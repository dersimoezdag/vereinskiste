<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Members\ApplicationRepository;
use Vereinskiste\Tests\TestCase;

final class InviteRevocationPagesTest extends TestCase
{
    use WebHarness;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->bootApp();
    }

    public function test_revoke_hides_link_and_blocks_public_access(): void
    {
        $actor = $this->loginAs(['admin']);
        $repo = new ApplicationRepository($this->pdo);
        $invite = $repo->createInvite($actor, 'Revocation example', 14, 'secret', false, true);
        $path = '/antrag/' . $invite['token'];
        $this->postForm($path . '/unlock', ['password' => 'secret']);
        $this->assertSame(200, $this->get($path)->getStatusCode());
        $list = (string) $this->get('/members/applications')->getBody();
        $this->assertStringContainsString('/members/invites/' . $invite['id'] . '/revoke', $list);
        $res = $this->postForm('/members/invites/' . $invite['id'] . '/revoke', ['filter' => 'pending', 'invite_status' => 'all']);
        $this->assertSame('/members/applications?filter=pending&invite_status=all', $res->getHeaderLine('Location'));
        $this->assertStringNotContainsString('Revocation example', (string) $this->get('/members/applications')->getBody());
        $history = (string) $this->get('/members/applications?invite_status=all')->getBody();
        $this->assertStringContainsString('Revocation example', $history);
        $this->assertStringNotContainsString('action="/members/invites/' . $invite['id'] . '/revoke"', $history);
        $this->assertSame(404, $this->postForm('/members/invites/' . $invite['id'] . '/revoke', [])->getStatusCode());
        $this->assertSame(410, $this->get($path)->getStatusCode());
        $this->assertSame($path, $this->postForm($path, [])->getHeaderLine('Location'));
        $this->assertSame($path, $this->postForm($path . '/unlock', ['password' => 'secret'])->getHeaderLine('Location'));
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM membership_applications')->fetchColumn());
    }

    public function test_read_only_role_cannot_revoke_invites(): void
    {
        $actor = $this->loginAs(['treasurer']);
        $repo = new ApplicationRepository($this->pdo);
        $invite = $repo->createInvite($actor);
        $this->assertSame(403, $this->postForm('/members/invites/' . $invite['id'] . '/revoke', [])->getStatusCode());
        $this->assertTrue($repo->findInviteByToken($invite['token'])['is_valid']);
    }
}
