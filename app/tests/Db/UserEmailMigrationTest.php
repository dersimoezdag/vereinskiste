<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Db;

use PDO;
use PDOException;
use RuntimeException;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Tests\TestCase;

final class UserEmailMigrationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vk_mig_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        foreach (['0001_initial.sql', '0002_transfer_sequence.sql'] as $f) {
            copy(dirname(__DIR__, 2) . '/migrations/' . $f, $this->dir . '/' . $f);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    private function runPending(PDO $pdo): void
    {
        $name = '0003_user_email_lower_unique.sql';
        copy(dirname(__DIR__, 2) . '/migrations/' . $name, $this->dir . '/' . $name);
        (new Migrator($pdo, $this->dir))->migrate();
    }

    public function test_migration_normalizes_existing_emails_and_enforces_case_insensitive_uniqueness(): void
    {
        $pdo = $this->pdo();
        (new Migrator($pdo, $this->dir))->migrate();
        $pdo->exec("INSERT INTO users (email, name, password_hash) VALUES ('  Anna@Example.ORG ', 'Anna', 'x')");

        $this->runPending($pdo);

        $this->assertSame('anna@example.org', $pdo->query('SELECT email FROM users')->fetchColumn());
        $this->expectException(PDOException::class);
        $pdo->exec("INSERT INTO users (email, name, password_hash) VALUES ('ANNA@example.org', 'Zwei', 'x')");
    }

    public function test_migration_aborts_cleanly_on_case_insensitive_duplicates(): void
    {
        $pdo = $this->pdo();
        (new Migrator($pdo, $this->dir))->migrate();
        $pdo->exec("INSERT INTO users (email, name, password_hash) VALUES ('Anna@example.org', 'Anna', 'x')");
        $pdo->exec("INSERT INTO users (email, name, password_hash) VALUES ('anna@example.org', 'Anna 2', 'x')");

        try {
            $this->runPending($pdo);
            $this->fail('RuntimeException erwartet');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('0003_user_email_lower_unique.sql', $e->getMessage());
        }

        $this->assertSame(
            ['Anna@example.org', 'anna@example.org'],
            $pdo->query('SELECT email FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)
        );
        $this->assertSame('0002_transfer_sequence.sql', (new Migrator($pdo, $this->dir))->currentVersion());
    }
}
