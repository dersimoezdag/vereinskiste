<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Web;

use Vereinskiste\Integrations\Clients;
use Vereinskiste\Tests\Support\IntegrationHarness;
use Vereinskiste\Tests\TestCase;

final class IntegrationOperationsTest extends TestCase
{
    use IntegrationHarness;

    public function test_user_creation_is_scoped_role_limited_and_idempotent(): void
    {
        $token = $this->client(['users.create'], ['api', 'mcp'], ['auditor'])['token'];
        $input = ['name' => 'New Person', 'email' => 'NEW@example.org', 'roles' => ['auditor'], 'request_id' => 'wordpress-42'];
        $first = $this->api('POST', '/api/v1/users', $token, $input);
        $this->assertSame(201, $first->getStatusCode(), (string) $first->getBody());
        $result = $this->data($first);
        $this->assertSame('new@example.org', $this->pdo->query("SELECT email FROM users WHERE id=" . (int) $result['id'])->fetchColumn());
        $this->assertEquals($result, $this->data($this->api('POST', '/api/v1/users', $token, $input)));
        $replay = $this->data($this->rpc($token, 'tools/call', ['name' => 'users_create', 'arguments' => (object) $input]));
        $this->assertEquals($result, $replay['result']['structuredContent']);
        $this->assertSame(2, (int) $this->pdo->query('SELECT count(*) FROM users')->fetchColumn());
        $this->assertArrayNotHasKey('password_hash', $result);
        $this->assertSame(403, $this->api('GET', '/api/v1/users', $token)->getStatusCode());
        $this->assertSame(409, $this->api('POST', '/api/v1/users', $token, array_replace($input, ['name' => 'Changed']))->getStatusCode());
        $this->assertSame(403, $this->api('POST', '/api/v1/users', $token, array_replace($input, ['roles' => ['treasurer'], 'request_id' => 'new']))->getStatusCode());
        $this->assertSame(422, $this->api('POST', '/api/v1/users', $token, array_replace($input, ['roles' => ['admin'], 'request_id' => 'admin']))->getStatusCode());
        $this->assertSame(422, $this->api('POST', '/api/v1/users', $token, ['name' => 'No ID', 'email' => 'no@example.org'])->getStatusCode());
    }

    public function test_read_only_access_cannot_create_users_or_reviews(): void
    {
        $booking = $this->booking();
        $token = $this->client(['users.read', 'bookings.read'])['token'];
        $list = $this->api('GET', '/api/v1/users?limit=1', $token);
        $this->assertSame(200, $list->getStatusCode());
        $this->assertCount(1, $this->data($list)['items']);
        $this->assertStringNotContainsString('password_hash', (string) $list->getBody());
        $this->assertSame(403, $this->api('POST', '/api/v1/users', $token, ['name' => 'Denied'])->getStatusCode());
        $this->assertSame(403, $this->api('POST', '/api/v1/bookings/' . $booking['id'] . '/reviews', $token, [])->getStatusCode());
        $this->assertSame(422, $this->api('GET', '/api/v1/bookings?limit=101', $token)->getStatusCode());
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM integration_booking_reviews')->fetchColumn());
    }

    public function test_reviews_are_versioned_visible_and_do_not_change_the_booking(): void
    {
        $booking = $this->booking();
        $client = $this->client(['bookings.read', 'bookings.review']);
        $path = '/api/v1/bookings/' . $booking['id'] . '/reviews';
        $input = ['version' => $booking['version'], 'status' => 'needs_review', 'note' => 'Please verify <script>alert(1)</script>', 'request_id' => 'check-1'];
        $result = $this->api('POST', $path, $client['token'], $input);
        $this->assertSame(201, $result->getStatusCode(), (string) $result->getBody());
        $this->assertEquals($this->data($result), $this->data($this->api('POST', $path, $client['token'], $input)));
        $this->assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM integration_booking_reviews')->fetchColumn());
        $this->assertSame($booking, (new \Vereinskiste\Ledger\BookingRepository($this->pdo))->find($booking['id']));
        $this->assertSame(409, $this->api('POST', $path, $client['token'], array_replace($input, ['version' => 99, 'request_id' => 'check-2']))->getStatusCode());
        $page = (string) $this->get('/bookings/' . $booking['id'])->getBody();
        $this->assertStringContainsString('&lt;script&gt;', $page);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $page);
        $this->assertStringContainsString('Test integration', $page);
        $this->pdo->exec('UPDATE bookings SET version=version+1');
        $this->assertStringContainsString('Buchung seitdem geändert', (string) $this->get('/bookings/' . $booking['id'])->getBody());
        $activity = (string) $this->get('/settings/integrations/activity')->getBody();
        $this->assertStringContainsString('bookings_review', $activity);
        $this->assertStringNotContainsString('Please verify', $activity);
        $this->assertStringNotContainsString($client['token'], $activity);
    }
}
