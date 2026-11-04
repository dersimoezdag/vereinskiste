<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Tests\TestCase;

final class MemberInvitesPagesTest extends TestCase
{
    use WebHarness;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->bootApp();
    }

    public function test_links_and_applications_have_separate_pages_and_usage_is_configurable(): void
    {
        $this->loginAs(['admin']);
        $links = (string) $this->get('/members/invites')->getBody();
        $this->assertStringContainsString('name="max_uses" value="1"', $links);
        $this->assertStringNotContainsString('Keine Mitgliedsanträge vorhanden.', $links);
        $applications = (string) $this->get('/members/applications')->getBody();
        $this->assertStringContainsString('Keine Mitgliedsanträge vorhanden.', $applications);
        $this->assertStringNotContainsString('name="max_uses"', $applications);
        foreach (['0', '-1', '1.5', '1000001', 'abc'] as $invalid) {
            $this->assertSame(422, $this->postForm('/members/invites/new', ['max_uses' => $invalid])->getStatusCode());
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM membership_application_invites')->fetchColumn());
        $this->postForm('/members/invites/new', []);
        $this->assertSame(1, (int) $this->pdo->query('SELECT max_uses FROM membership_application_invites')->fetchColumn());
        $res = $this->postForm('/members/invites/new', ['max_uses' => '5', 'title' => 'Five people']);
        $invite = $this->pdo->query('SELECT id, max_uses FROM membership_application_invites ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertSame(5, (int) $invite['max_uses']);
        $this->assertSame('/members/invites?invite=' . $invite['id'], $res->getHeaderLine('Location'));
        $this->assertStringContainsString('0 von 5 Anträgen eingereicht', (string) $this->get('/members/invites')->getBody());
    }
}
