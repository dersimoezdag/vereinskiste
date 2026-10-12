<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Http;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Vereinskiste\Http\Kernel;

final class HealthTest extends TestCase
{
    public function test_health_returns_200_ok_text(): void
    {
        $app = Kernel::create([]);
        $res = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/health'));

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('ok', (string) $res->getBody());
        $this->assertStringStartsWith('text/plain', $res->getHeaderLine('Content-Type'));
    }
}
