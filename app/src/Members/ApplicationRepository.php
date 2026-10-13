<?php
declare(strict_types=1);

namespace Vereinskiste\Members;

use DateTimeImmutable;
use PDO;
use Vereinskiste\Audit\AuditLog;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Ledger\TextInput;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

final class ApplicationRepository
{
    public const FIELDS = [
        'applicant_name' => 200,
        'email' => 254,
        'address' => 200,
        'zip' => 20,
        'city' => 100,
        'country' => 100,
        'phone' => 50,
        'birth_date' => 10,
        'notes' => 1000,
        'account_holder' => 200,
        'iban' => 34,
        'bic' => 11,
    ];

    public function __construct(private PDO $pdo) {}

    /**
     * Erstellt einen neuen Einladungs-Link (Einmal-Link).
     *
     * @return array{id:int,token:string,title:string,require_sepa:bool,expires_at:string,has_password:bool}
     */
    public function createInvite(int $createdBy, string $title = '', int $validDays = 14, ?string $password = null, bool $requireSepa = false): array
    {
        $title = mb_substr(trim($title), 0, 200);
        $validDays = max(1, min($validDays, 365));
        $token = bin2hex(random_bytes(24));
        $expiresAt = (new DateTimeImmutable())->modify("+{$validDays} days")->format('Y-m-d H:i:sP');
        $pwdHash = $password !== null && trim($password) !== '' ? password_hash(trim($password), PASSWORD_DEFAULT) : null;

        $stmt = $this->pdo->prepare(
            'INSERT INTO membership_application_invites (token, title, require_sepa, password_hash, expires_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?) RETURNING id'
        );
        $stmt->execute([$token, $title, $requireSepa ? 'true' : 'false', $pwdHash, $expiresAt, $createdBy]);
        $id = (int) $stmt->fetchColumn();

        return [
            'id' => $id,
            'token' => $token,
            'title' => $title,
            'require_sepa' => $requireSepa,
            'expires_at' => $expiresAt,
            'has_password' => $pwdHash !== null,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listInvites(): array
    {
        $stmt = $this->pdo->query(
            'SELECT i.*, u.name AS creator_name
               FROM membership_application_invites i
               JOIN users u ON u.id = i.created_by
              ORDER BY i.created_at DESC'
        );
        return array_map(self::hydrateInvite(...), $stmt->fetchAll());
    }

    public function findInviteByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM membership_application_invites WHERE token = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        return self::hydrateInvite($row);
    }

    public function verifyInvitePassword(string $token, string $password): bool
    {
        $stmt = $this->pdo->prepare('SELECT password_hash FROM membership_application_invites WHERE token = ?');
        $stmt->execute([$token]);
        $hash = $stmt->fetchColumn();
        if ($hash === false || $hash === null) {
            return true;
        }
        return password_verify(trim($password), (string) $hash);
    }

    /**
     * Reicht einen Mitgliedsantrag über den Einladungs-Link ein.
     *
     * @param array<string,mixed> $input
     * @throws ValidationError
     */
    public function submit(string $token, array $input): int
    {
        return Transaction::run($this->pdo, function () use ($token, $input): int {
            $stmt = $this->pdo->prepare('SELECT * FROM membership_application_invites WHERE token = ? FOR UPDATE');
            $stmt->execute([$token]);
            $row = $stmt->fetch();
            if ($row === false) {
                throw new ValidationError(['token' => 'applications.link_invalid']);
            }
            $invite = self::hydrateInvite($row);
            if ($invite['is_expired']) {
                throw new ValidationError(['token' => 'applications.link_expired']);
            }
            if ($invite['is_used']) {
                throw new ValidationError(['token' => 'applications.link_used']);
            }

            // Validierung
            $errors = [];
            $names = ['salutation' => '', 'title' => '', 'first_name' => '', 'last_name' => ''];
            try {
                $names = PersonName::read($input);
            } catch (ValidationError $e) {
                $errors += $e->errors;
            }
            $name = PersonName::display($names);

            $email = trim((string) ($input['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'validation.email.invalid';
            }

            $consentStatute = !empty($input['consent_statute']);
            if (!$consentStatute) {
                $errors['consent_statute'] = 'foundation.required';
            }

            $consentPrivacy = !empty($input['consent_privacy']);
            if (!$consentPrivacy) {
                $errors['consent_privacy'] = 'foundation.required';
            }

            $birthDate = trim((string) ($input['birth_date'] ?? ''));
            if ($birthDate !== '') {
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
                if ($parsed === false || $parsed->format('Y-m-d') !== $birthDate || $parsed > new DateTimeImmutable('today')) {
                    $errors['birth_date'] = 'foundation.invalid';
                }
            }

            $address = mb_substr(trim((string) ($input['address'] ?? '')), 0, 200);
            $zip = mb_substr(trim((string) ($input['zip'] ?? '')), 0, 20);
            $city = mb_substr(trim((string) ($input['city'] ?? '')), 0, 100);
            $country = mb_substr(trim((string) ($input['country'] ?? 'Deutschland')), 0, 100);
            $phone = mb_substr(trim((string) ($input['phone'] ?? '')), 0, 50);
            $notes = mb_substr(trim((string) ($input['notes'] ?? '')), 0, 1000);

            $accountHolder = '';
            $iban = '';
            $bic = '';
            $sepaConsent = false;

            if ($invite['require_sepa']) {
                $accountHolder = trim((string) ($input['account_holder'] ?? ''));
                if (TextInput::isBlank($accountHolder)) {
                    $errors['account_holder'] = 'foundation.required';
                }
                $iban = strtoupper(str_replace(' ', '', (string) ($input['iban'] ?? '')));
                if (strlen($iban) < 15 || strlen($iban) > 34) {
                    $errors['iban'] = 'validation.iban.invalid';
                }
                $bic = strtoupper(str_replace(' ', '', (string) ($input['bic'] ?? '')));
                $sepaConsent = !empty($input['sepa_mandate_consent']);
                if (!$sepaConsent) {
                    $errors['sepa_mandate_consent'] = 'foundation.required';
                }
            }

            if ($errors !== []) {
                throw new ValidationError($errors);
            }

            $data = $names + [
                'email' => $email,
                'address' => $address,
                'zip' => $zip,
                'city' => $city,
                'country' => $country,
                'phone' => $phone,
                'birth_date' => $birthDate,
                'notes' => $notes,
                'consent_statute' => $consentStatute,
                'consent_privacy' => $consentPrivacy,
                'account_holder' => $accountHolder,
                'iban' => $iban,
                'bic' => $bic,
                'sepa_mandate_consent' => $sepaConsent,
            ];

            // Antrag speichern
            $insert = $this->pdo->prepare(
                'INSERT INTO membership_applications (invite_id, applicant_name, email, data)
                 VALUES (?, ?, ?, ?::jsonb) RETURNING id'
            );
            $insert->execute([$invite['id'], $name, $email, json_encode($data, JSON_THROW_ON_ERROR)]);
            $appId = (int) $insert->fetchColumn();

            // Einladungs-Link als verwendet markieren
            $this->pdo->prepare('UPDATE membership_application_invites SET used_count = used_count + 1 WHERE id = ?')
                ->execute([$invite['id']]);

            return $appId;
        });
    }

    /** @return list<array<string,mixed>> */
    public function listApplications(string $filter = 'all'): array
    {
        $filter = in_array($filter, ['pending', 'approved', 'rejected', 'all'], true) ? $filter : 'all';
        $where = $filter === 'all' ? '' : "WHERE a.status = '$filter'";

        $stmt = $this->pdo->query(
            "SELECT a.*, i.title AS invite_title, i.require_sepa, u.name AS reviewer_name, m.member_no
               FROM membership_applications a
               LEFT JOIN membership_application_invites i ON i.id = a.invite_id
               LEFT JOIN users u ON u.id = a.reviewed_by
               LEFT JOIN members m ON m.id = a.member_id
               $where
              ORDER BY (a.status = 'pending') DESC, a.created_at DESC"
        );
        return array_map(self::hydrateApplication(...), $stmt->fetchAll());
    }

    public function countPending(): int
    {
        return (int) $this->pdo->query("SELECT count(*) FROM membership_applications WHERE status = 'pending'")->fetchColumn();
    }

    public function findApplication(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, i.title AS invite_title, i.require_sepa, u.name AS reviewer_name, m.member_no
               FROM membership_applications a
               LEFT JOIN membership_application_invites i ON i.id = a.invite_id
               LEFT JOIN users u ON u.id = a.reviewed_by
               LEFT JOIN members m ON m.id = a.member_id
              WHERE a.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : self::hydrateApplication($row);
    }

    /**
     * Nimmt einen Antrag an: legt das Mitglied an, startet die Mitgliedschaft ab heute, weist die aktive Mitgliedsart zu.
     */
    public function approve(int $id, int $actor): int
    {
        return Transaction::run($this->pdo, function () use ($id, $actor): int {
            $stmt = $this->pdo->prepare('SELECT * FROM membership_applications WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row === false || $row['status'] !== 'pending') {
                throw new ValidationError(['application' => 'foundation.invalid']);
            }
            $app = self::hydrateApplication($row);
            $data = $app['data'];

            // Mitglied anlegen
            $memberRepo = new MemberRepository($this->pdo);
            $names = array_intersect_key($data, PersonName::LIMITS);
            if (!isset($names['last_name']) || $names['last_name'] === '') {
                $names = ['name' => $app['applicant_name']]; // aeltere Antraege ohne getrennte Namensfelder
            }
            $memberInput = $names + [
                'email' => $app['email'],
                'address' => $data['address'] ?? '',
                'zip' => $data['zip'] ?? '',
                'city' => $data['city'] ?? '',
                'country' => $data['country'] ?? '',
                'phone' => $data['phone'] ?? '',
                'birth_date' => $data['birth_date'] ?? '',
                'notes' => $data['notes'] ?? '',
            ];
            $memberId = $memberRepo->save(null, $memberInput, 0, $actor, 'Online-Mitgliedsantrag #' . $id);

            // Eintritt per heute starten (dies setzt via MembershipTypeSync automatisch 'active_member')
            $periods = new MembershipPeriods($this->pdo);
            $today = (new DateTimeImmutable())->format('Y-m-d');
            $periods->join($memberId, $today, $actor);

            // Antrag aktualisieren
            $this->pdo->prepare(
                "UPDATE membership_applications
                    SET status = 'approved', member_id = ?, reviewed_by = ?, reviewed_at = now()
                  WHERE id = ?"
            )->execute([$memberId, $actor, $id]);

            (new AuditLog($this->pdo))->record('membership_application_approved', $actor, [
                'application_id' => $id,
                'member_id' => $memberId,
            ]);

            return $memberId;
        });
    }

    /**
     * Lehnt einen Antrag ab mit optionaler Begründung.
     */
    public function reject(int $id, string $reason, int $actor): void
    {
        Transaction::run($this->pdo, function () use ($id, $reason, $actor): void {
            $stmt = $this->pdo->prepare('SELECT status FROM membership_applications WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $status = $stmt->fetchColumn();
            if ($status !== 'pending') {
                throw new ValidationError(['application' => 'foundation.invalid']);
            }
            $reason = mb_substr(trim($reason), 0, 500);
            $this->pdo->prepare(
                "UPDATE membership_applications
                    SET status = 'rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = now()
                  WHERE id = ?"
            )->execute([$reason === '' ? null : $reason, $actor, $id]);

            (new AuditLog($this->pdo))->record('membership_application_rejected', $actor, [
                'application_id' => $id,
            ]);
        });
    }

    private static function hydrateInvite(array $row): array
    {
        $expiresAt = new DateTimeImmutable($row['expires_at']);
        $now = new DateTimeImmutable();
        $isExpired = $expiresAt < $now;
        $isUsed = (int) $row['used_count'] >= (int) $row['max_uses'];

        return [
            'id' => (int) $row['id'],
            'token' => $row['token'],
            'title' => (string) $row['title'],
            'require_sepa' => (bool) $row['require_sepa'],
            'has_password' => $row['password_hash'] !== null,
            'expires_at' => $row['expires_at'],
            'max_uses' => (int) $row['max_uses'],
            'used_count' => (int) $row['used_count'],
            'created_by' => (int) $row['created_by'],
            'creator_name' => $row['creator_name'] ?? null,
            'created_at' => $row['created_at'],
            'is_expired' => $isExpired,
            'is_used' => $isUsed,
            'is_valid' => !$isExpired && !$isUsed,
        ];
    }

    private static function hydrateApplication(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'invite_id' => $row['invite_id'] === null ? null : (int) $row['invite_id'],
            'status' => $row['status'],
            'applicant_name' => $row['applicant_name'],
            'email' => $row['email'],
            'data' => json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR),
            'member_id' => $row['member_id'] === null ? null : (int) $row['member_id'],
            'member_no' => isset($row['member_no']) && $row['member_no'] !== null ? (int) $row['member_no'] : null,
            'rejection_reason' => $row['rejection_reason'],
            'reviewed_by' => $row['reviewed_by'] === null ? null : (int) $row['reviewed_by'],
            'reviewer_name' => $row['reviewer_name'] ?? null,
            'reviewed_at' => $row['reviewed_at'],
            'created_at' => $row['created_at'],
            'invite_title' => $row['invite_title'] ?? null,
            'require_sepa' => isset($row['require_sepa']) ? (bool) $row['require_sepa'] : false,
        ];
    }
}
