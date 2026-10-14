<?php
declare(strict_types=1);

namespace Vereinskiste\Members;

use PDO;
use PDOException;
use Vereinskiste\Audit\AuditLog;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

final class MemberUserLink
{
    public function __construct(private PDO $pdo) {}

    /** @throws ValidationError */
    public function link(int $memberId, int $userId, int $actor): void
    {
        Transaction::run($this->pdo, function () use ($memberId, $userId, $actor): void {
            $member = $this->pdo->prepare('SELECT 1 FROM members WHERE id = ? AND anonymized_at IS NULL FOR UPDATE');
            $member->execute([$memberId]);
            $user = $this->pdo->prepare('SELECT member_id FROM users WHERE id = ? FOR UPDATE');
            $user->execute([$userId]);
            $row = $user->fetch();
            if ($member->fetchColumn() === false || $row === false) {
                throw new ValidationError(['user' => 'members.user.unknown']);
            }
            if ($row['member_id'] !== null) {
                throw new ValidationError(['user' => 'members.user.user_taken']);
            }
            $taken = $this->pdo->prepare('SELECT 1 FROM users WHERE member_id = ?');
            $taken->execute([$memberId]);
            if ($taken->fetchColumn() !== false) {
                throw new ValidationError(['user' => 'members.user.member_taken']);
            }
            try {
                $this->pdo->prepare('UPDATE users SET member_id = ? WHERE id = ?')->execute([$memberId, $userId]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23514') { throw new ValidationError(['user'=>'validation.user.email.invalid']); }
                if ($e->getCode() === '23505' && str_contains($e->getMessage(),'users_email')) { throw new ValidationError(['user'=>'validation.user.email.duplicate']); }
                if ($e->getCode() === '23505') { // parallele Verknuepfung: UNIQUE users.member_id
                    throw new ValidationError(['user' => 'members.user.member_taken']);
                }
                throw $e;
            }
            (new AuditLog($this->pdo))->record('member_user_linked', $actor, ['member_id' => $memberId, 'user_id' => $userId]);
        });
    }

    public function unlink(int $memberId, int $actor): void
    {
        Transaction::run($this->pdo, function () use ($memberId, $actor): void {
            $stmt = $this->pdo->prepare('UPDATE users SET member_id = NULL WHERE member_id = ? RETURNING id');
            $stmt->execute([$memberId]);
            $userId = $stmt->fetchColumn();
            if ($userId !== false) {
                (new AuditLog($this->pdo))->record('member_user_unlinked', $actor, ['member_id' => $memberId, 'user_id' => (int) $userId]);
            }
        });
    }

    /** @return list<array{id:int,name:string,email:string}> */
    public function linkableUsers(): array
    {
        $rows = $this->pdo->query('SELECT id, name, email FROM users WHERE member_id IS NULL AND active ORDER BY lower(name)')->fetchAll();
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => $r['name'], 'email' => $r['email']], $rows);
    }

    /** @return list<array{id:int,member_no:int,name:string}> */
    public function linkableMembers(): array
    {
        $rows = $this->pdo->query("SELECT m.id, m.member_no, m.name, m.data->>'email' AS email FROM members m
            WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.member_id = m.id) ORDER BY lower(m.name)")->fetchAll();
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'member_no' => (int) $r['member_no'], 'name' => $r['name'], 'email'=>$r['email']], $rows);
    }
}
