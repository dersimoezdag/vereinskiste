<?php
declare(strict_types=1);

namespace Vereinskiste\Members;

use DateTimeImmutable;
use PDO;
use Vereinskiste\Foundation\History;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

final class MembershipPeriods
{
    public function __construct(private PDO $pdo) {}

    /** @throws ValidationError */
    public function join(int $memberId, string $date, int $actor): void
    {
        self::checkDate($date);
        Transaction::run($this->pdo, function () use ($memberId, $date, $actor): void {
            $this->lockMember($memberId);
            $open = $this->pdo->prepare('SELECT 1 FROM membership_periods WHERE member_id = ? AND end_date IS NULL');
            $open->execute([$memberId]);
            if ($open->fetchColumn() !== false) {
                throw new ValidationError(['date' => 'members.period.already_member']);
            }
            $last = $this->pdo->prepare('SELECT max(end_date) FROM membership_periods WHERE member_id = ?');
            $last->execute([$memberId]);
            $lastEnd = $last->fetchColumn();
            if (is_string($lastEnd) && $date <= $lastEnd) {
                throw new ValidationError(['date' => 'members.period.overlap']);
            }
            $this->pdo->prepare('INSERT INTO membership_periods (member_id, start_date) VALUES (?, ?)')
                ->execute([$memberId, $date]);
            (new History($this->pdo))->record('member', $memberId, ['membership_start' => null], ['membership_start' => $date], $actor, '');
            (new MembershipTypeSync($this->pdo))->onJoin($memberId, $date, $actor);
        });
    }

    /** @throws ValidationError */
    public function leave(int $memberId, string $date, string $reason, int $actor): void
    {
        self::checkDate($date);
        $reason = mb_substr(trim($reason), 0, 500);
        Transaction::run($this->pdo, function () use ($memberId, $date, $reason, $actor): void {
            $this->lockMember($memberId);
            $open = $this->pdo->prepare('SELECT id, start_date FROM membership_periods WHERE member_id = ? AND end_date IS NULL');
            $open->execute([$memberId]);
            $period = $open->fetch();
            if ($period === false) {
                throw new ValidationError(['date' => 'members.period.not_member']);
            }
            if ($date < $period['start_date']) {
                throw new ValidationError(['date' => 'members.period.before_start']);
            }
            $this->pdo->prepare('UPDATE membership_periods SET end_date = ?, end_reason = ? WHERE id = ?')
                ->execute([$date, $reason === '' ? null : $reason, $period['id']]);
            (new History($this->pdo))->record('member', $memberId, ['membership_end' => null], ['membership_end' => $date], $actor, $reason);
            (new MembershipTypeSync($this->pdo))->onLeave($memberId, $date, $actor);
        });
    }

    public function isMemberOn(int $memberId, string $date): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM membership_periods
            WHERE member_id = ? AND start_date <= ? AND (end_date IS NULL OR end_date >= ?)');
        $stmt->execute([$memberId, $date, $date]);
        return $stmt->fetchColumn() !== false;
    }

    private function lockMember(int $memberId): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM members WHERE id = ? AND anonymized_at IS NULL FOR UPDATE');
        $stmt->execute([$memberId]);
        if ($stmt->fetchColumn() === false) {
            throw new ValidationError(['date' => 'foundation.invalid']);
        }
    }

    private static function checkDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new ValidationError(['date' => 'foundation.invalid']);
        }
    }
}
