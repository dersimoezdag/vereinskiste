<?php
declare(strict_types=1);

namespace Vereinskiste\Auth;

use Closure;
use PDO;

final class UserRepository
{
    private ?PDO $pdo = null;

    /** @param PDO|Closure():PDO $source Closure = Verbindung erst bei Bedarf */
    public function __construct(private PDO|Closure $source)
    {
    }

    /** @return array{id:int,email:string,name:string,password_hash:string,active:bool,roles:string[]}|null */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, email, name, password_hash, active FROM users WHERE lower(email) = lower(?)'
        );
        $stmt->execute([$email]);
        return $this->hydrate($stmt->fetch());
    }

    /** @return array{id:int,email:string,name:string,password_hash:string,active:bool,roles:string[]}|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, email, name, password_hash, active FROM users WHERE id = ?'
        );
        $stmt->execute([$id]);
        return $this->hydrate($stmt->fetch());
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $this->db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
    }

    /** @param array<string,mixed> $detail keine Personendaten */
    public function audit(string $action, ?int $userId, array $detail = []): void
    {
        $this->db()
            ->prepare('INSERT INTO audit_log (user_id, action, detail) VALUES (?, ?, ?::jsonb)')
            ->execute([$userId, $action, json_encode((object) $detail, JSON_THROW_ON_ERROR)]);
    }

    /**
     * @param array<string,mixed>|false $row
     * @return array{id:int,email:string,name:string,password_hash:string,active:bool,roles:string[]}|null
     */
    private function hydrate(array|false $row): ?array
    {
        if ($row === false) {
            return null;
        }
        $stmt = $this->db()->prepare('SELECT role FROM user_roles WHERE user_id = ? ORDER BY role');
        $stmt->execute([$row['id']]);
        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'name' => (string) $row['name'],
            'password_hash' => (string) $row['password_hash'],
            'active' => (bool) $row['active'],
            'roles' => array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)),
        ];
    }

    private function db(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = $this->source instanceof PDO ? $this->source : ($this->source)();
        }
        return $this->pdo;
    }
}
