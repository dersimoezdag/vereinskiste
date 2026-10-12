<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Db;

use PDO;
use PDOException;
use PDOStatement;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Ledger\AccountRepository;
use Vereinskiste\Ledger\CategoryRepository;
use Vereinskiste\Ledger\NameAlreadyExists;
use Vereinskiste\Tests\TestCase;

/** Migration 0005 und Wettlaeufe: Konto-/Kategorienamen sind in der Datenbank eindeutig (lower(name)). */
final class NameUniquenessTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vk_mig_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        foreach (glob(dirname(__DIR__, 2) . '/migrations/000[1-4]_*.sql') ?: [] as $f) {
            copy($f, $this->dir . '/' . basename($f));
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    private function migrateOld(PDO $pdo): void
    {
        (new Migrator($pdo, $this->dir))->migrate();
    }

    private function runNew(PDO $pdo): void
    {
        $name = '0005_unique_account_category_names.sql';
        copy(dirname(__DIR__, 2) . '/migrations/' . $name, $this->dir . '/' . $name);
        (new Migrator($pdo, $this->dir))->migrate();
    }

    private function fullSchema(): PDO
    {
        $pdo = $this->pdo();
        (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        return $pdo;
    }

    private function user(PDO $pdo): int
    {
        return (int) $pdo->query(
            "INSERT INTO users (email, name, password_hash) VALUES ('v@example.org', 'Vorstand', 'x') RETURNING id"
        )->fetchColumn();
    }

    // --- Migration ----------------------------------------------------------------------------------------

    public function test_migration_enforces_case_insensitive_uniqueness_on_both_tables(): void
    {
        $pdo = $this->pdo();
        $this->migrateOld($pdo);
        $pdo->exec("INSERT INTO accounts (name, type, opening_date) VALUES ('Barkasse', 'cash', '2026-01-01')");
        $pdo->exec("INSERT INTO categories (name) VALUES ('Porto')");

        $this->runNew($pdo);

        foreach ([
            "INSERT INTO accounts (name, type, opening_date) VALUES ('BARKASSE', 'cash', '2026-01-01')",
            "INSERT INTO categories (name) VALUES ('porto')",
        ] as $sql) {
            try {
                $pdo->exec($sql);
                $this->fail('Eindeutigkeitsverletzung erwartet: ' . $sql);
            } catch (PDOException $e) {
                $this->assertSame('23505', $e->getCode());
            }
        }
        // Umbenennen auf einen vorhandenen Namen in anderer Schreibweise wird ebenfalls abgewiesen.
        $pdo->exec("INSERT INTO categories (name) VALUES ('Spenden')");
        $this->expectException(PDOException::class);
        $pdo->exec("UPDATE categories SET name = 'PORTO' WHERE name = 'Spenden'");
    }

    /** @dataProvider duplicateData */
    public function test_migration_aborts_with_clear_message_and_keeps_data(string $sql, string $table, string $needle): void
    {
        $pdo = $this->pdo();
        $this->migrateOld($pdo);
        $pdo->exec($sql);

        try {
            $this->runNew($pdo);
            $this->fail('RuntimeException erwartet');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('0005_unique_account_category_names.sql', $e->getMessage());
            $this->assertStringContainsString($needle, $e->getMessage());
        }

        $this->assertSame(2, (int) $pdo->query("SELECT count(*) FROM $table")->fetchColumn());
        $this->assertSame('0004_password_resets.sql', (new Migrator($pdo, $this->dir))->currentVersion());
        $this->assertNull(
            $pdo->query("SELECT to_regclass('public.accounts_name_lower_key')")->fetchColumn() ?: null
        );
    }

    /** @return array<string,array{string,string,string}> */
    public static function duplicateData(): array
    {
        return [
            'accounts' => [
                "INSERT INTO accounts (name, type, opening_date) VALUES ('Kasse', 'cash', '2026-01-01'), ('KASSE', 'bank', '2026-01-01')",
                'accounts',
                'Kasse / KASSE',
            ],
            'categories' => [
                "INSERT INTO categories (name) VALUES ('Porto'), ('porto')",
                'categories',
                'Porto / porto',
            ],
        ];
    }

    // --- Wettlauf -----------------------------------------------------------------------------------------

    public function test_account_create_race_second_insert_is_name_already_exists(): void
    {
        $first = $this->fullSchema();
        $second = $this->connect();
        $userId = $this->user($first);
        $a = new AccountRepository($first);
        $b = new AccountRepository($second);

        // Beide pruefen vor dem Schreiben: noch frei.
        $this->assertFalse($a->nameExists('Barkasse'));
        $this->assertFalse($b->nameExists('BARKASSE'));
        $a->create('Barkasse', 'cash', '0', '2026-01-01', $userId);

        try {
            $b->create('BARKASSE', 'cash', '0', '2026-01-01', $userId);
            $this->fail('NameAlreadyExists erwartet');
        } catch (NameAlreadyExists) {
        }
        $this->assertFalse($second->inTransaction());
        $this->assertSame(1, (int) $first->query('SELECT count(*) FROM accounts')->fetchColumn());
        $this->assertSame(1, (int) $first->query("SELECT count(*) FROM audit_log WHERE action = 'account_created'")->fetchColumn());
    }

    public function test_category_create_and_rename_race_map_to_name_already_exists(): void
    {
        $first = $this->fullSchema();
        $second = $this->connect();
        $userId = $this->user($first);
        $a = new CategoryRepository($first);
        $b = new CategoryRepository($second);

        $porto = $a->create('Porto', $userId);
        $spenden = $b->create('Spenden', $userId);

        try {
            $b->create('PORTO', $userId);
            $this->fail('NameAlreadyExists erwartet');
        } catch (NameAlreadyExists) {
        }
        try {
            $b->rename($spenden, 'porto', $userId);
            $this->fail('NameAlreadyExists erwartet');
        } catch (NameAlreadyExists) {
        }
        $this->assertSame(
            ['Porto', 'Spenden'],
            $first->query('SELECT name FROM categories ORDER BY name')->fetchAll(PDO::FETCH_COLUMN)
        );
        $this->assertSame(
            ['category_created', 'category_created'],
            $first->query('SELECT action FROM audit_log ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)
        );
        $this->assertGreaterThan(0, $porto);
    }

    // --- Controller ---------------------------------------------------------------------------------------

    /** Wettlauf auf Controller-Ebene: die Anwendungspruefung (nameExists) sieht den Namen noch nicht. */
    public function test_controllers_answer_422_not_500_when_the_name_check_races(): void
    {
        $real = $this->fullSchema();
        $this->user($real);
        $real->exec("INSERT INTO accounts (name, type, opening_date) VALUES ('Barkasse', 'cash', '2026-01-01')");
        $real->exec("INSERT INTO categories (name) VALUES ('Porto'), ('Spenden')");
        $real->exec("INSERT INTO user_roles (user_id, role) VALUES (1, 'vorstand')");
        $real->exec("UPDATE users SET password_hash = '" . password_hash('richtig-richtig', PASSWORD_DEFAULT) . "'");

        $blind = new class (
            getenv('VK_TEST_DSN') ?: 'pgsql:host=localhost;dbname=vereinskiste_test',
            getenv('VK_TEST_USER') ?: 'vk',
            getenv('VK_TEST_PASS') ?: 'vk',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        ) extends PDO {
            /** Name pruefen liefert immer "frei" (simuliert die Luecke zwischen Pruefung und Schreiben). */
            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                if (str_contains($query, 'lower(name) = lower(?)')) {
                    return parent::prepare('SELECT 1 WHERE ?::text IS NULL AND ?::int IS NULL', $options);
                }
                return parent::prepare($query, $options);
            }
        };

        $store = new ArraySessionStore();
        $app = Kernel::create(['pdo' => $blind, 'session_store' => $store]);
        $factory = new ServerRequestFactory();
        $post = static fn (string $path, array $body): ResponseInterface => $app->handle(
            $factory->createServerRequest('POST', $path)->withParsedBody($body)
        );
        $csrf = static function () use ($app, $factory): string {
            $html = (string) $app->handle($factory->createServerRequest('GET', '/login'))->getBody();
            if ($html === '') { // angemeldet: /login leitet um
                $html = (string) $app->handle($factory->createServerRequest('GET', '/'))->getBody();
            }
            preg_match('/name="_csrf" value="([^"]+)"/', $html, $m);
            return $m[1];
        };
        $login = $post('/login', ['_csrf' => $csrf(), 'email' => 'v@example.org', 'password' => 'richtig-richtig']);
        $this->assertSame(302, $login->getStatusCode());

        $res = $post('/accounts', [
            '_csrf' => $csrf(), 'name' => 'BARKASSE', 'type' => 'cash', 'opening_balance' => '0', 'opening_date' => '2026-01-01',
        ]);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Dieses Konto gibt es bereits.', (string) $res->getBody());

        $res = $post('/categories', ['_csrf' => $csrf(), 'name' => 'PORTO']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Diese Kategorie gibt es bereits.', (string) $res->getBody());

        $res = $post('/categories/2', ['_csrf' => $csrf(), 'name' => 'porto']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Diese Kategorie gibt es bereits.', (string) $res->getBody());

        $this->assertSame(1, (int) $real->query('SELECT count(*) FROM accounts')->fetchColumn());
        $this->assertSame(2, (int) $real->query('SELECT count(*) FROM categories')->fetchColumn());
    }
}
