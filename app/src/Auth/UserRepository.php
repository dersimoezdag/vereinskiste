<?php
declare(strict_types=1);

namespace Vereinskiste\Auth;

use Closure;
use PDO;
use PDOException;
use Throwable;
use Vereinskiste\Ledger\ValidationError;

final class UserRepository
{
    /**
     * Advisory-Sperre (transaktionsgebunden), die alle Aenderungen an Rollen/Aktiv-Status serialisiert.
     * So sieht jede Pruefung "letzter aktiver Administrator" den committed Stand der vorherigen Aenderung;
     * zwei Administratoren koennen sich nicht gleichzeitig gegenseitig entmachten. Nur diese Klasse nutzt sie.
     */
    public const LOCK_KEY = 7340163;

    private ?PDO $pdo = null;

    /** @param PDO|Closure():PDO $source Closure = Verbindung erst bei Bedarf */
    public function __construct(private PDO|Closure $source)
    {
    }

    /** Speicherform einer E-Mail-Adresse: getrimmt und kleingeschrieben (UTF-8). */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    /** Gueltiges UTF-8 ohne NUL-Byte: nur solcher Text darf an PostgreSQL gehen. */
    public static function isStorableText(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8') && !str_contains($value, "\0");
    }

    /** @return array{id:int,email:string,name:string,password_hash:string,active:bool,roles:string[]}|null */
    public function findByEmail(string $email): ?array
    {
        if (!self::isStorableText($email)) {
            return null;
        }
        $stmt = $this->db()->prepare(
            'SELECT id, email, name, password_hash, active, member_id FROM users WHERE lower(email) = lower(?)'
        );
        $stmt->execute([$email]);
        return $this->hydrate($stmt->fetch());
    }

