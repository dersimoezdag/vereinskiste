<?php
declare(strict_types=1);

namespace Vereinskiste\Ledger;

use PDO;

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

    public function create(string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO categories (name) VALUES (?) RETURNING id');
        $stmt->execute([$name]);
        return (int) $stmt->fetchColumn();
    }

    public function rename(int $id, string $name): void
    {
        $this->pdo->prepare('UPDATE categories SET name = ? WHERE id = ?')->execute([$name, $id]);
    }

    public function toggleActive(int $id): void
    {
        $this->pdo->prepare('UPDATE categories SET active = NOT active WHERE id = ?')->execute([$id]);
    }
}
