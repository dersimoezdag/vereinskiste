<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Auth;

use PDO;
use PDOException;
use Vereinskiste\Auth\UserNotFound;
use Vereinskiste\Auth\UserRepository;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Tests\TestCase;

final class UserRepositoryTest extends TestCase
{
    private PDO $pdo;
    private UserRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->repo = new UserRepository($this->pdo);
    }

    /** @param string[] $roles */
    private function user(string $email, array $roles): int
    {
        return $this->repo->create('Test ' . $email, $email, password_hash('geheim-geheim', PASSWORD_DEFAULT), $roles, null);
    }

    /** @return string[] */
    private function roles(int $id): array
    {
        return $this->repo->findById($id)['roles'];
    }

    public function test_admin_is_granted_on_new_board_assignment_but_can_be_revoked_independently(): void
    {
        $actor = $this->user('admin@example.org', ['admin']);
        $id = $this->user('board@example.org', ['vorstand']);
        $this->assertSame(['admin', 'vorstand'], $this->roles($id));
        $this->repo->update($id, 'Board', 'board@example.org', ['vorstand'], true, $actor);
        $this->assertSame(['vorstand'], $this->roles($id));
        $this->repo->update($id, 'Board renamed', 'board@example.org', ['vorstand'], true, $actor);
        $this->assertSame(['vorstand'], $this->roles($id));
        $this->assertSame([], \Vereinskiste\Auth\Permissions::for($this->roles($id)));
        $this->repo->update($id, 'Board', 'board@example.org', ['kassenwart'], true, $actor);
        $this->repo->update($id, 'Board', 'board@example.org', ['vorstand'], true, $actor);
        $this->assertSame(['admin', 'vorstand'], $this->roles($id));
        $this->repo->update($id, 'Board', 'board@example.org', ['admin'], true, $actor);
        $this->assertSame(['admin'], $this->roles($id));
        $this->repo->update($id, 'Board', 'board@example.org', ['kassenwart'], true, $actor);
        $this->assertSame(['kassenwart'], $this->roles($id));
    }

    public function test_board_without_admin_does_not_satisfy_last_admin_protection(): void
    {
        $admin = $this->user('admin@example.org', ['admin']);
        $board = $this->user('board@example.org', ['vorstand']);
        $this->repo->update($board, 'Board', 'board@example.org', ['vorstand'], true, $admin);
        $this->expectException(ValidationError::class);
        $this->repo->update($admin, 'Admin', 'admin@example.org', ['kassenwart'], true, $admin);
    }

    public function test_migration_adds_explicit_admin_to_existing_board_accounts_without_duplicates(): void
    {
        $id = $this->user('board@example.org', ['vorstand']);
        $this->pdo->prepare("DELETE FROM user_roles WHERE user_id=? AND role='admin'")->execute([$id]);
        $sql = file_get_contents(dirname(__DIR__, 2) . '/migrations/0016_vorstand_admin_assignment.sql');
        $this->pdo->exec($sql);
        $this->pdo->exec($sql);
        $this->assertSame(['admin', 'vorstand'], $this->roles($id));
        $this->assertSame(2, (int) $this->pdo->query('SELECT count(*) FROM user_roles')->fetchColumn());
    }

    public function test_unique_violation_on_create_maps_to_the_duplicate_email_validation_error(): void
    {
        $this->user('a@example.org', ['kassenwart']);

        // Pruefung im Controller uebergangen (Wettlauf): die Datenbank entscheidet.
        try {
            $this->user('A@Example.org', ['kassenpruefer']);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertSame(['email' => 'validation.user.email.duplicate'], $e->errors);
        }
        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM users')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM user_roles')->fetchColumn());
    }

    public function test_unique_violation_on_update_rolls_back_everything(): void
    {
        $actor = $this->user('chef@example.org', ['vorstand']);
        $this->user('a@example.org', ['kassenwart']);
        $b = $this->user('b@example.org', ['kassenwart']);

        try {
            $this->repo->update($b, 'Neuer Name', 'A@example.org', ['kassenpruefer'], true, $actor);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertSame(['email' => 'validation.user.email.duplicate'], $e->errors);
        }

        $row = $this->repo->findById($b);
        $this->assertSame('b@example.org', $row['email']);
        $this->assertSame(['kassenwart'], $row['roles']);
        $this->assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM audit_log WHERE action = 'user_updated'")->fetchColumn());
    }

    public function test_update_of_missing_user_throws_user_not_found(): void
    {
        $this->expectException(UserNotFound::class);
        $this->repo->update(424242, 'X', 'x@example.org', ['kassenwart'], true, 1);
    }

    public function test_demoting_the_other_vorstand_waits_for_a_concurrent_demotion(): void
    {
        $a = $this->user('a@example.org', ['vorstand']);
        $b = $this->user('b@example.org', ['vorstand']);

        // Verbindung 2: A entzieht B die Rolle, noch nicht committed.
        $other = $this->connect();
        $otherRepo = new UserRepository($other);
        $other->beginTransaction();
        $otherRepo->update($b, 'Test b@example.org', 'b@example.org', ['kassenwart'], true, $a);

        // Verbindung 1: B entzieht A die Rolle. Muss auf die Sperre warten, nicht an ihr vorbeilesen.
        $this->pdo->exec("SET lock_timeout = '300ms'");
        try {
            $this->repo->update($a, 'Test a@example.org', 'a@example.org', ['kassenwart'], true, $b);
            $this->fail('Die gleichzeitige Rollenaenderung muss warten (55P03)');
        } catch (PDOException $e) {
            $this->assertSame('55P03', $e->getCode());
        } finally {
            $this->pdo->exec('RESET lock_timeout');
        }
        $this->assertFalse($this->pdo->inTransaction());

        $other->commit();

        // Nach dem Commit sieht der zweite Vorgang den neuen Stand: A ist nun der letzte Vorstand.
        try {
            $this->repo->update($a, 'Test a@example.org', 'a@example.org', ['kassenwart'], true, $b);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertSame(['roles' => 'validation.user.last_admin'], $e->errors);
        }
        $this->assertSame(['admin', 'vorstand'], $this->roles($a));
        $this->assertSame(['kassenwart'], $this->roles($b));
    }

    public function test_two_vorstaende_demoting_themselves_concurrently_cannot_leave_none(): void
    {
        // Jeder fasst nur die eigene Zeile an (kein Zeilen-/FK-Konflikt): nur die Advisory-Sperre serialisiert.
        $a = $this->user('a@example.org', ['vorstand']);
        $b = $this->user('b@example.org', ['vorstand']);

        $other = $this->connect();
        $otherRepo = new UserRepository($other);
        $other->beginTransaction();
        $otherRepo->update($a, 'Test a@example.org', 'a@example.org', ['kassenwart'], true, $a);

        $this->pdo->exec("SET lock_timeout = '300ms'");
        try {
            $this->repo->update($b, 'Test b@example.org', 'b@example.org', ['kassenwart'], true, $b);
            $this->fail('55P03 erwartet: die Rollenaenderung muss auf die andere warten');
        } catch (PDOException $e) {
            $this->assertSame('55P03', $e->getCode());
        } finally {
            $this->pdo->exec('RESET lock_timeout');
        }
        $other->commit();

        try {
            $this->repo->update($b, 'Test b@example.org', 'b@example.org', ['kassenwart'], true, $b);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertSame(['roles' => 'validation.user.last_admin'], $e->errors);
        }
        $this->assertSame(['admin', 'vorstand'], $this->roles($b));
        $this->assertSame(['kassenwart'], $this->roles($a));
    }

    public function test_concurrent_deactivation_and_demotion_cannot_remove_both_vorstaende(): void
    {
        $a = $this->user('a@example.org', ['vorstand']);
        $b = $this->user('b@example.org', ['vorstand']);

        $other = $this->connect();
        $otherRepo = new UserRepository($other);
        $other->beginTransaction();
        // A deaktiviert B (uncommitted) ...
        $otherRepo->update($b, 'Test b@example.org', 'b@example.org', ['vorstand'], false, $a);

        // ... waehrend B sich selbst die Rolle nimmt.
        $this->pdo->exec("SET lock_timeout = '300ms'");
        try {
            $this->repo->update($b, 'Test b@example.org', 'b@example.org', ['kassenwart'], true, $b);
            $this->fail('55P03 erwartet');
        } catch (PDOException $e) {
            $this->assertSame('55P03', $e->getCode());
        } finally {
            $this->pdo->exec('RESET lock_timeout');
        }
        $other->commit();

        // B ist inzwischen deaktiviert und kein aktiver Vorstand mehr; A bleibt geschuetzt.
        try {
            $this->repo->update($a, 'Test a@example.org', 'a@example.org', ['kassenwart'], true, $a);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertSame(['roles' => 'validation.user.last_admin'], $e->errors);
        }
        $active = (int) $this->pdo->query(
            "SELECT count(*) FROM users u JOIN user_roles r ON r.user_id = u.id WHERE r.role = 'vorstand' AND u.active"
        )->fetchColumn();
        $this->assertSame(1, $active);
    }

    public function test_deactivation_of_last_vorstand_reports_the_active_field(): void
    {
        $a = $this->user('a@example.org', ['vorstand']);
        $c = $this->user('c@example.org', ['kassenwart']);

        try {
            // Aktor ist ein anderer Benutzer (kein Selbst-Deaktivieren), Ziel ist der letzte Vorstand.
            $this->repo->update($a, 'Test a@example.org', 'a@example.org', ['vorstand'], false, $c);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertSame(['active' => 'validation.user.last_admin'], $e->errors);
        }
        $this->assertTrue($this->repo->findById($a)['active']);
    }

    public function test_last_admin_remains_protected_and_a_vorstand_can_take_over(): void
    {
        $admin = $this->user('admin@example.org', ['admin']);
        $actor = $this->user('actor@example.org', ['kassenwart']);
        try {
            $this->repo->update($admin, 'Admin', 'admin@example.org', ['kassenwart'], true, $actor);
            $this->fail('Letzter Administrator darf nicht entmachtet werden');
        } catch (ValidationError $e) {
            $this->assertSame(['roles' => 'validation.user.last_admin'], $e->errors);
        }
        $this->assertSame(['admin'], $this->roles($admin));
        $vorstand = $this->user('vorstand@example.org', ['vorstand']);
        $this->repo->update($admin, 'Admin', 'admin@example.org', ['kassenwart'], true, $vorstand);
        $this->assertSame(['kassenwart'], $this->roles($admin));
        $this->assertSame(['admin', 'vorstand'], $this->roles($vorstand));
    }
}
