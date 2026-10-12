<?php
declare(strict_types=1);

namespace Vereinskiste\Tests;

use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Vereinskiste\Db\Connection;

abstract class TestCase extends BaseTestCase
{
    private ?string $securityDirectory = null;

    protected function securityDir(): string
    {
        return $this->securityDirectory ??= sys_get_temp_dir() . '/vk_security_' . bin2hex(random_bytes(12));
    }
    /** Frisches, leeres Schema pro Aufruf. */
    protected function pdo(): PDO
    {
        $pdo = $this->connect();
        $pdo->exec('DROP SCHEMA IF EXISTS public CASCADE');
        $pdo->exec('CREATE SCHEMA public');
        return $pdo;
    }

    /** Weitere Verbindung zur Testdatenbank, ohne das Schema anzufassen (z. B. fuer Nebenlaeufigkeit). */
    protected function connect(): PDO
    {
        return Connection::fromConfig([
            'dsn' => getenv('VK_TEST_DSN') ?: 'pgsql:host=localhost;dbname=vereinskiste_test',
            'user' => getenv('VK_TEST_USER') ?: 'vk',
            'pass' => getenv('VK_TEST_PASS') ?: 'vk',
        ]);
    }
}
