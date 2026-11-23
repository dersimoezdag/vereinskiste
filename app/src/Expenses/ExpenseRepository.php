<?php
declare(strict_types=1);

namespace Vereinskiste\Expenses;

use PDO;
use Vereinskiste\Finance\BankDetails;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Foundation\History;
use Vereinskiste\Ledger\BookingService;
use Vereinskiste\Ledger\DateInput;
use Vereinskiste\Ledger\InvalidAmount;
use Vereinskiste\Ledger\Money;
use Vereinskiste\Ledger\TextInput;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Members\SignatureImage;

/**
 * Auslagen von Personen fuer den Verein. Erstattet ist eine Auslage genau dann, wenn eine aktive
 * Buchung verknuepft ist: Wird die Buchung storniert, ist die Auslage automatisch wieder offen.
 */
final class ExpenseRepository
{
    private const SELECT = "SELECT x.id, x.member_id, x.person_name, COALESCE(m.name, x.person_name) AS person, x.purpose,
            x.amount::text AS amount, x.spent_on::text AS spent_on,
            EXISTS(SELECT 1 FROM expense_documents d WHERE d.expense_id = x.id) AS has_receipt,
            x.event_id, e.title AS event_title,
            x.booking_id, x.version, (b.id IS NOT NULL AND b.status = 'active') AS reimbursed,
            b.booking_date::text AS reimbursed_on, b.account_id AS booking_account_id,
            x.payout_method, x.payout_detail, x.payout_account_id, x.payout_at::text AS payout_at,
            x.payout_by, x.counter_signed_at::text AS counter_signed_at, x.counter_signed_by,
            pa.name AS payout_account_name
        FROM member_expenses x
        LEFT JOIN members m ON m.id = x.member_id
        LEFT JOIN events e ON e.id = x.event_id
        LEFT JOIN bookings b ON b.id = x.booking_id
        LEFT JOIN accounts pa ON pa.id = x.payout_account_id";
    private const STATE = ['open' => " WHERE (b.id IS NULL OR b.status <> 'active')", 'reimbursed' => " WHERE b.status = 'active'", 'all' => ''];

    public function __construct(private PDO $pdo) {}

    public function count(string $state): int
    {
        return (int) $this->pdo->query('SELECT count(*) FROM member_expenses x LEFT JOIN bookings b ON b.id = x.booking_id' . self::STATE[$state])->fetchColumn();
    }

    public function list(string $state, int $page = 1, int $perPage = 50): array
    {
        $stmt = $this->pdo->prepare(self::SELECT . self::STATE[$state] . ' ORDER BY x.spent_on DESC, x.id DESC LIMIT ' . $perPage . ' OFFSET ?');
        $stmt->execute([(max(1, min($page, 100000)) - 1) * $perPage]);
        return array_map(self::cast(...), $stmt->fetchAll());
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE x.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : self::cast($row);
    }

