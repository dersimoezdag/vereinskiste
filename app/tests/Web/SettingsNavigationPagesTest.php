<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Web;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Tests\TestCase;

final class SettingsNavigationPagesTest extends TestCase
{
    use WebHarness;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->bootApp();
    }

    public function test_settings_replace_application_menu_and_tabs_stay_in_their_section(): void
    {
        $this->loginAs(['admin']);
        foreach (['/settings', '/settings/backup', '/settings/club', '/settings/club-roles', '/settings/declarations', '/settings/declarations/options', '/settings/privacy', '/users', '/audit-log'] as $path) {
            $res = $this->get($path);
            $this->assertSame(200, $res->getStatusCode(), $path);
            $html = (string) $res->getBody();
            $this->assertStringContainsString('data-navigation-mode="settings"', $html, $path);
            $this->assertStringContainsString('Zurück zur Anwendung', $html);
            $this->assertStringNotContainsString('id="members-menu"', $html);
        }
        $system = (string) $this->get('/settings')->getBody();
        $this->assertStringContainsString('aria-label="System"', $system);
        $this->assertStringNotContainsString('aria-label="Verein"', $system);
        $options = (string) $this->get('/settings/declarations/options')->getBody();
        $this->assertStringContainsString('name="require_sepa"', $options);
        $this->assertStringNotContainsString('name="require_sepa"', (string) $this->get('/settings/declarations')->getBody());
        $app = (string) $this->get('/members')->getBody();
        $this->assertStringContainsString('data-navigation-mode="application"', $app);
        $this->assertStringContainsString('data-settings-entry', $app);
        $this->assertStringNotContainsString('data-application-return', $app);
    }
}
