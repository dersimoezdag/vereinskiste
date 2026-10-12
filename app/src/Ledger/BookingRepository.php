<?php
declare(strict_types=1);

namespace Vereinskiste\Ledger;

use PDO;

final class BookingRepository
{
    public const KINDS = ['regular', 'correction', 'transfer'];
    public const STATUSES = ['active', 'cancelled'];
    public const DIRECTIONS = ['in', 'out'];
    private const Q_MAX = 100;

    // "changed": es gibt Verlaufseintraege ausser dem Anlage-Eintrag (ChangeLog::CREATED, z. B. Korrekturbuchung).
    private const SELECT = "
        SELECT b.id, b.account_id, a.name AS account_name, b.booking_date::text AS booking_date,
               b.amount::text AS amount, b.direction, b.category_id, c.name AS category_name,
               b.description, b.counterparty, b.receipt_no, b.kind, b.status, b.correction_of_id,
               b.transfer_id, b.version, b.created_by, u.name AS created_by_name,
               b.created_at::text AS created_at, b.updated_at::text AS updated_at,
               EXISTS (SELECT 1 FROM booking_changes bc
                        WHERE bc.booking_id = b.id
                          AND NOT bc.changes @> '[{\"field\":\"created\"}]'::jsonb) AS changed
          FROM bookings b
          JOIN accounts a ON a.id = b.account_id
          JOIN users u ON u.id = b.created_by
          LEFT JOIN categories c ON c.id = b.category_id";

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE b.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Sortierung: Buchungsdatum absteigend, dann id absteigend.
     *
     * @param array<string,mixed> $filter from, to, account_id, category_id, direction, kind, status, q
     * @return list<array<string,mixed>>
     */
    public function search(array $filter, int $limit, int $offset): array
    {
        [$where, $params] = $this->where(self::normalizeFilter($filter));
        $stmt = $this->pdo->prepare(
            self::SELECT . $where . ' ORDER BY b.booking_date DESC, b.id DESC LIMIT ' . max(1, $limit)
            . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute($params);
        return array_map($this->cast(...), $stmt->fetchAll());
    }

    /** @param array<string,mixed> $filter */
    public function count(array $filter): int
    {
        [$where, $params] = $this->where(self::normalizeFilter($filter));
        $stmt = $this->pdo->prepare('SELECT count(*) FROM bookings b' . $where);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Rohe Query-Werte auf bekannte, gueltige Filter reduzieren (Unbekanntes/Ungueltiges entfaellt).
     *
     * @param array<string,mixed> $raw
     * @return array<string,string|int>
     */
    public static function normalizeFilter(array $raw): array
    {
        $out = [];
        foreach (['from', 'to'] as $key) {
            $value = $raw[$key] ?? null;
            if (DateInput::valid($value)) {
                $out[$key] = $value;
            }
        }
        foreach (['account_id', 'category_id'] as $key) {
            $value = $raw[$key] ?? null;
            if ((is_string($value) || is_int($value)) && preg_match('/^[1-9]\d{0,17}\z/', (string) $value) === 1) {
                $out[$key] = (int) $value;
            }
        }
        foreach (['direction' => self::DIRECTIONS, 'kind' => self::KINDS, 'status' => self::STATUSES] as $key => $allowed) {
            if (isset($raw[$key]) && is_string($raw[$key]) && in_array($raw[$key], $allowed, true)) {
                $out[$key] = $raw[$key];
            }
        }
        // Ungueltiges UTF-8 wuerde PostgreSQL mit einem Fehler quittieren: Suchbegriff dann ignorieren.
        if (isset($raw['q']) && is_string($raw['q']) && mb_check_encoding($raw['q'], 'UTF-8')) {
            $q = mb_substr(trim($raw['q']), 0, self::Q_MAX);
            if ($q !== '') {
                $out['q'] = $q;
            }
        }
        return $out;
    }

    /**
     * @param array<string,string|int> $f normalisierter Filter
     * @return array{0:string,1:list<string|int>}
     */
    private function where(array $f): array
    {
        $conds = [];
        $params = [];
        if (isset($f['from'])) {
            $conds[] = 'b.booking_date >= ?::date';
            $params[] = $f['from'];
        }
        if (isset($f['to'])) {
            $conds[] = 'b.booking_date <= ?::date';
            $params[] = $f['to'];
        }
        foreach (['account_id', 'category_id', 'direction', 'kind', 'status'] as $key) {
            if (isset($f[$key])) {
                $conds[] = "b.$key = ?";
                $params[] = $f[$key];
            }
        }
        if (isset($f['q'])) {
            // "!" als Escape-Zeichen: Backslash in SQL-Literalen kollidiert mit dem PDO-Parser.
            $like = '%' . strtr((string) $f['q'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $conds[] = "(b.description ILIKE ? ESCAPE '!' OR b.counterparty ILIKE ? ESCAPE '!' OR b.receipt_no ILIKE ? ESCAPE '!')";
            array_push($params, $like, $like, $like);
        }
        return [$conds === [] ? '' : ' WHERE ' . implode(' AND ', $conds), $params];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function cast(array $row): array
    {
        foreach (['id', 'account_id', 'version', 'created_by'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        foreach (['category_id', 'correction_of_id', 'transfer_id'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        $row['changed'] = (bool) $row['changed'];
        return $row;
    }
}
