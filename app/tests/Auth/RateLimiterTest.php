<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Auth;

use Vereinskiste\Auth\RateLimiter;
use Vereinskiste\Tests\TestCase;

final class RateLimiterTest extends TestCase
{
    public function test_limits_are_shared_across_instances_and_email_spellings_then_expire(): void
    {
        $now = 1000;
        $first = new RateLimiter($this->securityDir(), static fn (): int => $now);
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($first->take('login', 'User@example.org', '127.0.0.1'));
        }
        $second = new RateLimiter($this->securityDir(), static function () use (&$now): int { return $now; });
        $this->assertFalse($second->take('login', ' user@EXAMPLE.org ', '192.0.2.1'));
        $this->assertTrue($second->take('login', 'other@example.org', '127.0.0.1'));
        $now += 61;
        $this->assertTrue($second->take('login', 'user@example.org', '127.0.0.1'));
        $this->assertStringNotContainsString('example.org', file_get_contents($this->securityDir() . '/rate-limits.json'));
        $this->assertStringNotContainsString('127.0.0.1', file_get_contents($this->securityDir() . '/rate-limits.json'));
    }

    public function test_ip_and_global_limits_apply_to_distinct_accounts(): void
    {
        $limiter = new RateLimiter($this->securityDir());
        for ($i = 0; $i < 60; $i++) { $this->assertTrue($limiter->take('login', "u$i@example.org", '192.0.2.1')); }
        $this->assertFalse($limiter->take('login', 'new@example.org', '::ffff:192.0.2.1'));
        for ($i = 60; $i < 120; $i++) { $this->assertTrue($limiter->take('login', "u$i@example.org", '192.0.2.2')); }
        $this->assertFalse($limiter->take('login', 'new@example.org', '192.0.2.3'));
    }

    public function test_corrupt_storage_fails_closed(): void
    {
        mkdir($this->securityDir());
        file_put_contents($this->securityDir() . '/rate-limits.json', '{bad');
        $this->expectException(\JsonException::class);
        (new RateLimiter($this->securityDir()))->take('login', 'u@example.org', '127.0.0.1');
    }

    public function test_parallel_processes_cannot_exceed_account_budget(): void
    {
        $children = [];
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $code = 'require $argv[1]; $limiter = new Vereinskiste\\Auth\\RateLimiter($argv[2]);
            exit($limiter->take("login", "u@example.org", "127.0.0.1") ? 0 : 2);';
        for ($i = 0; $i < 12; $i++) {
            $children[] = proc_open([PHP_BINARY, '-r', $code, $autoload, $this->securityDir()],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        }
        $admitted = 0;
        foreach ($children as $child) {
            $status = proc_close($child);
            $this->assertContains($status, [0, 2]);
            $admitted += $status === 0 ? 1 : 0;
        }
        $this->assertSame(5, $admitted);
    }
}
