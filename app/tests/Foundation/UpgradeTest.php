<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Foundation;

use Vereinskiste\Club\Profile;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Tests\TestCase;

final class UpgradeTest extends TestCase
{
    public function test_existing_installation_keeps_name_accounts_and_schema_history(): void
    {
        $db = $this->pdo();
        $source = dirname(__DIR__, 2) . '/migrations';
        $old = sys_get_temp_dir() . '/vk_upgrade_' . bin2hex(random_bytes(8));
        mkdir($old, 0700);
        $files = [];
        $migrationNames = array_map('basename', glob($source . '/*.sql'));
        sort($migrationNames, SORT_STRING);
        $pendingNames = array_values(array_filter($migrationNames, static fn (string $name): bool => $name >= '0008'));
        try {
            foreach (glob($source . '/*.sql') as $file) {
                if (basename($file) < '0008') {
                    $target = $old . '/' . basename($file);
                    copy($file, $target);
                    $files[] = $target;
                }
            }
            (new Migrator($db, $old))->migrate();
            $db->exec("INSERT INTO settings(key,value) VALUES ('club_name','Bestandsverein')");
            $db->exec("INSERT INTO accounts(name,type,opening_date) VALUES ('Barkasse','cash','2026-01-01')");
            $before = $db->query('SELECT id,name,type,opening_date FROM accounts')->fetchAll();
            $migrator = new Migrator($db, $source);
            $this->assertSame($pendingNames, $migrator->migrate());
            $this->assertSame('Bestandsverein', (new Profile($db))->name());
            $this->assertSame($before, $db->query('SELECT id,name,type,opening_date FROM accounts')->fetchAll());
            $this->assertSame('', $db->query('SELECT iban FROM accounts')->fetchColumn());
            $this->assertSame([], $migrator->migrate());
            $this->assertSame($migrationNames, $db->query('SELECT name FROM schema_migrations ORDER BY name')->fetchAll(\PDO::FETCH_COLUMN));
        } finally {
            foreach ($files as $file) { unlink($file); }
            rmdir($old);
        }
    }
}
