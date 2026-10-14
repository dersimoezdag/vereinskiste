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

    public function test_board_cannot_be_manually_assigned(): void
    {
        $this->expectException(ValidationError::class);
        $this->user('board@example.org',['board']);
    }

    public function test_account_can_exist_without_manual_roles(): void
    {
        $id=$this->user('unprivileged@example.org',[]);
        $this->assertSame([],$this->roles($id));
    }

    public function test_unique_violation_on_create_maps_to_the_duplicate_email_validation_error(): void
    {
        $this->user('a@example.org', ['treasurer']);

        // Pruefung im Controller uebergangen (Wettlauf): die Datenbank entscheidet.
        try {
            $this->user('A@Example.org', ['auditor']);
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
        $actor = $this->user('chef@example.org', ['admin']);
        $this->user('a@example.org', ['treasurer']);
        $b = $this->user('b@example.org', ['treasurer']);

        try {
            $this->repo->update($b, 'Neuer Name', 'A@example.org', ['auditor'], true, $actor);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertSame(['email' => 'validation.user.email.duplicate'], $e->errors);
        }

        $row = $this->repo->findById($b);
        $this->assertSame('b@example.org', $row['email']);
        $this->assertSame(['treasurer'], $row['roles']);
        $this->assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM audit_log WHERE action = 'user_updated'")->fetchColumn());
    }

    public function test_update_of_missing_user_throws_user_not_found(): void
    {
        $this->expectException(UserNotFound::class);
        $this->repo->update(424242, 'X', 'x@example.org', ['treasurer'], true, 1);
    }

    public function test_demoting_the_other_vorstand_waits_for_a_concurrent_demotion(): void
    {
        $a = $this->user('a@example.org', ['admin']);
        $b = $this->user('b@example.org', ['admin']);

        // Verbindung 2: A entzieht B die Rolle, noch nicht committed.
        $other = $this->connect();
        $otherRepo = new UserRepository($other);
        $other->beginTransaction();
        $otherRepo->update($b, 'Test b@example.org', 'b@example.org', ['treasurer'], true, $a);

        // Verbindung 1: B entzieht A die Rolle. Muss auf die Sperre warten, nicht an ihr vorbeilesen.
        $this->pdo->exec("SET lock_timeout = '300ms'");
        try {
            $this->repo->update($a, 'Test a@example.org', 'a@example.org', ['treasurer'], true, $b);
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
            $this->repo->update($a, 'Test a@example.org', 'a@example.org', ['treasurer'], true, $b);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertSame(['roles' => 'validation.user.last_admin'], $e->errors);
        }
        $this->assertSame(['admin'], $this->roles($a));
        $this->assertSame(['treasurer'], $this->roles($b));
    }

    public function test_two_vorstaende_demoting_themselves_concurrently_cannot_leave_none(): void
    {
        // Jeder fasst nur die eigene Zeile an (kein Zeilen-/FK-Konflikt): nur die Advisory-Sperre serialisiert.
        $a = $this->user('a@example.org', ['admin']);
        $b = $this->user('b@example.org', ['admin']);

        $other = $this->connect();
        $otherRepo = new UserRepository($other);
        $other->beginTransaction();
        $otherRepo->update($a, 'Test a@example.org', 'a@example.org', ['treasurer'], true, $a);

        $this->pdo->exec("SET lock_timeout = '300ms'");
        try {
            $this->repo->update($b, 'Test b@example.org', 'b@example.org', ['treasurer'], true, $b);
            $this->fail('55P03 erwartet: die Rollenaenderung muss auf die andere warten');
        } catch (PDOException $e) {
            $this->assertSame('55P03', $e->getCode());
        } finally {
            $this->pdo->exec('RESET lock_timeout');
        }
        $other->commit();

        try {
            $this->repo->update($b, 'Test b@example.org', 'b@example.org', ['treasurer'], true, $b);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertSame(['roles' => 'validation.user.last_admin'], $e->errors);
        }
        $this->assertSame(['admin'], $this->roles($b));
        $this->assertSame(['treasurer'], $this->roles($a));
    }

    public function test_concurrent_deactivation_and_demotion_cannot_remove_both_vorstaende(): void
    {
        $a = $this->user('a@example.org', ['admin']);
        $b = $this->user('b@example.org', ['admin']);

        $other = $this->connect();
        $otherRepo = new UserRepository($other);
        $other->beginTransaction();
        // A deaktiviert B (uncommitted) ...
        $otherRepo->update($b, 'Test b@example.org', 'b@example.org', ['admin'], false, $a);

        // ... waehrend B sich selbst die Rolle nimmt.
        $this->pdo->exec("SET lock_timeout = '300ms'");
        try {
            $this->repo->update($b, 'Test b@example.org', 'b@example.org', ['treasurer'], true, $b);
            $this->fail('55P03 erwartet');
        } catch (PDOException $e) {
            $this->assertSame('55P03', $e->getCode());
        } finally {
            $this->pdo->exec('RESET lock_timeout');
        }
        $other->commit();

        // B ist inzwischen deaktiviert und kein aktiver Vorstand mehr; A bleibt geschuetzt.
        try {
            $this->repo->update($a, 'Test a@example.org', 'a@example.org', ['treasurer'], true, $a);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertSame(['roles' => 'validation.user.last_admin'], $e->errors);
        }
        $active = (int) $this->pdo->query(
            "SELECT count(*) FROM users u JOIN user_roles r ON r.user_id = u.id WHERE r.role = 'admin' AND u.active"
        )->fetchColumn();
        $this->assertSame(1, $active);
    }

    public function test_deactivation_of_last_vorstand_reports_the_active_field(): void
    {
        $a = $this->user('a@example.org', ['admin']);
        $c = $this->user('c@example.org', ['treasurer']);

        try {
            // Aktor ist ein anderer Benutzer (kein Selbst-Deaktivieren), Ziel ist der letzte Vorstand.
            $this->repo->update($a, 'Test a@example.org', 'a@example.org', ['admin'], false, $c);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertSame(['active' => 'validation.user.last_admin'], $e->errors);
        }
        $this->assertTrue($this->repo->findById($a)['active']);
    }

    public function test_last_admin_remains_protected_and_a_vorstand_can_take_over(): void
    {
        $admin = $this->user('admin@example.org', ['admin']);
        $actor = $this->user('actor@example.org', ['treasurer']);
        try {
            $this->repo->update($admin, 'Admin', 'admin@example.org', ['treasurer'], true, $actor);
            $this->fail('Letzter Administrator darf nicht entmachtet werden');
        } catch (ValidationError $e) {
            $this->assertSame(['roles' => 'validation.user.last_admin'], $e->errors);
        }
        $this->assertSame(['admin'], $this->roles($admin));
        $vorstand = $this->user('vorstand@example.org', ['admin']);
        $this->repo->update($admin, 'Admin', 'admin@example.org', ['treasurer'], true, $vorstand);
        $this->assertSame(['treasurer'], $this->roles($admin));
        $this->assertSame(['admin'], $this->roles($vorstand));
    }
}
