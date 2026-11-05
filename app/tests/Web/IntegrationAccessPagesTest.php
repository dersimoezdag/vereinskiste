<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Web;

use Slim\Psr7\Factory\ServerRequestFactory;
use Vereinskiste\Integrations\Clients;
use Vereinskiste\Tests\Support\IntegrationHarness;
use Vereinskiste\Tests\TestCase;

final class IntegrationAccessPagesTest extends TestCase
{
    use IntegrationHarness;

    public function test_settings_create_a_secret_shown_once_and_only_store_a_hash(): void
    {
        $res = $this->postForm('/settings/integrations', ['name' => 'WordPress', 'valid_days' => '30', 'scopes' => ['users.create'], 'protocols' => ['api'], 'allowed_roles' => ['auditor']]);
        $this->assertSame('/settings/integrations', $res->getHeaderLine('Location'));
        $page = (string) $this->get('/settings/integrations')->getBody();
        $this->assertSame(1, preg_match('/vk_api_[a-f0-9]{64}/', $page, $match));
        $stored = $this->pdo->query('SELECT * FROM integration_clients')->fetch();
        $this->assertSame(hash('sha256', $match[0]), $stored['token_hash']);
        $this->assertStringNotContainsString($match[0], json_encode($stored));
        $this->assertStringNotContainsString($match[0], (string) $this->get('/settings/integrations')->getBody());
        foreach (['/settings/integrations/connection', '/settings/integrations/activity'] as $path) { $this->assertSame(200, $this->get($path)->getStatusCode()); }
        $this->assertStringNotContainsString($match[0], json_encode($this->pdo->query('SELECT * FROM audit_log')->fetchAll()));
    }

    public function test_form_csrf_and_role_gates_still_apply(): void
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/settings/integrations')->withParsedBody(['name' => 'Bad']);
        $this->assertSame(403, $this->app->handle($req)->getStatusCode());
        $this->pdo->exec("UPDATE user_roles SET role='treasurer'");
        $this->assertSame(403, $this->get('/settings/integrations')->getStatusCode());
        $this->assertSame(403, $this->postForm('/settings/integrations', [])->getStatusCode());
    }

    public function test_api_ignores_session_login_and_enforces_protocol_revocation_and_expiry(): void
    {
        $this->assertSame(401, $this->api('GET', '/api/v1/capabilities')->getStatusCode());
        $this->assertSame(401, $this->api('POST', '/api/v1/users', null, ['name' => 'No bearer'])->getStatusCode());
        $client = $this->client(['bookings.read'], ['api']);
        $this->assertSame(200, $this->api('GET', '/api/v1/capabilities', $client['token'])->getStatusCode());
        $spec = $this->data($this->api('GET', '/api/v1/openapi.json', $client['token']));
        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertArrayHasKey('/bookings', $spec['paths']);
        $this->assertArrayNotHasKey('/users', $spec['paths']);
        $this->assertSame(404, $this->api('GET', '/api/v1/unknown', $client['token'])->getStatusCode());
        $this->store->destroy();
        $this->assertSame(200, $this->api('GET', '/api/v1/capabilities', $client['token'])->getStatusCode());
        $this->assertSame('/login', $this->get('/settings/integrations')->getHeaderLine('Location'));
        $this->assertSame(403, $this->rpc($client['token'], 'tools/list')->getStatusCode());
        $this->assertTrue((new Clients($this->pdo))->revoke($client['id'], $this->owner));
        $this->assertSame(401, $this->api('GET', '/api/v1/capabilities', $client['token'])->getStatusCode());
        $expired = $this->client();
        $this->pdo->prepare("UPDATE integration_clients SET expires_at=now()-interval '1 second' WHERE id=?")->execute([$expired['id']]);
        $this->assertSame(401, $this->api('GET', '/api/v1/capabilities', $expired['token'])->getStatusCode());
    }

    public function test_rate_limit_is_reported_as_json_with_retry_header(): void
    {
        $token = $this->client()['token'];
        for ($i = 0; $i < 120; $i++) { $this->assertSame(200, $this->api('GET', '/api/v1/capabilities', $token)->getStatusCode()); }
        $res = $this->api('GET', '/api/v1/capabilities', $token);
        $this->assertSame(429, $res->getStatusCode());
        $this->assertSame('60', $res->getHeaderLine('Retry-After'));
        $this->assertSame('rate_limited', $this->data($res)['error']['code']);
    }

    public function test_owner_permissions_are_rechecked_and_inactive_owners_disable_access(): void
    {
        $client = $this->client(['users.read', 'bookings.read']);
        $this->pdo->exec("UPDATE user_roles SET role='treasurer'");
        $this->assertSame(403, $this->api('GET', '/api/v1/users', $client['token'])->getStatusCode());
        $this->assertSame(200, $this->api('GET', '/api/v1/bookings', $client['token'])->getStatusCode());
        $this->pdo->exec('UPDATE users SET active=false');
        $this->assertSame(401, $this->api('GET', '/api/v1/capabilities', $client['token'])->getStatusCode());
    }

    public function test_origin_payload_and_scope_validation_fail_closed(): void
    {
        $token = $this->client()['token'];
        $this->assertSame(403, $this->api('GET', '/api/v1/capabilities', $token, null, ['Origin' => 'https://evil.example'])->getStatusCode());
        $this->assertSame(415, $this->api('POST', '/api/v1/users', $token, [], ['Content-Type' => 'text/plain'])->getStatusCode());
        $this->assertSame(413, $this->api('POST', '/api/v1/users', $token, ['name' => str_repeat('a', 65537)])->getStatusCode());
        foreach ([['scopes' => ['users.manage']], ['allowed_roles' => ['admin']], ['scopes' => ['bookings.review']]] as $override) {
            $res = $this->postForm('/settings/integrations', $override + ['name' => 'Invalid', 'valid_days' => '30', 'scopes' => ['users.create'], 'protocols' => ['api']]);
            $this->assertSame(422, $res->getStatusCode());
        }
        $this->assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM integration_clients')->fetchColumn());
    }
}
