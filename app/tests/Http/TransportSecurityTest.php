<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Http;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;

final class TransportSecurityTest extends TestCase
{
    public function test_https_is_required_before_sessions_or_database_and_proxy_headers_are_ignored(): void
    {
        $store = new ArraySessionStore();
        $app = Kernel::create(['session_store' => $store, 'base_url' => 'https://trusted.example.org']);
        $factory = new ServerRequestFactory();
        $response = $app->handle($factory->createServerRequest('GET', 'http://untrusted.example.org/login')
            ->withHeader('X-Forwarded-Proto', 'https'));
        $this->assertSame(308, $response->getStatusCode());
        $this->assertSame('https://trusted.example.org/login', $response->getHeaderLine('Location'));
        $this->assertSame(403, $app->handle($factory->createServerRequest('POST', 'http://trusted.example.org/login'))
            ->getStatusCode());
        $secure = $app->handle($factory->createServerRequest('GET', 'https://trusted.example.org/login'));
        $this->assertSame(200, $secure->getStatusCode());
        $this->assertSame('max-age=31536000', $secure->getHeaderLine('Strict-Transport-Security'));
        $this->assertStringContainsString("frame-ancestors 'none'", $secure->getHeaderLine('Content-Security-Policy'));
        $this->assertSame('nosniff', $secure->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('no-store, no-transform', $secure->getHeaderLine('Cache-Control'));
    }
}
