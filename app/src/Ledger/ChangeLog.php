<?php
declare(strict_types=1);

namespace Vereinskiste\Ledger;

use InvalidArgumentException;
use PDO;

/**
 * Aenderungsverlauf je Buchung (Tabelle booking_changes, per Trigger unveraenderlich).
 * `record` oeffnet keine eigene Transaktion: der Aufrufer schreibt Aenderung und Eintrag gemeinsam.
 */
final class ChangeLog
{
    public const REASON_MAX = 500;

    /** Feldname eines Anlage-Eintrags (z. B. Korrekturbuchung); zaehlt nicht als "geaendert". */
    public const CREATED = 'created';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Pflichtgrund pruefen und normalisieren (getrimmt).
     *
     * @throws ValidationError ['reason' => Textschluessel]
     */
    public static function normalizeReason(string $reason): string
    {
        $error = self::reasonError($reason);
        if ($error !== null) {
            throw new ValidationError(['reason' => $error]);
        }
        return trim($reason);
    }

    /** @return string|null Textschluessel des Fehlers oder null, wenn der Grund passt */
    public static function reasonError(string $reason): ?string
    {
        $reason = trim($reason);
        if ($reason === '') {
            return 'validation.reason.required';
        }
        if (!mb_check_encoding($reason, 'UTF-8')) {
            return 'validation.text.encoding';
        }
        if (mb_strlen($reason) > self::REASON_MAX) {
            return 'validation.reason.too_long';
        }
        return null;
    }

    /**
     * @param list<array{field:string,from:mixed,to:mixed}> $changes
     * @throws ValidationError bei leerem Grund
     */
    public function record(int $bookingId, int $userId, array $changes, string $reason): void
    {
        $reason = self::normalizeReason($reason);
        if ($changes === []) {
            throw new InvalidArgumentException('Ein Verlaufseintrag braucht mindestens eine Aenderung.');
        }
        $entries = [];
        foreach ($changes as $change) {
            if (!is_string($change['field'] ?? null) || !array_key_exists('from', $change) || !array_key_exists('to', $change)) {
                throw new InvalidArgumentException('Aenderung braucht field, from und to.');
            }
            $entries[] = ['field' => $change['field'], 'from' => $change['from'], 'to' => $change['to']];
        }
        $this->pdo->prepare(
            'INSERT INTO booking_changes (booking_id, changed_by, changes, reason) VALUES (?, ?, ?::jsonb, ?)'
        )->execute([
            $bookingId, $userId, json_encode($entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $reason,
        ]);
    }

    /**
     * Verlauf in zeitlicher Reihenfolge (aelteste zuerst).
     *
     * @return list<array{id:int,changed_by:int,changed_by_name:string,changed_at:string,
     *                    changes:list<array{field:string,from:mixed,to:mixed}>,reason:string}>
     */
    public function forBooking(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT bc.id, bc.changed_by, u.name AS changed_by_name, bc.changed_at::text AS changed_at,
                    bc.changes::text AS changes, bc.reason
               FROM booking_changes bc
               JOIN users u ON u.id = bc.changed_by
              WHERE bc.booking_id = ?
              ORDER BY bc.changed_at, bc.id'
        );
        $stmt->execute([$id]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $changes = json_decode($row['changes'], true, 512, JSON_THROW_ON_ERROR);
            // jsonb ordnet Objektschluessel um: Eintraege wieder als {field, from, to} aufbauen.
            $list = [];
            foreach (array_is_list($changes) ? $changes : [] as $c) {
                $list[] = ['field' => (string) ($c['field'] ?? ''), 'from' => $c['from'] ?? null, 'to' => $c['to'] ?? null];
            }
            $rows[] = [
                'id' => (int) $row['id'],
                'changed_by' => (int) $row['changed_by'],
                'changed_by_name' => (string) $row['changed_by_name'],
                'changed_at' => (string) $row['changed_at'],
                'changes' => $list,
                'reason' => (string) $row['reason'],
            ];
        }
        return $rows;
    }
}
