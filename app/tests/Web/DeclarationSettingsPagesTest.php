<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Tests\TestCase;

final class DeclarationSettingsPagesTest extends TestCase
{
    use WebHarness;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->store = new ArraySessionStore();
        $this->app = Kernel::create(['pdo' => $this->pdo, 'session_store' => $this->store, 'require_https' => false, 'security_dir' => $this->securityDir()]);
    }

    public function test_board_creates_edits_moves_and_deactivates_declarations(): void
    {
        $this->loginAs(['admin']);
        $list = (string) $this->get('/settings/declarations')->getBody();
        $this->assertStringContainsString('Antrag auf Mitgliedschaft', $list);
        $this->assertStringContainsString('href="/settings/declarations"', $list);

        $this->assertSame(200, $this->get('/settings/declarations/new')->getStatusCode());
        $res = $this->postForm('/settings/declarations/new', ['title' => 'Beitragsordnung', 'content' => '<p>Ich erkenne die Beitragsordnung an.</p>', 'kind' => 'confirmation', 'signature_required' => '1']);
        $this->assertSame('/settings/declarations', $res->getHeaderLine('Location'));
        $id = (int) $this->pdo->query("SELECT declaration_id FROM application_declaration_versions WHERE title = 'Beitragsordnung'")->fetchColumn();

        $edit = (string) $this->get("/settings/declarations/$id")->getBody();
        $this->assertStringContainsString('data-rich-text', $edit);
        $this->assertStringContainsString('Ich erkenne die Beitragsordnung an.', $edit);
        $this->assertStringContainsString('data-signature-pad', $edit, 'preview shows signature field');

        $this->assertSame(422, $this->postForm("/settings/declarations/$id", ['title' => '', 'content' => '', 'kind' => 'confirmation'])->getStatusCode());
        $this->postForm("/settings/declarations/$id", ['title' => 'Beitragsordnung', 'content' => '<p>Neu</p>', 'kind' => 'consent']);
        $this->assertSame(2, (int) $this->pdo->query("SELECT current_version FROM application_declarations WHERE id = $id")->fetchColumn());

        $this->postForm("/settings/declarations/$id/move", ['direction' => 'up']);
        $this->assertSame(4, (int) $this->pdo->query("SELECT position FROM application_declarations WHERE id = $id")->fetchColumn());
        $this->postForm("/settings/declarations/$id/toggle", ['active' => '0']);
        $this->assertFalse((bool) $this->pdo->query("SELECT active FROM application_declarations WHERE id = $id")->fetchColumn());
        $this->assertSame(404, $this->get('/settings/declarations/999')->getStatusCode());
    }

    public function test_builder_saves_sepa_and_rejects_stale_updates(): void
    {
        $this->loginAs(['admin']);
        $settings = new \Vereinskiste\Members\ApplicationSettings($this->pdo);
        $version = $settings->get()['version'];
        $page = (string) $this->get('/settings/declarations')->getBody();
        $this->assertStringContainsString('name="require_sepa"', $page);
        $privacy = (string) $this->get('/settings/privacy')->getBody();
        $this->assertStringNotContainsString('name="application_require_sepa"', $privacy);

        $res = $this->postForm('/settings/declarations/settings', ['version' => (string) $version, 'require_sepa' => '1']);
        $this->assertSame('/settings/declarations', $res->getHeaderLine('Location'));
        $this->assertTrue($settings->get()['require_sepa']);
        $this->assertSame(409, $this->postForm('/settings/declarations/settings', ['version' => (string) $version])->getStatusCode());
        $this->assertTrue($settings->get()['require_sepa']);

        foreach ([false, true] as $isTest) {
            $this->postForm('/members/invites/new', ['is_test' => $isTest ? '1' : '0']);
            $invite = $this->pdo->query('SELECT require_sepa FROM membership_application_invites ORDER BY id DESC LIMIT 1')->fetch();
            $this->assertTrue((bool) $invite['require_sepa']);
        }
        $this->postForm('/settings/declarations/settings', ['version' => (string) $settings->get()['version']]);
        $this->assertFalse($settings->get()['require_sepa']);
        foreach ([false, true] as $isTest) {
            $this->postForm('/members/invites/new', ['is_test' => $isTest ? '1' : '0', 'require_sepa' => '1']);
            $invite = $this->pdo->query('SELECT require_sepa FROM membership_application_invites ORDER BY id DESC LIMIT 1')->fetch();
            $this->assertFalse((bool) $invite['require_sepa']);
        }
    }

    public function test_members_role_cannot_manage_declarations(): void
    {
        $this->loginAs(['secretary']);
        $this->assertSame(403, $this->get('/settings/declarations')->getStatusCode());
        $this->assertSame(403, $this->postForm('/settings/declarations/settings', ['version' => '1', 'require_sepa' => '1'])->getStatusCode());
        $this->assertSame(403, $this->postForm('/settings/declarations/new', ['title' => 'X', 'kind' => 'notice'])->getStatusCode());
    }
}
