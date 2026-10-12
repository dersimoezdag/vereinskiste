<?php
declare(strict_types=1);

namespace Vereinskiste\Ledger;

use InvalidArgumentException;
use PDO;
use PDOException;
use Vereinskiste\Audit\AuditLog;

final class CategoryRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array{id:int,name:string,active:bool}> */
    public function all(): array
    {
        return $this->pdo->query(
            'SELECT id, name, active FROM categories ORDER BY active DESC, lower(name), id'
        )->fetchAll();
    }

    /** @return array{id:int,name:string,active:bool}|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, active FROM categories WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM categories WHERE lower(name) = lower(?) AND id <> ?');
        $stmt->execute([$name, $exceptId ?? 0]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Legt eine Kategorie an und protokolliert `category_created` in derselben Transaktion.
     *
     * @throws NameAlreadyExists
     */
    public function create(string $name, int $userId): int
    {
        try {
            return Transaction::run($this->pdo, function () use ($name, $userId): int {
                $stmt = $this->pdo->prepare('INSERT INTO categories (name) VALUES (?) RETURNING id');
                $stmt->execute([$name]);
                $id = (int) $stmt->fetchColumn();
                (new AuditLog($this->pdo))->record('category_created', $userId, ['id' => $id, 'name' => $name]);
                return $id;
            });
        } catch (PDOException $e) {
            throw NameAlreadyExists::from($e);
        }
    }

    /**
     * Benennt um und protokolliert `category_renamed` (id, from, to); unveraenderter Name schreibt nichts.
     *
     * @throws NameAlreadyExists
     * @throws InvalidArgumentException unbekannte Kategorie
     */
    public function rename(int $id, string $name, int $userId): void
    {
        try {
            Transaction::run($this->pdo, function () use ($id, $name, $userId): void {
                $stmt = $this->pdo->prepare('SELECT name FROM categories WHERE id = ? FOR UPDATE');
                $stmt->execute([$id]);
                $old = $stmt->fetchColumn();
                if ($old === false) {
                    throw new InvalidArgumentException("Unbekannte Kategorie $id.");
                }
                if ($old === $name) {
                    return;
                }
                $this->pdo->prepare('UPDATE categories SET name = ? WHERE id = ?')->execute([$name, $id]);
                (new AuditLog($this->pdo))->record('category_renamed', $userId, ['id' => $id, 'from' => $old, 'to' => $name]);
            });
        } catch (PDOException $e) {
            throw NameAlreadyExists::from($e);
        }
    }

    /**
     * Schaltet aktiv/inaktiv um und protokolliert `category_activated`/`category_deactivated`.
     *
     * @return bool neuer Zustand (true = aktiv)
     * @throws InvalidArgumentException unbekannte Kategorie
     */
    public function toggleActive(int $id, int $userId): bool
    {
        return Transaction::run($this->pdo, function () use ($id, $userId): bool {
            $stmt = $this->pdo->prepare('UPDATE categories SET active = NOT active WHERE id = ? RETURNING active, name');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row === false) {
                throw new InvalidArgumentException("Unbekannte Kategorie $id.");
            }
            (new AuditLog($this->pdo))->record(
                $row['active'] ? 'category_activated' : 'category_deactivated',
                $userId,
                ['id' => $id, 'name' => $row['name']]
            );
            return (bool) $row['active'];
        });
    }
}
