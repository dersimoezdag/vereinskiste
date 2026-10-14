<?php
declare(strict_types=1);

namespace Vereinskiste\Members;

use DateTimeImmutable;
use PDO;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Foundation\History;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

final class MemberRepository
{
    /** Namensfelder (Anrede, Titel, Vorname, Nachname) plus Anschrift und Kontakt; `members.name` ist der Anzeigename. */
    public const FIELDS = PersonName::LIMITS + ['address' => 200, 'house_number' => 20, 'zip' => 20, 'city' => 100, 'country' => 100,
        'email' => 254, 'phone' => 50, 'birth_date' => 10, 'notes' => 1000];

    public function __construct(private PDO $pdo) {}

    /** @return list<array<string,mixed>> */
    public function list(string $search = '', string $state = 'current', int $page = 1): array
    {
        return array_map(self::hydrate(...), $this->matching($search, $state,
            " ORDER BY lower(COALESCE(m.data->>'last_name', m.name)), lower(COALESCE(m.data->>'first_name', '')), m.id LIMIT 50 OFFSET ?",
            [(max(1, min($page, 100000)) - 1) * 50])->fetchAll());
    }

    /** All matching members, in bounded keyset batches independent of UI pagination. */
    public function forExport(string $search = '', string $state = 'all'): \Generator
    {
        $after = 0;
        do {
            $rows = $this->matching($search, $state, ' AND m.id > ? ORDER BY m.id LIMIT 500', [$after])->fetchAll();
            foreach ($rows as $row) {
                $after = (int) $row['id'];
                yield self::hydrate($row);
            }
        } while (count($rows) === 500);
    }

    private function matching(string $search, string $state, string $suffix, array $params): \PDOStatement
    {
        $search = mb_substr(trim($search), 0, 200);
        $number = ctype_digit($search) && strlen($search) <= 9 ? (int) $search : null;
        $stmt = $this->pdo->prepare(
            "SELECT m.*, u.id AS user_id,
                    EXISTS (SELECT 1 FROM membership_periods p WHERE p.member_id = m.id AND p.end_date IS NULL) AS current
               FROM members m LEFT JOIN users u ON u.member_id = m.id
              WHERE (m.name ILIKE ? OR m.member_no = ?::integer)
                AND (?::text = 'all'
                     OR (?::text = 'current' AND EXISTS (SELECT 1 FROM membership_periods p WHERE p.member_id = m.id AND p.end_date IS NULL))
                     OR (?::text = 'left' AND EXISTS (SELECT 1 FROM membership_periods p WHERE p.member_id = m.id)
                         AND NOT EXISTS (SELECT 1 FROM membership_periods p WHERE p.member_id = m.id AND p.end_date IS NULL)))" . $suffix
        );
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $state = in_array($state, ['current', 'left', 'all'], true) ? $state : 'current';
        $stmt->execute(array_merge([$like, $number, $state, $state, $state], $params));
        return $stmt;
    }

    public function find(int $id, bool $lock = false): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.*, u.id AS user_id,
                    EXISTS (SELECT 1 FROM membership_periods p WHERE p.member_id = m.id AND p.end_date IS NULL) AS current
               FROM members m LEFT JOIN users u ON u.member_id = m.id WHERE m.id = ?' . ($lock ? ' FOR UPDATE OF m' : '')
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $member = self::hydrate($row);
        $periods = $this->pdo->prepare('SELECT * FROM membership_periods WHERE member_id = ? ORDER BY start_date DESC, id DESC');
        $periods->execute([$id]);
        $member['periods'] = $periods->fetchAll();
        return $member;
    }

    /** @throws ValidationError|Conflict */
    public function save(?int $id, array $input, int $version, int $actor, string $reason = ''): int
    {
        $errors = [];
        try {
            $names = PersonName::read($input);
        } catch (ValidationError $e) {
            $errors += $e->errors;
            $names = [];
        }
        try {
            $rest = Fields::read($input, array_diff_key(self::FIELDS, PersonName::LIMITS));
        } catch (ValidationError $e) {
            $errors += $e->errors;
            $rest = ['birth_date' => ''];
        }
        if ($errors !== []) {
            throw new ValidationError($errors);
        }
        $data = $names + $rest;
        self::checkBirthDate($data['birth_date']);
        $display = PersonName::display($data);
        return Transaction::run($this->pdo, function () use ($id, $data, $display, $version, $actor, $reason): int {
            $before = $id === null ? null : $this->find($id, true);
            if ($before !== null && $before['anonymized_at'] !== null) { throw new ValidationError(['member' => 'privacy.block.already']); }
            if ($id !== null && ($before === null || $before['version'] !== $version)) {
                throw new Conflict();
            }
            $json = json_encode($data, JSON_THROW_ON_ERROR);
            if ($id === null) {
                $stmt = $this->pdo->prepare('INSERT INTO members (name, data) VALUES (?, ?::jsonb) RETURNING id');
                $stmt->execute([$display, $json]);
                $id = (int) $stmt->fetchColumn();
            } else {
                $this->pdo->prepare('UPDATE members SET name = ?, data = ?::jsonb, version = version + 1 WHERE id = ?')
                    ->execute([$display, $json, $id]);
            }
            (new History($this->pdo))->record('member', $id, $before['data'] ?? [], $data, $actor, $reason);
            return $id;
        });
    }

    private static function checkBirthDate(string $value): void
    {
        if ($value === '') {
            return;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $valid = $date !== false && $date->format('Y-m-d') === $value && $date <= new DateTimeImmutable('today');
        if (!$valid) {
            throw new ValidationError(['birth_date' => 'foundation.invalid']);
        }
    }

    private static function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['member_no'] = (int) $row['member_no'];
        $row['version'] = (int) $row['version'];
        $row['user_id'] = $row['user_id'] === null ? null : (int) $row['user_id'];
        $row['current'] = (bool) $row['current'];
        $row['data'] = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR)
            + array_fill_keys(array_keys(self::FIELDS), '');
        return $row;
    }
}
