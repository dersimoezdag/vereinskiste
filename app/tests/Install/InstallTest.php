<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Install;

use PDO;
use RuntimeException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Install\Installer;
use Vereinskiste\Install\SystemCheck;
use Vereinskiste\Install\Updater;
use Vereinskiste\Tests\TestCase;

final class InstallTest extends TestCase
{
    private string $dir;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        $this->dir = sys_get_temp_dir() . '/vk_install_' . bin2hex(random_bytes(8));
        mkdir($this->dir);
        foreach (['config', 'storage', 'migrations'] as $folder) { mkdir($this->dir . '/' . $folder); }
        file_put_contents($this->dir . '/config/setup.key', str_repeat('k', 48));
        foreach (glob(dirname(__DIR__, 2) . '/migrations/*.sql') as $file) {
            copy($file, $this->dir . '/migrations/' . basename($file));
        }
    }

    protected function tearDown(): void
    {
        foreach (['config', 'storage', 'migrations'] as $folder) {
            foreach (glob($this->dir . '/' . $folder . '/*') as $file) { unlink($file); }
            rmdir($this->dir . '/' . $folder);
        }
        rmdir($this->dir);
    }

    private function db(): array
    {
        return ['dsn' => getenv('VK_TEST_DSN'), 'user' => getenv('VK_TEST_USER'), 'pass' => getenv('VK_TEST_PASS')];
    }

    private function install(?array $db = null): void
    {
        (new Installer($this->dir))->install($db ?? $this->db(),
            ['name' => 'Testverein', 'base_url' => 'https://verein.example.org'],
            ['name' => 'Vorstand', 'email' => 'vorstand@example.org', 'password' => 'sicheres-passwort-123'],
            null, hash('sha256', str_repeat('k', 48)));
    }

    public function test_install_creates_admin_config_and_lock(): void
    {
        $this->install();
        $this->assertFileExists($this->dir . '/config/installed.lock');
        $this->assertFileDoesNotExist($this->dir . '/config/setup.key');
        $config = require $this->dir . '/config/config.php';
        $this->assertSame('https://verein.example.org', $config['base_url']);
        $this->assertSame('admin', $this->pdo->query('SELECT role FROM user_roles')->fetchColumn());
        $this->assertSame('Testverein', $this->pdo->query("SELECT value FROM settings WHERE key = 'club_name'")->fetchColumn());
        $this->assertTrue(password_verify('sicheres-passwort-123', $this->pdo->query('SELECT password_hash FROM users')->fetchColumn()));
        $this->expectException(RuntimeException::class);
        $this->install();
    }

    public function test_lock_alone_refuses_reinstallation(): void
    {
        file_put_contents($this->dir . '/config/installed.lock', 'installed');
        $this->expectException(RuntimeException::class);
        $this->install();
    }

    public function test_owner_proof_is_required_before_any_database_connection(): void
    {
        try {
            (new Installer($this->dir))->install(['dsn' => 'invalid'], [], [], null, 'wrong-proof');
            $this->fail('Eigentumsnachweis erforderlich');
        } catch (RuntimeException $e) {
            $this->assertSame('Einrichtung nicht autorisiert.', $e->getMessage());
            $this->assertNull($this->pdo->query("SELECT to_regclass('public.users')")->fetchColumn());
            $this->assertFileDoesNotExist($this->dir . '/config/config.php');
        }
    }

    public function test_public_install_form_does_not_bypass_unlock_or_expose_key(): void
    {
        $store = new ArraySessionStore();
        $app = Kernel::createInstaller(['require_https' => false, 'security_dir' => $this->securityDir(),
            'app_dir' => $this->dir, 'session_store' => $store]);
        $factory = new ServerRequestFactory();
        $html = (string) $app->handle($factory->createServerRequest('GET', '/install'))->getBody();
        $this->assertStringNotContainsString('name="db_host"', $html);
        $this->assertStringNotContainsString(str_repeat('k', 48), $html);
        preg_match('/name="_csrf" value="([^"]+)"/', $html, $csrf);
        $this->assertSame(403, $app->handle($factory->createServerRequest('POST', '/install')
            ->withParsedBody(['_csrf' => $csrf[1], 'db_host' => 'attacker.example.org']))->getStatusCode());
        $this->assertSame(403, $app->handle($factory->createServerRequest('POST', '/install/unlock')
            ->withParsedBody(['_csrf' => $csrf[1], 'setup_key' => 'wrong']))->getStatusCode());
        $app->handle($factory->createServerRequest('POST', '/install/unlock')
            ->withParsedBody(['_csrf' => $csrf[1], 'setup_key' => str_repeat('k', 48)]));
        $this->assertNotSame(str_repeat('k', 48), $store->get('setup_proof'));
        file_put_contents($this->dir . '/config/setup.key', str_repeat('r', 48));
        $this->assertSame(403, $app->handle($factory->createServerRequest('POST', '/install')
            ->withParsedBody(['_csrf' => $csrf[1]]))->getStatusCode());
    }

    public function test_wrong_credentials_change_nothing(): void
    {
        try {
            $this->install(['dsn' => 'pgsql:host=db;dbname=database_that_does_not_exist', 'user' => 'vk', 'pass' => 'wrong']);
            $this->fail('Verbindungsfehler erwartet');
        } catch (\PDOException) {
            $this->assertFileDoesNotExist($this->dir . '/config/config.php');
            $this->assertNull($this->pdo->query("SELECT to_regclass('public.users')")->fetchColumn());
        }
    }

    public function test_failed_install_rolls_back_all_migrations_and_keeps_config_absent(): void
    {
        file_put_contents($this->dir . '/migrations/0900_fail.sql', 'THIS IS NOT SQL;');
        try { $this->install(); $this->fail('Fehler erwartet'); } catch (RuntimeException) {
            $this->assertNull($this->pdo->query("SELECT to_regclass('public.users')")->fetchColumn());
            $this->assertFileDoesNotExist($this->dir . '/config/config.php');
            $this->assertSame([], glob($this->dir . '/config/*.tmp'));
        }
    }

    public function test_system_check_reports_missing_directory(): void
    {
        rmdir($this->dir . '/storage');
        $checks = array_column((new SystemCheck($this->dir))->run(), null, 'name');
        $this->assertFalse($checks['storage']['ok']);
        $this->assertNotSame('', $checks['storage']['message']);
        mkdir($this->dir . '/storage');
    }

    public function test_system_check_refuses_private_application_inside_document_root(): void
    {
        $checks = array_column((new SystemCheck($this->dir, dirname($this->dir)))->run(), null, 'name');
        $this->assertFalse($checks['Webroot']['ok']);
        $checks = array_column((new SystemCheck($this->dir, $this->dir . '/migrations'))->run(), null, 'name');
        $this->assertTrue($checks['Webroot']['ok']);
    }

    public function test_installer_page_renders_without_database_and_redirects_other_paths(): void
    {
        $app = Kernel::createInstaller(['require_https' => false, 'security_dir' => $this->securityDir(), 'app_dir' => $this->dir, 'session_store' => new ArraySessionStore()]);
        $factory = new ServerRequestFactory();
        $response = $app->handle($factory->createServerRequest('GET', '/install'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('name="_csrf"', (string) $response->getBody());
        $this->assertSame('/install', $app->handle($factory->createServerRequest('GET', '/bookings'))->getHeaderLine('Location'));
        $this->assertSame(403, $app->handle($factory->createServerRequest('POST', '/install')->withParsedBody([]))->getStatusCode());
    }

    public function test_updater_requires_backup_and_rolls_back_complete_batch(): void
    {
        (new Migrator($this->pdo, $this->dir . '/migrations'))->migrate();
        $previousVersion = (new Migrator($this->pdo, $this->dir . '/migrations'))->currentVersion();
        file_put_contents($this->dir . '/migrations/0900_good.sql', 'CREATE TABLE new_table (id int);');
        file_put_contents($this->dir . '/migrations/0901_bad.sql', 'THIS IS NOT SQL;');
        $updater = new Updater($this->pdo, $this->dir . '/migrations');
        $this->assertTrue($updater->needsUpdate());
        try { $updater->run(false); $this->fail('Backup erforderlich'); } catch (RuntimeException) {
            $this->assertNull($this->pdo->query("SELECT to_regclass('public.new_table')")->fetchColumn());
        }
        try { $updater->run(true); $this->fail('Migrationsfehler erwartet'); } catch (RuntimeException) {
            $this->assertNull($this->pdo->query("SELECT to_regclass('public.new_table')")->fetchColumn());
            $this->assertSame($previousVersion, (new Migrator($this->pdo, $this->dir . '/migrations'))->currentVersion());
        }
        unlink($this->dir . '/migrations/0901_bad.sql');
        $this->assertSame(['0900_good.sql'], $updater->run(true));
        $this->assertFalse($updater->needsUpdate());
    }

    public function test_install_form_creates_a_working_configuration(): void
    {
        $store = new ArraySessionStore();
        $app = Kernel::createInstaller(['require_https' => false, 'security_dir' => $this->securityDir(), 'app_dir' => $this->dir, 'session_store' => $store]);
        $factory = new ServerRequestFactory();
        $html = (string) $app->handle($factory->createServerRequest('GET', '/install'))->getBody();
        preg_match('/name="_csrf" value="([^"]+)"/', $html, $csrf);
        $this->assertSame(302, $app->handle($factory->createServerRequest('POST', '/install/unlock')
            ->withParsedBody(['_csrf' => $csrf[1], 'setup_key' => str_repeat('k', 48)]))->getStatusCode());
        $html = (string) $app->handle($factory->createServerRequest('GET', '/install'))->getBody();
        preg_match('/name="_csrf" value="([^"]+)"/', $html, $csrf);
        preg_match('/dbname=([^;]+)/', getenv('VK_TEST_DSN'), $dbName);
        preg_match('/host=([^;]+)/', getenv('VK_TEST_DSN'), $dbHost);
        $body = ['_csrf' => $csrf[1], 'db_host' => $dbHost[1], 'db_port' => '5432',
            'db_name' => $dbName[1], 'db_user' => getenv('VK_TEST_USER'), 'db_password' => getenv('VK_TEST_PASS'),
            'club_name' => 'Testverein', 'base_url' => 'https://verein.example.org',
            'admin_name' => 'Vorstand', 'admin_email' => 'admin@example.org', 'admin_password' => 'sicheres-passwort-123'];
        $response = $app->handle($factory->createServerRequest('POST', '/install')->withParsedBody($body));
        $this->assertSame('/login', $response->getHeaderLine('Location'));
        $config = require $this->dir . '/config/config.php';
        $normal = Kernel::create($config + ['require_https' => false, 'security_dir' => $this->securityDir(), 'session_store' => new ArraySessionStore(), 'maintenance' => true]);
        $this->assertSame(200, $normal->handle($factory->createServerRequest('GET', '/login'))->getStatusCode());
    }

    public function test_newer_database_stays_in_maintenance_and_refuses_update(): void
    {
        (new Migrator($this->pdo, $this->dir . '/migrations'))->migrate();
        $this->pdo->exec("INSERT INTO schema_migrations (name) VALUES ('9999_future.sql')");
        $updater = new Updater($this->pdo, $this->dir . '/migrations');
        $this->assertFalse($updater->isCompatible());
        $this->assertTrue($updater->needsUpdate());
        $app = Kernel::create(['require_https' => false, 'security_dir' => $this->securityDir(), 'pdo' => $this->pdo, 'session_store' => new ArraySessionStore(),
            'maintenance' => true, 'migration_dir' => $this->dir . '/migrations']);
        $this->assertSame(503, $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/bookings'))->getStatusCode());
        $this->expectException(RuntimeException::class);
        $updater->run(true);
    }

    public function test_maintenance_blocks_business_routes_but_update_requires_vorstand(): void
    {
        (new Migrator($this->pdo, $this->dir . '/migrations'))->migrate();
        file_put_contents($this->dir . '/migrations/0900_pending.sql', 'CREATE TABLE after_update (id int);');
        $store = new ArraySessionStore();
        $app = Kernel::create(['require_https' => false, 'security_dir' => $this->securityDir(), 'pdo' => $this->pdo, 'session_store' => $store, 'maintenance' => true,
            'migration_dir' => $this->dir . '/migrations']);
        $factory = new ServerRequestFactory();
        $this->assertSame(503, $app->handle($factory->createServerRequest('GET', '/bookings'))->getStatusCode());
        $this->assertSame(200, $app->handle($factory->createServerRequest('GET', '/login'))->getStatusCode());
        $this->assertSame('/login', $app->handle($factory->createServerRequest('GET', '/settings'))->getHeaderLine('Location'));
        $this->assertSame('/login', $app->handle($factory->createServerRequest('GET', '/update'))->getHeaderLine('Location'));
        $id = (new \Vereinskiste\Auth\UserRepository($this->pdo))->create('Leser', 'reader@example.org', 'hash', ['kassenpruefer'], null);
        $store->set('user_id', $id);
        $store->set('credential_hash', hash('sha256', 'hash'));
        $this->assertSame(403, $app->handle($factory->createServerRequest('GET', '/update'))->getStatusCode());
        $this->assertSame(403, $app->handle($factory->createServerRequest('GET', '/settings'))->getStatusCode());
        $this->pdo->exec("UPDATE user_roles SET role = 'vorstand'");
        $this->assertSame(200, $app->handle($factory->createServerRequest('GET', '/update'))->getStatusCode());
        $settings = $app->handle($factory->createServerRequest('GET', '/settings'));
        $this->assertSame(200, $settings->getStatusCode());
        $this->assertStringContainsString('Installierte Version', (string) $settings->getBody());
        $this->assertStringContainsString('action="/settings/update"', (string) $settings->getBody());
    }
}
