<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Members\MemberRepository;
use Vereinskiste\Tests\TestCase;

final class UserMemberLinkPagesTest extends TestCase
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

    public function test_create_user_with_member_links_them(): void
    {
        $this->loginAs(['admin']);
        $mId = (new MemberRepository($this->pdo))->save(null, ['name' => 'Erika Muster', 'email' => 'erika@example.org'], 0, 1);

        $res = $this->postForm('/users/new', [
            'name' => 'Erika Muster',
            'email' => 'erika@example.org',
            'password' => 'Passwort123!Safe',
            'roles' => ['secretary'],
            'member_id' => (string) $mId,
        ]);
        $this->assertSame('/users', $res->getHeaderLine('Location'));
        $linked = $this->pdo->query("SELECT member_id FROM users WHERE email = 'erika@example.org'")->fetchColumn();
        $this->assertSame($mId, (int) $linked);
    }

    public function test_cannot_link_member_that_is_already_linked(): void
    {
        $this->loginAs(['admin']);
        $mId = (new MemberRepository($this->pdo))->save(null, ['name' => 'Erika Muster','email'=>'linked@example.org'], 0, 1);

        // Link to admin user first
        $this->pdo->exec("UPDATE users SET member_id = {$mId} WHERE id = 1");

        // Try creating second user with same member
        $res = $this->postForm('/users/new', [
            'name' => 'Zweiter Benutzer',
            'email' => 'zweiter@example.org',
            'password' => 'Passwort123!Safe',
            'roles' => ['secretary'],
            'member_id' => (string) $mId,
        ]);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('bereits einen Benutzer', (string) $res->getBody());

        // Ensure user was not created
        $count = $this->pdo->query("SELECT count(*) FROM users WHERE email = 'zweiter@example.org'")->fetchColumn();
        $this->assertSame(0, (int) $count);
    }

    public function test_new_user_form_prefills_from_member_query_param(): void
    {
        $this->loginAs(['admin']);
        $mId = (new MemberRepository($this->pdo))->save(null, ['name' => 'Vorauswahl Mitglied', 'email' => 'vorauswahl@example.org'], 0, 1);

        $body = (string) $this->get('/users/new?member=' . $mId)->getBody();
        $this->assertStringContainsString('value="Vorauswahl Mitglied"', $body);
        $this->assertStringContainsString('value="vorauswahl@example.org"', $body);
        $this->assertStringContainsString('value="' . $mId . '" selected', $body);
    }

    public function test_edit_user_can_link_and_unlink_member(): void
    {
        $this->loginAs(['admin']);
        $m1 = (new MemberRepository($this->pdo))->save(null, ['name' => 'Mitglied 1','email'=>'first@example.org'], 0, 1);
        $m2 = (new MemberRepository($this->pdo))->save(null, ['name' => 'Mitglied 2','email'=>'second@example.org'], 0, 1);

        // Link m1
        $this->postForm('/users/1/edit', [
            'name' => 'Admin',
            'email' => 'wer@example.org',
            'roles' => ['admin'],
            'active' => '1',
            'member_id' => (string) $m1,
        ]);
        $this->assertSame($m1, (int) $this->pdo->query('SELECT member_id FROM users WHERE id = 1')->fetchColumn());

        // Change to m2
        $this->postForm('/users/1/edit', [
            'member_link_unlocked' => '1',
            'name' => 'Admin',
            'email' => 'wer@example.org',
            'roles' => ['admin'],
            'active' => '1',
            'member_id' => (string) $m2,
        ]);
        $this->assertSame($m2, (int) $this->pdo->query('SELECT member_id FROM users WHERE id = 1')->fetchColumn());

        // Unlink
        $this->postForm('/users/1/edit', [
            'member_link_unlocked' => '1',
            'name' => 'Admin',
            'email' => 'wer@example.org',
            'roles' => ['admin'],
            'active' => '1',
            'member_id' => '',
        ]);
        $this->assertNull($this->pdo->query('SELECT member_id FROM users WHERE id = 1')->fetchColumn() ?: null);
    }

    public function test_existing_member_link_requires_explicit_unlock_and_survives_disabled_field_submission(): void
    {
        $this->loginAs(['admin']);
        $member=(new MemberRepository($this->pdo))->save(null,['name'=>'Mitglied','email'=>'locked@example.org'],0,1);
        $data=['roles'=>['admin'],'active'=>'1','member_id'=>(string)$member];
        $this->assertSame(302,$this->postForm('/users/1/edit',$data)->getStatusCode());
        $html=(string)$this->get('/users/1/edit')->getBody();
        $this->assertStringContainsString('data-member-link-unlock',$html);
        $this->assertMatchesRegularExpression('/<select id="user-member-id"[^>]* disabled>/',$html);
        $this->assertSame(403,$this->postForm('/users/1/edit',['member_id'=>'']+$data)->getStatusCode());
        unset($data['member_id']);
        $this->assertSame(302,$this->postForm('/users/1/edit',$data)->getStatusCode());
        $this->assertSame($member,(int)$this->pdo->query('SELECT member_id FROM users WHERE id=1')->fetchColumn());
    }
}
