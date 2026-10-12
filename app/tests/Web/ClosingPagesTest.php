<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\ClosingService;
use Vereinskiste\Tests\TestCase;

final class ClosingPagesTest extends TestCase
{
    use WebHarness;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->bootApp();
    }

    private function closedUntil(): ?string
    {
        return (new ClosingService($this->pdo))->closedUntil();
    }

    private function audit(string $action, ?int $userId, string $detail = '{}', string $at = 'now()'): void
    {
        $this->pdo->prepare("INSERT INTO audit_log (user_id, action, detail, at) VALUES (?, ?, ?::jsonb, $at)")
            ->execute([$userId, $action, $detail]);
    }

    public function test_closing_page_shows_state_and_forms(): void
    {
        $this->loginAs(['vorstand']);

        $html = (string) $this->get('/settings/closing')->getBody();
        $this->assertStringContainsString('Es ist noch kein Zeitraum abgeschlossen', $html);
        $this->assertStringContainsString('name="_csrf"', $html);
        $this->assertStringContainsString('name="closed_until"', $html);

        (new ClosingService($this->pdo))->close('2026-03-31', 1);
        $html = (string) $this->get('/settings/closing')->getBody();
        $this->assertStringContainsString('31.03.2026', $html);
        $this->assertStringContainsString('name="reason"', $html);
    }

    public function test_vorstand_closes_and_reopens_with_reason(): void
    {
        $userId = $this->loginAs(['vorstand']);

        $res = $this->postForm('/settings/closing', ['action' => 'close', 'closed_until' => '2026-03-31']);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('2026-03-31', $this->closedUntil());

        $res = $this->postForm('/settings/closing', ['action' => 'reopen', 'closed_until' => '2026-02-28', 'reason' => '   ']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame('2026-03-31', $this->closedUntil());
        $this->assertStringContainsString('Grund', (string) $res->getBody());

        $res = $this->postForm('/settings/closing', ['action' => 'reopen', 'closed_until' => '2026-02-28', 'reason' => 'Beleg fehlte']);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('2026-02-28', $this->closedUntil());

        $res = $this->postForm('/settings/closing', ['action' => 'reopen', 'closed_until' => '', 'reason' => 'Alles offen']);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertNull($this->closedUntil());

        $actions = $this->pdo->query("SELECT action FROM audit_log WHERE action LIKE 'period_%' ORDER BY id")
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['period_closed', 'period_reopened', 'period_reopened'], $actions);
        $this->assertSame(
            [$userId],
            array_map('intval', $this->pdo->query("SELECT DISTINCT user_id FROM audit_log WHERE action LIKE 'period_%'")
                ->fetchAll(PDO::FETCH_COLUMN))
        );
    }

    public function test_invalid_close_is_rendered_with_error_and_keeps_state(): void
    {
        $this->loginAs(['vorstand']);
        $this->postForm('/settings/closing', ['action' => 'close', 'closed_until' => '2026-03-31']);

        $res = $this->postForm('/settings/closing', ['action' => 'close', 'closed_until' => '2026-03-01']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Wiederöffnen', (string) $res->getBody());

        $res = $this->postForm('/settings/closing', ['action' => 'close', 'closed_until' => '2999-01-01']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame('2026-03-31', $this->closedUntil());

        $res = $this->postForm('/settings/closing', ['action' => 'unknown']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function test_closing_requires_csrf(): void
    {
        $this->loginAs(['vorstand']);
        $res = $this->app->handle(
            (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/settings/closing')
                ->withParsedBody(['action' => 'close', 'closed_until' => '2026-03-31'])
        );

        $this->assertSame(403, $res->getStatusCode());
        $this->assertNull($this->closedUntil());
    }

    public function test_nav_entries_only_for_settings_manage(): void
    {
        $this->loginAs(['kassenwart'], 'kw@example.org');
        $html = (string) $this->get('/')->getBody();
        $this->assertStringNotContainsString('href="/settings/closing"', $html);
        $this->assertStringNotContainsString('href="/audit-log"', $html);

        $this->webUser('vo@example.org', ['vorstand'], 'Vera Vorstand');
        $this->app->handle(
            (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/logout')
                ->withParsedBody(['_csrf' => $this->csrf()])
        );
        $res = $this->app->handle(
            (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/login')->withParsedBody([
                '_csrf' => $this->csrf(), 'email' => 'vo@example.org', 'password' => 'richtig-richtig',
            ])
        );
        $this->assertSame(302, $res->getStatusCode());
        $html = (string) $this->get('/')->getBody();
        $this->assertStringContainsString('href="/settings/closing"', $html);
        $this->assertStringContainsString('href="/audit-log"', $html);
    }

    public function test_audit_log_lists_newest_first_with_labels_and_readable_detail(): void
    {
        $userId = $this->loginAs(['vorstand'], 'vo@example.org', 'Vera Vorstand');
        $this->audit('period_closed', $userId, '{"from": null, "to": "2026-03-31"}', "now() + interval '1 hour'");
        $this->audit('period_reopened', $userId, '{"from": "2026-03-31", "to": "2026-02-28", "reason": "Beleg <fehlte>"}', "now() + interval '2 hours'");

        $res = $this->get('/audit-log');
        $html = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('Vera Vorstand', $html);
        $this->assertStringContainsString('Abschluss aufgehoben', $html);
        $this->assertStringContainsString('Zeitraum abgeschlossen', $html);
        $this->assertStringContainsString('Anmeldung', $html);
        $this->assertStringContainsString('28.02.2026', $html);
        $this->assertStringContainsString('Beleg &lt;fehlte&gt;', $html);
        $cell = static fn (string $label): int => (int) strpos($html, '<td data-label="Aktion">' . $label . '</td>');
        $this->assertGreaterThan(0, $cell('Abschluss aufgehoben'));
        $this->assertLessThan($cell('Zeitraum abgeschlossen'), $cell('Abschluss aufgehoben'));
        $this->assertLessThan($cell('Anmeldung'), $cell('Zeitraum abgeschlossen'));
    }

    public function test_audit_log_filters_by_known_action_and_ignores_unknown(): void
    {
        $userId = $this->loginAs(['vorstand'], 'vo@example.org', 'Vera Vorstand');
        $this->audit('period_closed', $userId, '{"from": null, "to": "2026-03-31"}');

        $html = (string) $this->get('/audit-log?action=period_closed')->getBody();
        $this->assertStringContainsString('<td data-label="Aktion">Zeitraum abgeschlossen</td>', $html);
        $this->assertStringNotContainsString('<td data-label="Aktion">Anmeldung</td>', $html);

        $html = (string) $this->get('/audit-log?action=%27%3B%20DROP%20TABLE%20audit_log')->getBody();
        $this->assertStringContainsString('<td data-label="Aktion">Zeitraum abgeschlossen</td>', $html);
        $this->assertStringContainsString('<td data-label="Aktion">Anmeldung</td>', $html);
    }

    public function test_audit_log_shows_no_personal_data_for_failed_logins(): void
    {
        $this->loginAs(['vorstand'], 'vo@example.org', 'Vera Vorstand');
        $this->audit('login_failed', null, '{"email": "fremd@example.org", "ip": "203.0.113.9"}');

        $html = (string) $this->get('/audit-log?action=login_failed')->getBody();

        $this->assertStringContainsString('<td data-label="Aktion">Fehlgeschlagene Anmeldung</td>', $html);
        $this->assertStringNotContainsString('fremd@example.org', $html);
        $this->assertStringNotContainsString('203.0.113.9', $html);
    }

    public function test_audit_log_is_paged(): void
    {
        $this->loginAs(['vorstand'], 'vo@example.org', 'Vera Vorstand');
        for ($i = 0; $i < 60; $i++) {
            $this->audit('period_closed', null, '{"from": null, "to": "2026-03-31"}', "now() - interval '$i minutes'");
        }

        $first = (string) $this->get('/audit-log?action=period_closed')->getBody();
        $second = (string) $this->get('/audit-log?action=period_closed&page=2')->getBody();

        $this->assertSame(50, substr_count($first, '<td data-label="Aktion">Zeitraum abgeschlossen</td>'));
        $this->assertSame(10, substr_count($second, '<td data-label="Aktion">Zeitraum abgeschlossen</td>'));
        $this->assertStringContainsString('page=2', $first);
        $this->assertStringContainsString('action=period_closed', $first);
    }
}
