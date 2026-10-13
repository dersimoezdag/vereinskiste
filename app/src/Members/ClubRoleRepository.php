<?php
declare(strict_types=1);

namespace Vereinskiste\Members;

use PDO;
use PDOException;
use Vereinskiste\Auth\UserRepository;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\History;
use Vereinskiste\Ledger\TextInput;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

final class ClubRoleRepository
{
    public function __construct(private PDO $pdo) {}

    public function list(bool $withArchived = false): array
    {
        $rows = $this->pdo->query(
            'SELECT r.*, (SELECT count(*) FROM member_club_roles a WHERE a.role_id = r.id AND a.end_date IS NULL) AS holders
               FROM club_roles r' . ($withArchived ? '' : ' WHERE r.active') . ' ORDER BY lower(r.name)'
        )->fetchAll();
        return array_map(self::hydrate(...), $rows);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT r.*, 0 AS holders FROM club_roles r WHERE r.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : self::hydrate($row);
    }

    /** @throws ValidationError|Conflict */
    public function save(?int $id, string $name, string $description, bool $active, int $version, int $actor,
        bool $singleHolder = false, string $kind = 'function'): int
    {
        if (!UserRepository::isStorableText($name . $description)) {
            throw new ValidationError(['name' => 'validation.text.encoding']);
        }
        $name = trim($name);
        $description = trim($description);
        if (TextInput::isBlank($name)) {
            throw new ValidationError(['name' => 'foundation.required']);
        }
        if (mb_strlen($name) > 100 || mb_strlen($description) > 500) {
            throw new ValidationError(['name' => 'foundation.invalid']);
        }
        if (!in_array($kind, ['function', 'membership_type'], true)) {
            throw new ValidationError(['kind' => 'foundation.invalid']);
        }
        return Transaction::run($this->pdo, function () use ($id, $name, $description, $active, $version, $actor, $singleHolder, $kind): int {
            $before = null;
            if ($id !== null) {
                $stmt = $this->pdo->prepare('SELECT * FROM club_roles WHERE id = ? FOR UPDATE');
                $stmt->execute([$id]);
                $before = $stmt->fetch();
                if ($before === false || (int) $before['version'] !== $version) {
                    throw new Conflict();
                }
                $open = $this->pdo->prepare('SELECT count(*) FROM member_club_roles WHERE role_id = ? AND end_date IS NULL');
                $open->execute([$id]);
                $openCount = (int) $open->fetchColumn();
                if ($before['kind'] !== $kind && $openCount > 0) {
                    throw new ValidationError(['kind' => 'clubroles.kind.locked']);
                }
                if ($singleHolder && !$before['single_holder'] && $openCount > 1) {
                    throw new ValidationError(['single_holder' => 'clubroles.single.conflict']);
                }
            }
            $text = $description === '' ? null : $description;
            try {
                if ($id === null) {
                    $stmt = $this->pdo->prepare('INSERT INTO club_roles (name, description, kind, single_holder, active) VALUES (?, ?, ?, ?, ?) RETURNING id');
                    $stmt->execute([$name, $text, $kind, $singleHolder ? 'true' : 'false', $active ? 'true' : 'false']);
                    $id = (int) $stmt->fetchColumn();
                } else {
                    $this->pdo->prepare('UPDATE club_roles SET name = ?, description = ?, kind = ?, single_holder = ?, active = ?, version = version + 1 WHERE id = ?')
                        ->execute([$name, $text, $kind, $singleHolder ? 'true' : 'false', $active ? 'true' : 'false', $id]);
                }
            } catch (PDOException $e) {
                if ($e->getCode() === '23505') {
                    throw new ValidationError(['name' => 'clubroles.duplicate']);
                }
                throw $e;
            }
            (new History($this->pdo))->record('club_role', $id,
                $before === null ? [] : ['name' => $before['name'], 'description' => (string) $before['description'],
                    'kind' => $before['kind'], 'single_holder' => (bool) $before['single_holder'], 'active' => (bool) $before['active']],
                ['name' => $name, 'description' => $description, 'kind' => $kind, 'single_holder' => $singleHolder, 'active' => $active], $actor, '');
            return $id;
        });
    }

    private static function hydrate(array $row): array
    {
        return ['id' => (int) $row['id'], 'name' => $row['name'], 'description' => (string) $row['description'],
            'kind' => $row['kind'], 'single_holder' => (bool) $row['single_holder'], 'system_key' => $row['system_key'],
            'active' => (bool) $row['active'], 'version' => (int) $row['version'], 'holders' => (int) $row['holders']];
    }
}
