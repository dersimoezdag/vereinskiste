<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Support;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Integrations\Clients;
use Vereinskiste\Tests\Web\WebHarness;

trait IntegrationHarness
{
    use WebHarness;
    private PDO $pdo;
    private int $owner;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->bootApp();
        $this->owner = $this->loginAs(['admin']);
    }

    private function client(array $scopes = ['bookings.read'], array $protocols = ['api', 'mcp'], array $roles = []): array
    {
        return (new Clients($this->pdo))->create(['name' => 'Test integration', 'valid_days' => '90', 'scopes' => $scopes, 'protocols' => $protocols, 'allowed_roles' => $roles], $this->owner);
    }

    private function api(string $method, string $path, ?string $token = null, ?array $input = null, array $headers = []): ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1']);
        if ($token !== null) { $req = $req->withHeader('Authorization', 'Bearer ' . $token); }
        if ($input !== null) { $req->getBody()->write(json_encode((object) $input, JSON_THROW_ON_ERROR)); $req = $req->withHeader('Content-Type', 'application/json'); }
        foreach ($headers as $name => $value) { $req = $req->withHeader($name, $value); }
        return $this->app->handle($req);
    }

    private function rpc(string $token, string $method, array $params = [], int $id = 1): ResponseInterface
    {
        return $this->api('POST', '/mcp', $token, ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params], ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-11-25']);
    }

    private function data(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function booking(): array
    {
        $account = (int) $this->pdo->query("INSERT INTO accounts(name,type,opening_date) VALUES('Cash','cash','2026-01-01') RETURNING id")->fetchColumn();
        return (new \Vereinskiste\Ledger\BookingService($this->pdo))->create(['account_id' => $account, 'booking_date' => '2026-03-05', 'amount' => '12,50', 'direction' => 'in', 'category_id' => '', 'description' => 'Example', 'counterparty' => '', 'receipt_no' => ''], $this->owner);
    }
}
