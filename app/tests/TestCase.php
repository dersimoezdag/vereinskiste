<?php
declare(strict_types=1);

namespace Vereinskiste\Tests;

use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Vereinskiste\Db\Connection;

abstract class TestCase extends BaseTestCase
{
    /** Frisches, leeres Schema pro Aufruf. */
    protected function pdo(): PDO
    {
        $pdo = Connection::fromConfig([
            'dsn' => getenv('VK_TEST_DSN') ?: 'pgsql:host=localhost;dbname=vereinskiste_test',
            'user' => getenv('VK_TEST_USER') ?: 'vk',
            'pass' => getenv('VK_TEST_PASS') ?: 'vk',
        ]);
        $pdo->exec('DROP SCHEMA IF EXISTS public CASCADE');
        $pdo->exec('CREATE SCHEMA public');
        return $pdo;
    }
}
