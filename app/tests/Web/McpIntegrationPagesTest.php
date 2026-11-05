<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Web;

use Slim\Psr7\Factory\ServerRequestFactory;
use Vereinskiste\Tests\Support\IntegrationHarness;
use Vereinskiste\Tests\TestCase;

final class McpIntegrationPagesTest extends TestCase
{
    use IntegrationHarness;

    public function test_lifecycle_discovery_and_direct_calls_share_scope_enforcement(): void
    {
        $token = $this->client(['bookings.read'])['token'];
        $init = $this->rpc($token, 'initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => (object) ['name' => 'Test', 'version' => '1']]);
        $this->assertSame(200, $init->getStatusCode());
        $this->assertSame('2025-11-25', $this->data($init)['result']['protocolVersion']);
        $this->assertArrayHasKey('tools', $this->data($init)['result']['capabilities']);
        $list = $this->data($this->rpc($token, 'tools/list'));
        $this->assertSame(['bookings_list', 'bookings_get'], array_column($list['result']['tools'], 'name'));
        $ok = $this->data($this->rpc($token, 'tools/call', ['name' => 'bookings_list', 'arguments' => (object) []]));
        $this->assertFalse($ok['result']['isError']);
        $denied = $this->data($this->rpc($token, 'tools/call', ['name' => 'users_create', 'arguments' => (object) []]));
        $this->assertTrue($denied['result']['isError']);
        $this->assertStringContainsString('scope_denied', $denied['result']['content'][0]['text']);
        $this->assertSame(-32601, $this->data($this->rpc($token, 'resources/list'))['error']['code']);
        $this->assertSame(-32602, $this->data($this->rpc($token, 'tools/call', ['name' => 'bookings_list', 'arguments' => []]))['error']['code']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM users')->fetchColumn());
    }

    public function test_transport_validates_origin_headers_and_notifications(): void
    {
        $token = $this->client()['token'];
        $this->assertSame(401, $this->api('POST', '/mcp', null, ['jsonrpc' => '2.0'])->getStatusCode());
        $this->assertSame(405, $this->api('GET', '/mcp', $token)->getStatusCode());
        $this->assertSame(406, $this->api('POST', '/mcp', $token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->getStatusCode());
        $this->assertSame(400, $this->api('POST', '/mcp', $token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => 'unsupported'])->getStatusCode());
        $notification = $this->api('POST', '/mcp', $token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], ['Accept' => 'application/json, text/event-stream']);
        $this->assertSame(202, $notification->getStatusCode());
        $this->assertSame('', (string) $notification->getBody());
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/mcp')->withHeader('Authorization', 'Bearer ' . $token)->withHeader('Content-Type', 'application/json');
        $request->getBody()->write('{broken');
        $error = $this->app->handle($request);
        $this->assertSame(-32700, $this->data($error)['error']['code']);
    }
}