    public function forMember(int $memberId): array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE x.member_id = ? ORDER BY x.spent_on DESC, x.id DESC LIMIT 100');
        $stmt->execute([$memberId]);
        return array_map(self::cast(...), $stmt->fetchAll());
    }

    public function forBooking(int $bookingId): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE x.booking_id = ?');
        $stmt->execute([$bookingId]);
        $row = $stmt->fetch();
        return $row === false ? null : self::cast($row);
    }

    /** @return array{count:int,total:string} offene Auslagen */
    public function openSummary(): array
    {
        $row = $this->pdo->query("SELECT count(*) AS n, COALESCE(sum(x.amount), 0)::text AS total FROM member_expenses x
            LEFT JOIN bookings b ON b.id = x.booking_id WHERE b.id IS NULL OR b.status <> 'active'")->fetch();
        return ['count' => (int) $row['n'], 'total' => $row['total']];
    }

    /** Aktive Ausgaben mit gleichem Betrag, die noch keiner Auslage zugeordnet sind. */
    public function candidates(array $expense): array
    {
        $stmt = $this->pdo->prepare("SELECT b.id, b.booking_date::text AS booking_date, b.description, b.counterparty FROM bookings b
            WHERE b.status = 'active' AND b.direction = 'out' AND b.kind = 'regular' AND b.amount = ?::numeric
              AND NOT EXISTS (SELECT 1 FROM member_expenses x WHERE x.booking_id = b.id)
            ORDER BY b.booking_date DESC, b.id DESC LIMIT 50");
        $stmt->execute([$expense['amount']]);
        return $stmt->fetchAll();
    }

    public function save(?int $id, array $input, int $version, int $actor): int
    {
        $values = $this->validate($input);
        return Transaction::run($this->pdo, function () use ($id, $values, $version, $actor): int {
            $before = [];
            if ($id !== null) {
                $current = $this->lock($id);
                if ($current['version'] !== $version) { throw new Conflict(); }
                $before = array_intersect_key($current, $values);
                foreach (['member_id', 'event_id'] as $key) { $before[$key] = $before[$key] === null ? null : (int) $before[$key]; }
                $this->pdo->prepare('UPDATE member_expenses SET member_id = ?, person_name = ?, purpose = ?, amount = ?, spent_on = ?,
                    event_id = ?, version = version + 1, updated_at = now() WHERE id = ?')->execute([$values['member_id'], $values['person_name'], $values['purpose'],
                    $values['amount'], $values['spent_on'], $values['event_id'], $id]);
            } else {
                $stmt = $this->pdo->prepare('INSERT INTO member_expenses (member_id, person_name, purpose, amount, spent_on, event_id, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id');
                $stmt->execute([$values['member_id'], $values['person_name'], $values['purpose'], $values['amount'], $values['spent_on'],
                    $values['event_id'], $actor]);
                $id = (int) $stmt->fetchColumn();
            }
            (new History($this->pdo))->record('expense', $id, $before, $values, $actor, '');
            return $id;
        });
    }

    /** Legt die Erstattung als Ausgabe an und verknuepft sie in derselben Transaktion. */
    public function reimburse(int $id, array $input, int $actor): void
    {
        Transaction::run($this->pdo, function () use ($id, $input, $actor): void {
            $expense = $this->lockOpen($id);
            $booking = (new BookingService($this->pdo))->create([
                'account_id' => is_string($input['account_id'] ?? null) ? $input['account_id'] : '',
                'booking_date' => is_string($input['booking_date'] ?? null) ? $input['booking_date'] : '',
                'amount' => $expense['amount'], 'direction' => 'out',
                'category_id' => is_string($input['category_id'] ?? null) ? $input['category_id'] : '',
                'description' => mb_substr('Erstattung Auslage: ' . $expense['purpose'], 0, BookingService::DESCRIPTION_MAX),
                'counterparty' => mb_substr((string) $expense['person'], 0, BookingService::COUNTERPARTY_MAX),
                'event_id' => $expense['event_id'] === null ? '' : (string) $expense['event_id'],
            ], $actor);
            $this->attach($id, $booking['id']);
        });
    }

    /** Ordnet eine vorhandene Ausgabe zu, etwa aus dem Buchungsimport. */
    public function link(int $id, string $bookingId): void
    {
        Transaction::run($this->pdo, function () use ($id, $bookingId): void {
            $expense = $this->lockOpen($id);
            foreach ($this->candidates($expense) as $candidate) {
                if ((string) $candidate['id'] === $bookingId) {
                    $this->attach($id, (int) $bookingId);
                    return;
                }
            }
            throw new ValidationError(['booking_id' => 'expenses.booking_invalid']);
        });
    }

    /** Hebt nur die Zuordnung auf, die Buchung bleibt bestehen. */
    public function unlink(int $id): void
    {
        $this->pdo->prepare('UPDATE member_expenses SET booking_id = NULL, version = version + 1, updated_at = now() WHERE id = ?')->execute([$id]);
    }

    /**
     * Markiert eine erstattete Auslage als ausbezahlt. Auszahlungsweg paypal/bank/cash,
     * Quelle ist ein aktives Konto (Vorschlag: Konto der Erstattungsbuchung).
     * Bei aktivierter Gegenzeichnung und verknuepftem Empfaenger-Login bleibt die
     * Gegenzeichnung offen (counter_signed_at NULL), sonst ist die Auszahlung final.
     */
    public function markPaidOut(int $id, array $input, int $actor, bool $counterSignatureRequired): void
    {
        Transaction::run($this->pdo, function () use ($id, $input, $actor, $counterSignatureRequired): void {
            $expense = $this->lock($id);
            if (!$expense['reimbursed']) { throw new ValidationError(['payout' => 'expenses.payout_requires_booking']); }
            if ($expense['paid']) { throw new ValidationError(['payout' => 'expenses.payout_already']); }
            $method = is_string($input['payout_method'] ?? null) ? $input['payout_method'] : '';
            if (!in_array($method, ['paypal', 'bank', 'cash'], true)) { throw new ValidationError(['payout_method' => 'foundation.invalid']); }
            $detail = trim(is_string($input['payout_detail'] ?? null) ? $input['payout_detail'] : '');
            if ($method === 'paypal') {
                if (mb_strlen($detail) > 200 || !filter_var($detail, FILTER_VALIDATE_EMAIL)) {
                    throw new ValidationError(['payout_detail' => 'validation.email.invalid']);
                }
            } elseif ($method === 'bank') {
                try {
                    $bank = BankDetails::read($detail, trim(is_string($input['payout_bic'] ?? null) ? $input['payout_bic'] : ''));
                } catch (ValidationError) {
                    throw new ValidationError(['payout_detail' => 'foundation.iban.invalid']);
                }
                $detail = $bank['iban'] . ($bank['bic'] !== '' ? ' ' . $bank['bic'] : '');
            } else {
                $detail = '';
            }
            $accountId = Fields::version(trim(is_string($input['payout_account_id'] ?? null) ? $input['payout_account_id'] : ''));
            $stmt = $this->pdo->prepare('SELECT 1 FROM accounts WHERE id = ? AND active');
            $stmt->execute([$accountId]);
            if ($stmt->fetchColumn() === false) { throw new ValidationError(['payout_account_id' => 'foundation.invalid']); }
            $this->pdo->prepare('UPDATE member_expenses SET payout_method = ?, payout_detail = ?, payout_account_id = ?,
                payout_at = now(), payout_by = ?, version = version + 1, updated_at = now() WHERE id = ?')
                ->execute([$method, $detail, $accountId, $actor, $id]);
            (new History($this->pdo))->record('expense', $id,
                ['payout_method' => null, 'payout_detail' => '', 'payout_account_id' => null],
                ['payout_method' => $method, 'payout_detail' => $detail, 'payout_account_id' => $accountId], $actor,
                $counterSignatureRequired && $this->recipientUserId($expense) !== null ? 'payout pending counter-signature' : 'payout marked');
        });
    }

    /** Gegenzeichnung des Empfaengers (verknuepftes Mitglied) mit Unterschrift und Ort. */
    public function counterSign(int $id, string $dataUrl, string $place, int $actor): void
    {
        Transaction::run($this->pdo, function () use ($id, $dataUrl, $place, $actor): void {
            $expense = $this->lock($id);
            if (!$expense['paid']) { throw new ValidationError(['payout' => 'expenses.payout_not_marked']); }
            if ($expense['counter_signed']) { throw new ValidationError(['payout' => 'expenses.payout_already_signed']); }
            if ($this->recipientUserId($expense) !== $actor) { throw new ValidationError(['payout' => 'expenses.payout_not_recipient']); }
            $png = SignatureImage::fromDataUrl($dataUrl);
            $place = mb_substr(trim($place), 0, 100);
            if (TextInput::isBlank($place)) { throw new ValidationError(['place' => 'foundation.required']); }
            $stmt = $this->pdo->prepare('INSERT INTO expense_payout_signatures (expense_id, signature_png, signature_sha256, signed_place)
                VALUES (?, ?, ?, ?)');
            $stmt->bindValue(1, $id, \PDO::PARAM_INT);
            $stmt->bindValue(2, $png, \PDO::PARAM_LOB);
            $stmt->bindValue(3, hash('sha256', $png));
            $stmt->bindValue(4, $place);
            $stmt->execute();
            $this->pdo->prepare('UPDATE member_expenses SET counter_signed_at = now(), counter_signed_by = ?,
                version = version + 1, updated_at = now() WHERE id = ?')->execute([$actor, $id]);
            (new History($this->pdo))->record('expense', $id, ['counter_signed_at' => null],
                ['counter_signed_at' => 'now', 'signed_place' => $place], $actor, 'payout counter-signed');
        });
    }

    /** @return array{signature_png:string,signature_sha256:string,signed_place:string,signed_at:string}|null */
    public function payoutSignature(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT signature_png, signature_sha256, signed_place, signed_at::text AS signed_at
            FROM expense_payout_signatures WHERE expense_id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) { return null; }
        if (is_resource($row['signature_png'])) { $row['signature_png'] = (string) stream_get_contents($row['signature_png']); }
        return $row;
    }

    /** user_id des mit der Auslage verknuepften Mitglieds, falls vorhanden. */
    private function recipientUserId(array $expense): ?int
    {
        if ($expense['member_id'] === null) { return null; }
        $stmt = $this->pdo->prepare('SELECT u.id AS user_id FROM users u WHERE u.member_id = ?');
        $stmt->execute([$expense['member_id']]);
        $userId = $stmt->fetchColumn();
        return $userId === false || $userId === null ? null : (int) $userId;
    }

    public function delete(int $id): void
    {
        Transaction::run($this->pdo, function () use ($id): void {
            if ($this->lock($id)['booking_id'] !== null) { throw new ValidationError(['delete' => 'expenses.delete_blocked']); }
            $this->pdo->prepare('DELETE FROM member_expenses WHERE id = ?')->execute([$id]);
        });
    }

    private function attach(int $id, int $bookingId): void
    {
        $this->pdo->prepare('UPDATE member_expenses SET booking_id = ?, version = version + 1, updated_at = now() WHERE id = ?')->execute([$bookingId, $id]);
    }

    private function lockOpen(int $id): array
    {
        $expense = $this->lock($id);
        if ($expense['reimbursed']) { throw new ValidationError(['booking_id' => 'expenses.already_reimbursed']); }
        return $expense;
    }

    private function lock(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM member_expenses WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $expense = $stmt->fetchColumn() === false ? null : $this->find($id);
        if ($expense === null) { throw new Conflict(); }
        return $expense;
    }

    /** @return array<string,mixed> */
    private function validate(array $input): array
    {
        $errors = [];
        $data = ['purpose' => '', 'person_name' => ''];
        try {
            $data = Fields::read($input, ['purpose' => 200, 'person_name' => 200], ['purpose']);
        } catch (ValidationError $e) {
            $errors = $e->errors;
        }
        $text = static fn (string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        $member = $text('member_id') === '' ? null : Fields::version($text('member_id'));
        if ($member !== null) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM members WHERE id = ? AND anonymized_at IS NULL');
            $stmt->execute([$member]);
            if ($stmt->fetchColumn() === false) { $errors['member_id'] = 'foundation.invalid'; }
        } elseif ($data['person_name'] === '') {
            $errors['member_id'] = 'expenses.person_required';
        }
        $amount = null;
        try {
            $amount = Money::parse($text('amount'));
        } catch (InvalidAmount) {
            $errors['amount'] = 'validation.booking.amount';
        }
        if (!DateInput::valid($text('spent_on'))) { $errors['spent_on'] = 'validation.date.invalid'; }
        $event = $text('event_id') === '' ? null : Fields::version($text('event_id'));
        if ($event !== null) {
            $stmt = $this->pdo->prepare("SELECT 1 FROM events WHERE id = ? AND kind = 'event'");
            $stmt->execute([$event]);
            if ($stmt->fetchColumn() === false) { $errors['event_id'] = 'foundation.invalid'; }
        }
        if ($errors !== []) { throw new ValidationError($errors); }
        return ['member_id' => $member, 'person_name' => $member === null ? $data['person_name'] : '', 'purpose' => $data['purpose'],
            'amount' => $amount, 'spent_on' => $text('spent_on'), 'event_id' => $event];
    }

    private static function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['version'] = (int) $row['version'];
        foreach (['reimbursed', 'has_receipt'] as $key) {
            $row[$key] = in_array($row[$key], [true, 't', 'true', 1, '1'], true);
        }
        $row['paid'] = ($row['payout_method'] ?? null) !== null && $row['payout_method'] !== '';
        $row['counter_signed'] = ($row['counter_signed_at'] ?? null) !== null;
        return $row;
    }
}
