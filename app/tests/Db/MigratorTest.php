<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Db;

use Vereinskiste\Db\Migrator;
use Vereinskiste\Tests\TestCase;

final class MigratorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vk_mig_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    public function test_applies_pending_migrations_in_order_and_records_them(): void
    {
        file_put_contents($this->dir . '/0002_second.sql', 'CREATE TABLE t_two (id int);');
        file_put_contents($this->dir . '/0001_first.sql', 'CREATE TABLE t_one (id int);');
        $pdo = $this->pdo();
        $m = new Migrator($pdo, $this->dir);

        $this->assertSame(['0001_first.sql', '0002_second.sql'], $m->pending());
        $applied = $m->migrate();

        $this->assertSame(['0001_first.sql', '0002_second.sql'], $applied);
        $rows = $pdo->query('SELECT name FROM schema_migrations ORDER BY applied_at, name')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['0001_first.sql', '0002_second.sql'], $rows);
        $this->assertSame('0002_second.sql', $m->currentVersion());
        $this->assertSame([], $m->pending());
        $this->assertSame([], $m->migrate());
    }

    public function test_failed_migration_rolls_back_whole_step(): void
    {
        file_put_contents($this->dir . '/0001_bad.sql', 'CREATE TABLE t_ok (id int); THIS IS NOT SQL;');
        $pdo = $this->pdo();
        $m = new Migrator($pdo, $this->dir);

        try {
            $m->migrate();
            $this->fail('Exception erwartet');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('0001_bad.sql', $e->getMessage());
        }

        $exists = $pdo->query("SELECT to_regclass('public.t_ok')")->fetchColumn();
        $this->assertNull($exists);
        $this->assertSame(0, (int) $pdo->query('SELECT count(*) FROM schema_migrations')->fetchColumn());
        $this->assertNull($m->currentVersion());
    }
}
