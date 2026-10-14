<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Web;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Tests\TestCase;

final class HomeDashboardPagesTest extends TestCase
{
    use WebHarness;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->bootApp();
    }

    public function test_defaults_are_permission_filtered_and_symbol_picker_is_available(): void
    {
        $this->loginAs(['secretary']);
        $html = $this->visibleHome();
        $this->assertStringContainsString('name="cards[]" value="members"', $html);
        $this->assertStringContainsString('name="cards[]" value="applications"', $html);
        $this->assertStringNotContainsString('name="cards[]" value="finance"', $html);
        $this->assertStringNotContainsString('name="cards[]" value="invoices"', $html);
        $this->assertStringNotContainsString('value="booking-add"', $html);
        $this->assertStringContainsString('data-theme-toggle', $html);
        $this->assertStringContainsString('aria-label="Darstellung umschalten"', $html);
        $this->assertSame(200, $this->get('/dashboard/preferences')->getStatusCode());
    }

    public function test_preferences_persist_for_one_user_and_empty_selection_stays_empty(): void
    {
        $id = $this->loginAs(['admin']);
        $res = $this->postForm('/dashboard/preferences', ['version' => '0', 'cards' => ['invoices', 'members'], 'shortcuts' => ['member-invite']]);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/', $res->getHeaderLine('Location'));
        $this->bootApp();
        $this->postForm('/login', ['email' => 'wer@example.org', 'password' => 'richtig-richtig']);
        $html = $this->visibleHome();
        $this->assertLessThan(strpos($html, 'value="members"'), strpos($html, 'value="invoices"'));
        $this->assertStringNotContainsString('value="finance"', $html);
        $this->assertStringContainsString('value="member-invite"', $html);
        $other = $this->webUser('other@example.org', ['admin']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM user_dashboard_preferences WHERE user_id=' . $other)->fetchColumn());
        $this->assertSame(302, $this->postForm('/dashboard/preferences', ['version' => '1'])->getStatusCode());
        $this->assertStringNotContainsString('name="cards[]"', $this->visibleHome());
        $this->assertSame('[]', $this->pdo->query('SELECT cards FROM user_dashboard_preferences WHERE user_id=' . $id)->fetchColumn());
    }

    public function test_forged_permissions_duplicates_and_stale_versions_are_rejected(): void
    {
        $id = $this->loginAs(['secretary']);
        $this->assertSame(422, $this->postForm('/dashboard/preferences', ['version' => '0', 'cards' => ['finance']])->getStatusCode());
        $this->assertSame(422, $this->postForm('/dashboard/preferences', ['version' => '0', 'cards' => ['members', 'members']])->getStatusCode());
        $this->assertSame(422, $this->postForm('/dashboard/preferences', ['version' => 'bad'])->getStatusCode());
        $this->assertSame(302, $this->postForm('/dashboard/preferences', ['version' => '0', 'cards' => ['members'], 'user_id' => '999'])->getStatusCode());
        $this->assertSame(409, $this->postForm('/dashboard/preferences', ['version' => '0'])->getStatusCode());
        $this->assertSame('["members"]', $this->pdo->query('SELECT cards FROM user_dashboard_preferences WHERE user_id=' . $id)->fetchColumn());
        $this->pdo->exec("UPDATE user_roles SET role='auditor' WHERE user_id=" . $id);
        $html = $this->visibleHome();
        $this->assertStringNotContainsString('value="applications"', $html);
        $this->assertStringNotContainsString('value="member-add"', $html);
    }

    public function test_position_inputs_work_without_javascript_and_role_changes_hide_saved_cards(): void
    {
        $id = $this->loginAs(['admin']);
        $this->postForm('/dashboard/preferences', ['version' => '0', 'cards' => ['finance', 'members', 'operations'],
            'cards_positions' => ['finance' => '3', 'members' => '1', 'operations' => '2']]);
        $this->assertSame('["members", "operations", "finance"]', $this->pdo->query('SELECT cards FROM user_dashboard_preferences WHERE user_id=' . $id)->fetchColumn());
        $this->pdo->exec("UPDATE user_roles SET role='secretary' WHERE user_id=" . $id);
        $html = $this->visibleHome();
        $this->assertStringContainsString('value="members"', $html);
        $this->assertStringNotContainsString('value="finance"', $html);
        $this->assertStringNotContainsString('value="operations"', $html);
    }

    private function visibleHome(): string
    {
        return preg_replace('/<template\b[^>]*>.*?<\/template>/s', '', (string) $this->get('/')->getBody());
    }

    public function test_home_has_explicit_editor_and_available_tiles_stay_permission_filtered(): void
    {
        $this->loginAs(['secretary']);
        $this->postForm('/dashboard/preferences', ['version' => '0', 'cards' => [], 'shortcuts' => []]);
        $html = (string) $this->get('/')->getBody();
        $this->assertStringContainsString('data-dashboard-editable', $html);
        $this->assertStringContainsString('data-dashboard-edit aria-pressed="false"', $html);
        $this->assertStringContainsString('data-layout-save', $html);
        $this->assertStringNotContainsString('data-autosave', $html);
        $this->assertStringContainsString('data-dashboard-template="cards:members"', $html);
        $this->assertStringContainsString('data-dashboard-add="members"', $html);
        $this->assertStringNotContainsString('data-dashboard-template="cards:finance"', $html);
        $this->assertStringNotContainsString('name="cards[]"', $this->visibleHome());
    }

    public function test_authentication_and_csrf_are_required(): void
    {
        $this->assertSame(302, $this->get('/dashboard/preferences')->getStatusCode());
        $this->loginAs(['admin']);
        $res = $this->app->handle((new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/dashboard/preferences')->withParsedBody(['version' => '0']));
        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM user_dashboard_preferences')->fetchColumn());
    }
}
