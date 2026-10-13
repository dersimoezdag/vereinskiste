<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Tests\TestCase;

final class MembersPagesTest extends TestCase
{
    use WebHarness;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->store = new ArraySessionStore();
        $this->app = Kernel::create([
            'pdo' => $this->pdo,
            'session_store' => $this->store,
            'require_https' => false,
            'security_dir' => $this->securityDir(),
        ]);
    }

    public function test_schriftfuehrer_maintains_members_kassenwart_reads_only(): void
    {
        $this->loginAs(['schriftfuehrer']);
        $response = $this->postForm('/members/new', ['name' => 'Erika Muster', 'email' => 'erika@example.org', 'version' => '0']);
        $this->assertSame('/members/1', $response->getHeaderLine('Location'));
        $this->assertStringContainsString('Erika Muster', (string) $this->get('/members')->getBody());
        $this->assertSame(302, $this->postForm('/members/1/join', ['date' => '2026-01-01'])->getStatusCode());
        $this->assertSame(422, $this->postForm('/members/1/join', ['date' => '2026-02-01'])->getStatusCode());
        $this->assertSame(409, $this->postForm('/members/1', ['name' => 'X', 'version' => '99'])->getStatusCode());
        $this->pdo->exec("UPDATE user_roles SET role='kassenwart'");
        $this->assertSame(200, $this->get('/members/1')->getStatusCode());
        $this->assertSame(403, $this->postForm('/members/1', ['name' => 'Y', 'version' => '1'])->getStatusCode());
        $this->assertSame(403, $this->get('/members/new')->getStatusCode());
    }

    public function test_linking_needs_users_manage_and_shows_hint_without_user(): void
    {
        $this->loginAs(['schriftfuehrer']);
        $this->postForm('/members/new', ['name' => 'Erika Muster', 'version' => '0']);
        $body = (string) $this->get('/members/1')->getBody();
        $this->assertStringContainsString('Kein Benutzerzugang', $body);
        $this->assertSame(403, $this->postForm('/members/1/user', ['user_id' => '1'])->getStatusCode());
        $this->pdo->exec("UPDATE user_roles SET role='vorstand'");
        $this->assertSame('/members/1', $this->postForm('/members/1/user', ['user_id' => '1'])->getHeaderLine('Location'));
        $this->assertSame(1, (int) $this->pdo->query('SELECT member_id FROM users WHERE id = 1')->fetchColumn());
    }

    public function test_member_menu_entry_depends_on_permission(): void
    {
        $this->loginAs(['schriftfuehrer']);
        $this->assertStringContainsString('href="/members"', (string) $this->get('/')->getBody());
    }
}
