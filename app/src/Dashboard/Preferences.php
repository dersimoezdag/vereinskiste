<?php
declare(strict_types=1);
namespace Vereinskiste\Dashboard;

use PDO;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

final class Preferences
{
    public function __construct(private PDO $pdo) {}

    public function get(int $userId, array $cards, array $shortcuts): array
    {
        $stmt = $this->pdo->prepare('SELECT cards, shortcuts, version FROM user_dashboard_preferences WHERE user_id=?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) { return ['cards' => array_keys($cards), 'shortcuts' => array_slice(array_keys($shortcuts), 0, 4), 'version' => 0]; }
        return [
            'cards' => array_values(array_intersect(json_decode($row['cards'], true, 512, JSON_THROW_ON_ERROR), array_keys($cards))),
            'shortcuts' => array_values(array_intersect(json_decode($row['shortcuts'], true, 512, JSON_THROW_ON_ERROR), array_keys($shortcuts))),
            'version' => (int) $row['version'],
        ];
    }

    public function save(int $userId, int $version, array $input, array $cards, array $shortcuts): int
    {
        $values = [];
        foreach (['cards' => $cards, 'shortcuts' => $shortcuts] as $key => $allowed) {
            $list = $input[$key] ?? [];
            if (!is_array($list) || !array_is_list($list) || count($list) > count($allowed)) {
                throw new ValidationError(['dashboard' => 'foundation.invalid']);
            }
            $seen = [];
            foreach ($list as $id) {
                if (!is_string($id) || !isset($allowed[$id]) || isset($seen[$id])) {
                    throw new ValidationError(['dashboard' => 'foundation.invalid']);
                }
                $seen[$id] = true;
            }
            $positions = $input[$key . '_positions'] ?? [];
            if (is_array($positions)) {
                // Stabil bei gleichen Positionen; ohne JavaScript direkt editierbar.
                usort($list, static fn(string $a, string $b): int => self::position($positions[$a] ?? null) <=> self::position($positions[$b] ?? null));
            }
            $values[$key] = json_encode($list, JSON_THROW_ON_ERROR);
        }
        return Transaction::run($this->pdo, function () use ($userId, $version, $values): int {
            // Auch zwei gleichzeitige erste Speicherungen werden serialisiert.
            $lock = $this->pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
            $lock->execute([$userId]);
            $stmt = $this->pdo->prepare('SELECT version FROM user_dashboard_preferences WHERE user_id=?');
            $stmt->execute([$userId]);
            $current = (int) ($stmt->fetchColumn() ?: 0);
            if ($current !== $version) { throw new Conflict(); }
            $next = $current + 1;
            $this->pdo->prepare('INSERT INTO user_dashboard_preferences(user_id,cards,shortcuts,version) VALUES (?,?::jsonb,?::jsonb,?)
                ON CONFLICT(user_id) DO UPDATE SET cards=EXCLUDED.cards,shortcuts=EXCLUDED.shortcuts,version=EXCLUDED.version')
                ->execute([$userId, $values['cards'], $values['shortcuts'], $next]);
            return $next;
        });
    }

    private static function position(mixed $value): int
    {
        return is_scalar($value) && preg_match('/^[0-9]{1,3}$/D', (string) $value) ? (int) $value : 999;
    }
}