    /** @return array{id:int,email:string,name:string,password_hash:string,active:bool,roles:string[]}|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, email, name, password_hash, active, member_id FROM users WHERE id = ?'
        );
        $stmt->execute([$id]);
        return $this->hydrate($stmt->fetch());
    }

    /**
     * Alle Benutzer (ohne Passwort-Hash), nach Name sortiert.
     *
     * @return list<array{id:int,email:string,name:string,active:bool,roles:string[]}>
     */
    public function all(): array
    {
        $roles = [];
        foreach ($this->db()->query('SELECT user_id, role FROM user_roles ORDER BY role')->fetchAll() as $r) {
            $roles[(int) $r['user_id']][] = (string) $r['role'];
        }
        $users = [];
        $rows = $this->db()->query('SELECT u.id, u.email, u.name, u.active, u.member_id, m.name AS member_name, m.member_no
            FROM users u LEFT JOIN members m ON m.id = u.member_id ORDER BY lower(u.name), u.id')->fetchAll();
        foreach ($rows as $row) {
            $users[] = [
                'id' => (int) $row['id'],
                'email' => (string) $row['email'],
                'name' => (string) $row['name'],
                'active' => (bool) $row['active'],
                'member_id' => $row['member_id'] === null ? null : (int) $row['member_id'],
                'member_name' => $row['member_name'],
                'member_no' => $row['member_no'] === null ? null : (int) $row['member_no'],
                'roles' => array_values(array_unique(array_merge($roles[(int)$row['id']] ?? [], (new MemberDerivedRoles($this->db()))->roles((int)$row['id'])))),
            ];
        }
        return $users;
    }

    /**
     * Legt einen Benutzer samt Rollen an und protokolliert `user_created` (ohne Passwort/Hash/E-Mail).
     *
     * @param string[] $roles
     * @param int|null $actorId Benutzer, der die Aktion ausfuehrt (Protokoll)
     * @throws ValidationError ['email' => ...] bei bereits vergebener Adresse, ['roles' => ...] bei ungueltigen Rollen
     */
    public function create(string $name, string $email, string $passwordHash, array $roles, ?int $actorId): int
    {
        $roles = RoleSelection::read($roles);
        $email = self::normalizeEmail($email);

        return $this->transactional(function () use ($name, $email, $passwordHash, $roles, $actorId): int {
            try {
                $stmt = $this->db()->prepare(
                    'INSERT INTO users (email, name, password_hash) VALUES (?, ?, ?) RETURNING id'
                );
                $stmt->execute([$email, $name, $passwordHash]);
            } catch (PDOException $e) {
                throw $this->duplicateOrRethrow($e);
            }
            $id = (int) $stmt->fetchColumn();
            $this->insertRoles($id, $roles);
            $this->audit('user_created', $actorId, ['user_id' => $id, 'roles' => $roles]);
            return $id;
        });
    }

    /**
     * Aendert Name, E-Mail, Rollen und Aktiv-Status. Schreibt `user_updated` (geaenderte Feldnamen und
     * Rollen-Diff, keine Werte) und bei Aenderung des Aktiv-Status `user_deactivated`/`user_activated`.
     * Ohne Aenderung wird nichts geschrieben.
     *
     * Regeln (unter der Advisory-Sperre geprueft): Der letzte aktive Administrator kann weder deaktiviert werden
     * noch die Rolle verlieren; niemand deaktiviert sich selbst.
     *
     * @param string[] $roles
     * @return string[] Namen der geaenderten Felder (leer = nichts geaendert)
     * @throws UserNotFound
     * @throws ValidationError
     */
    public function update(int $id, string $name, string $email, array $roles, bool $active, int $actorId): array
    {
        $roles = RoleSelection::read($roles);
        $email = self::normalizeEmail($email);

        return $this->transactional(function () use ($id, $name, $email, $roles, $active, $actorId): array {
            $pdo = $this->db();
            // Erst sperren, dann lesen: unter READ COMMITTED sieht jedes Lesen danach den Stand nach dem Warten.
            $pdo->query('SELECT pg_advisory_xact_lock(' . self::LOCK_KEY . ')');

            $stmt = $pdo->prepare('SELECT email, name, active, member_id FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row === false) {
                throw new UserNotFound($id);
            }
            if ($row['member_id'] !== null) { $name=$row['name'];$email=$row['email']; }
            $wasActive = (bool) $row['active'];
            $before = $this->rolesOf($id);

            if ($actorId === $id && $wasActive && !$active) {
                throw new ValidationError(['active' => 'validation.user.self_deactivate']);
            }

            $wasAdmin = $wasActive && in_array('admin', $before, true);
            $staysAdmin = $active && in_array('admin', $roles, true);
            if ($wasAdmin && !$staysAdmin && $this->otherActiveAdmins($id) === 0) {
                throw new ValidationError([($active ? 'roles' : 'active') => 'validation.user.last_admin']);
            }

            $added = array_values(array_diff($roles, $before));
            $removed = array_values(array_diff($before, $roles));
            $fields = [];
            if ((string) $row['name'] !== $name) {
                $fields[] = 'name';
            }
            if ((string) $row['email'] !== $email) {
                $fields[] = 'email';
            }
            if ($added !== [] || $removed !== []) {
                $fields[] = 'roles';
            }
            if ($wasActive !== $active) {
                $fields[] = 'active';
            }
            if ($fields === []) {
                return [];
            }

            try {
                $pdo->prepare('UPDATE users SET name = ?, email = ?, active = ?::boolean WHERE id = ?')
                    ->execute([$name, $email, $active ? 'true' : 'false', $id]);
            } catch (PDOException $e) {
                throw $this->duplicateOrRethrow($e);
            }
            if ($removed !== []) {
                $in = implode(',', array_fill(0, count($removed), '?'));
                $pdo->prepare("DELETE FROM user_roles WHERE user_id = ? AND role IN ($in)")
                    ->execute(array_merge([$id], $removed));
            }
            $this->insertRoles($id, $added);

            // Nur Aktiv-Status geaendert: ein Eintrag (user_deactivated/user_activated) genuegt.
            if ($fields !== ['active']) {
                $this->audit('user_updated', $actorId, [
                    'user_id' => $id,
                    'fields' => $fields,
                    'roles_added' => $added,
                    'roles_removed' => $removed,
                ]);
            }
            if ($wasActive !== $active) {
                $this->audit($active ? 'user_activated' : 'user_deactivated', $actorId, ['user_id' => $id]);
            }
            return $fields;
        });
    }

    /**
     * Setzt das Passwort eines Benutzers (durch den Vorstand) und protokolliert `user_password_reset`
     * ohne Passwort und Hash.
     *
     * @throws UserNotFound
     */
    public function resetPassword(int $id, string $passwordHash, int $actorId): void
    {
        $this->transactional(function () use ($id, $passwordHash, $actorId): void {
            $stmt = $this->db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([$passwordHash, $id]);
            if ($stmt->rowCount() !== 1) {
                throw new UserNotFound($id);
            }
            $this->audit('user_password_reset', $actorId, ['user_id' => $id]);
        });
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

    /** @param string[] $roles */
    private function insertRoles(int $id, array $roles): void
    {
        $stmt = $this->db()->prepare('INSERT INTO user_roles (user_id, role) VALUES (?, ?)');
        foreach ($roles as $role) {
            $stmt->execute([$id, $role]);
        }
    }

    /** @return string[] */
    private function rolesOf(int $id): array
    {
        $stmt = $this->db()->prepare('SELECT role FROM user_roles WHERE user_id = ? ORDER BY role');
        $stmt->execute([$id]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function otherActiveAdmins(int $excludeId): int
    {
        $stmt = $this->db()->prepare(
            "SELECT count(*) FROM users u JOIN user_roles r ON r.user_id = u.id
              WHERE r.role = 'admin' AND u.active AND u.id <> ?"
        );
        $stmt->execute([$excludeId]);
        return (int) $stmt->fetchColumn();
    }

    /** Eindeutigkeitsverletzung (23505) = Adresse vergeben (auch beim Wettlauf); alles andere unveraendert. */
    private function duplicateOrRethrow(PDOException $e): Throwable
    {
        if ($e->getCode() === '23505') {
            return new ValidationError(['email' => 'validation.user.email.duplicate']);
        }
        return $e;
    }

    /**
     * Fuehrt `$work` in einer eigenen Transaktion aus; laeuft schon eine (der Aufrufer verantwortet sie),
     * nimmt es daran teil.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    private function transactional(callable $work): mixed
    {
        $pdo = $this->db();
        if ($pdo->inTransaction()) {
            return $work();
        }
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
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
        $manual=array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN));
        $derived=(new MemberDerivedRoles($this->db()))->sources((int)$row['id']);
        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'name' => (string) $row['name'],
            'password_hash' => (string) $row['password_hash'],
            'active' => (bool) $row['active'],
            'member_id' => isset($row['member_id']) && $row['member_id'] !== null ? (int) $row['member_id'] : null,
            'roles' => array_values(array_unique(array_merge($manual, array_column($derived,'role')))),
            'derived_roles' => $derived,
            'manual_roles' => $manual,
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
