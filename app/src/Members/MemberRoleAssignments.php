<?php
declare(strict_types=1);

namespace Vereinskiste\Members;

use DateTimeImmutable;
use PDO;
use PDOException;
use Vereinskiste\Auth\UserRepository;
use Vereinskiste\Foundation\History;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

final class MemberRoleAssignments
{
    public function __construct(private PDO $pdo) {}

    public function forMember(int $memberId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.role_id, r.name AS role_name, r.active AS role_active, r.kind AS role_kind, a.scope, a.start_date, a.end_date
               FROM member_club_roles a JOIN club_roles r ON r.id = a.role_id
              WHERE a.member_id = ? ORDER BY (a.end_date IS NOT NULL), lower(r.name), a.id'
        );
        $stmt->execute([$memberId]);
        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['role_id'] = (int) $row['role_id'];
            $row['role_active'] = (bool) $row['role_active'];
            return $row;
        }, $stmt->fetchAll());
    }

    public function holders(int $roleId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.id AS member_id, m.member_no, m.name, a.scope
               FROM member_club_roles a JOIN members m ON m.id = a.member_id
              WHERE a.role_id = ? AND a.end_date IS NULL ORDER BY lower(m.name), lower(coalesce(a.scope, \'\')), a.id'
        );
        $stmt->execute([$roleId]);
        return array_map(static fn (array $r): array => ['member_id' => (int) $r['member_id'],
            'member_no' => (int) $r['member_no'], 'name' => $r['name'], 'scope' => (string) $r['scope']], $stmt->fetchAll());
    }

    /** @throws ValidationError */
    public function assign(int $memberId, int $roleId, string $scope, ?string $start, int $actor): int
    {
        if (!UserRepository::isStorableText($scope) || mb_strlen(trim($scope)) > 200) {
            throw new ValidationError(['role' => 'foundation.invalid']);
        }
        $scope = trim($scope);
        $start = $start === '' ? null : $start;
        if ($start !== null) {
            self::checkDate($start);
        }
        return Transaction::run($this->pdo, function () use ($memberId, $roleId, $scope, $start, $actor): int {
            $member = $this->pdo->prepare('SELECT 1 FROM members WHERE id = ? AND anonymized_at IS NULL FOR UPDATE');
            $member->execute([$memberId]);
            $role = $this->pdo->prepare('SELECT name, active, kind, single_holder FROM club_roles WHERE id = ? FOR UPDATE');
            $role->execute([$roleId]);
            $roleRow = $role->fetch();
            if ($member->fetchColumn() === false || $roleRow === false) {
                throw new ValidationError(['role' => 'foundation.invalid']);
            }
            if (!$roleRow['active']) {
                throw new ValidationError(['role' => 'clubroles.assign.archived']);
            }
            if ($roleRow['single_holder']) {
                $taken = $this->pdo->prepare('SELECT 1 FROM member_club_roles WHERE role_id = ? AND end_date IS NULL');
                $taken->execute([$roleId]);
                if ($taken->fetchColumn() !== false) {
                    throw new ValidationError(['role' => 'clubroles.assign.single_taken']);
                }
            }
            if ($roleRow['kind'] === 'membership_type') {
                $type = $this->pdo->prepare("SELECT 1 FROM member_club_roles a JOIN club_roles r ON r.id = a.role_id
                    WHERE a.member_id = ? AND a.end_date IS NULL AND r.kind = 'membership_type'");
                $type->execute([$memberId]);
                if ($type->fetchColumn() !== false) {
                    throw new ValidationError(['role' => 'clubroles.assign.type_exists']);
                }
            }
            try {
                $stmt = $this->pdo->prepare('INSERT INTO member_club_roles (member_id, role_id, scope, start_date) VALUES (?, ?, ?, ?) RETURNING id');
                $stmt->execute([$memberId, $roleId, $scope === '' ? null : $scope, $start]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23505') {
                    throw new ValidationError(['role' => 'clubroles.assign.duplicate']);
                }
                throw $e;
            }
            $label = $roleRow['name'] . ($scope === '' ? '' : ' – ' . $scope);
            (new History($this->pdo))->record('member', $memberId, ['club_role' => null], ['club_role' => $label], $actor, '');
            return (int) $stmt->fetchColumn();
        });
    }

    /** @throws ValidationError */
    public function end(int $assignmentId, string $date, int $actor): void
    {
        self::checkDate($date);
        Transaction::run($this->pdo, function () use ($assignmentId, $date, $actor): void {
            $stmt = $this->pdo->prepare('SELECT a.member_id, a.scope, a.start_date, r.name FROM member_club_roles a
                JOIN club_roles r ON r.id = a.role_id WHERE a.id = ? AND a.end_date IS NULL FOR UPDATE OF a');
            $stmt->execute([$assignmentId]);
            $row = $stmt->fetch();
            if ($row === false || ($row['start_date'] !== null && $date < $row['start_date'])) {
                throw new ValidationError(['role' => 'foundation.invalid']);
            }
            $this->pdo->prepare('UPDATE member_club_roles SET end_date = ? WHERE id = ?')->execute([$date, $assignmentId]);
            $label = $row['name'] . ($row['scope'] === null ? '' : ' – ' . $row['scope']);
            (new History($this->pdo))->record('member', (int) $row['member_id'], ['club_role' => $label], ['club_role' => null], $actor, '');
        });
    }

    /**
     * Beendet alle offenen Mitgliedsarten des Mitglieds (optional nur eine Rolle) zum Ende-Datum, nie vor dem
     * eigenen Beginn. Fuer die automatische Mitgliedsart bei Ein-/Austritt.
     *
     * @return list<int> IDs der Rollen, deren Zuweisung beendet wurde
     */
    public function endOpenMembershipTypes(int $memberId, string $endDate, int $actor, ?int $onlyRoleId = null): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.role_id, a.scope, a.start_date, r.name FROM member_club_roles a
               JOIN club_roles r ON r.id = a.role_id
              WHERE a.member_id = ? AND a.end_date IS NULL AND r.kind = 'membership_type'
                AND (?::bigint IS NULL OR a.role_id = ?::bigint) FOR UPDATE OF a"
        );
        $stmt->execute([$memberId, $onlyRoleId, $onlyRoleId]);
        $ended = [];
        foreach ($stmt->fetchAll() as $row) {
            $end = $row['start_date'] !== null && $row['start_date'] > $endDate ? $row['start_date'] : $endDate;
            $this->pdo->prepare('UPDATE member_club_roles SET end_date = ? WHERE id = ?')->execute([$end, $row['id']]);
            (new History($this->pdo))->record('member', $memberId, ['club_role' => $row['name']], ['club_role' => null], $actor, '');
            $ended[] = (int) $row['role_id'];
        }
        return $ended;
    }

    /** Sucht eine aktive Vereinsrolle ueber ihren festen Schluessel (`active_member`, `former_member`, …). */
    public function roleIdByKey(string $key): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM club_roles WHERE system_key = ? AND active');
        $stmt->execute([$key]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private static function checkDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new ValidationError(['role' => 'foundation.invalid']);
        }
    }
}
