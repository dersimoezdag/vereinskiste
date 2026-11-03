<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Db;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Tests\TestCase;

final class SplitHouseNumbersMigrationTest extends TestCase
{
    private PDO $pdo;
    private Migrator $migrator;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        $migrator = new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations');
        // bis einschliesslich 0022 migrieren, Daten anlegen, dann 0023 ausfuehren
        $dir = sys_get_temp_dir() . '/vk-mig-' . bin2hex(random_bytes(4));
        mkdir($dir);
        foreach (glob(dirname(__DIR__, 2) . '/migrations/*.sql') as $file) {
            if (basename($file) < '0023') { copy($file, $dir . '/' . basename($file)); }
        }
        (new Migrator($this->pdo, $dir))->migrate();
        array_map('unlink', glob($dir . '/*.sql')); rmdir($dir);
        $this->migrator = $migrator;
    }

    public function test_unambiguous_addresses_are_split_others_kept(): void
    {
        $insert = $this->pdo->prepare("INSERT INTO finance_contacts (kind, name, data) VALUES ('person', ?, ?::jsonb)");
        foreach (['Musterstraße 12' => 'a', 'Am Weiher 3a' => 'b', 'Hauptstr. 10-12' => 'c', 'Postfach' => 'd', 'Ring 5' => 'e'] as $address => $name) {
            $data = ['address' => $address] + ($name === 'e' ? ['house_number' => '7'] : []);
            $insert->execute([$name, json_encode($data, JSON_UNESCAPED_UNICODE)]);
        }
        $this->pdo->exec("UPDATE club_profile SET data = data || '{\"address\":\"Schillerstraße 1\"}'::jsonb WHERE id = 1");
        $this->migrator->migrate();

        $rows = $this->pdo->query("SELECT name, data->>'address' AS a, data->>'house_number' AS h FROM finance_contacts ORDER BY name")->fetchAll(PDO::FETCH_NUM);
        $this->assertSame([
            ['a', 'Musterstraße', '12'], ['b', 'Am Weiher', '3a'], ['c', 'Hauptstr.', '10-12'], ['d', 'Postfach', null], ['e', 'Ring 5', '7'],
        ], $rows);
        $this->assertSame(['Schillerstraße', '1'], $this->pdo->query("SELECT data->>'address', data->>'house_number' FROM club_profile")->fetch(PDO::FETCH_NUM));
    }
}
