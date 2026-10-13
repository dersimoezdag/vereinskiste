<?php
declare(strict_types=1);

namespace Vereinskiste\Foundation;

use PDO;
use Vereinskiste\Audit\AuditLog;
use Vereinskiste\Ledger\ChangeLog;

final class History
{
    public function __construct(private PDO $pdo) {}

    public function record(string $subject, int $id, array $before, array $after, int $actor, string $reason): void
    {
        if (!\Vereinskiste\Auth\UserRepository::isStorableText($reason)) {
            throw new \Vereinskiste\Ledger\ValidationError(['reason' => 'validation.text.encoding']);
        }
        // Stammdaten (Verein, Kontakte, Kontodaten, Mitglieder) brauchen keine Begruendung; nur Buchungen, Belege und Abschluss.
        if (trim($reason) === '' && in_array($subject, ['club', 'contact', 'account_bank', 'member', 'club_role'], true)) {
            $reason = '–';
        }
        $reason = ChangeLog::normalizeReason($reason);
        $changes = [];
        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changes[] = ['field' => $key, 'from' => $before[$key] ?? null, 'to' => $value];
            }
        }
        if ($changes === []) { return; }
        $this->pdo->prepare('INSERT INTO foundation_changes (subject, subject_id, actor_id, reason, changes)
            VALUES (?, ?, ?, ?, ?::jsonb)')->execute([$subject, $id, $actor, $reason,
                json_encode($changes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
        // Kein Kontakt-/Adress-/Bankdatensatz im allgemeinen Protokoll.
        (new AuditLog($this->pdo))->record('foundation_updated', $actor, ['subject' => $subject, 'id' => $id]);
    }

    public function for(string $subject, int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT h.*, u.name AS actor FROM foundation_changes h
            JOIN users u ON u.id = h.actor_id WHERE subject = ? AND subject_id = ? ORDER BY h.id DESC LIMIT 100');
        $stmt->execute([$subject, $id]);
        return array_map(static function (array $row): array {
            $row['changes'] = json_decode($row['changes'], true, 512, JSON_THROW_ON_ERROR);
            return $row;
        }, $stmt->fetchAll());
    }
}
