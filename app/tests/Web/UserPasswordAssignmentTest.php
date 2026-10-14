<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use PDO;
use Vereinskiste\Auth\AuthService;
use Vereinskiste\Auth\UserRepository;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Tests\TestCase;

final class UserPasswordAssignmentTest extends TestCase
{
    use WebHarness;
    private PDO $pdo;
    private int $admin;
    private int $target;

    protected function setUp(): void
    {
        $this->pdo=$this->pdo();
        (new Migrator($this->pdo,dirname(__DIR__,2).'/migrations'))->migrate();
        $this->bootApp();
        $this->admin=$this->loginAs(['admin']);
        $this->target=$this->webUser('target@example.org',['kassenwart']);
    }

    private function input(array $override=[]): array
    {
        return $override+['name'=>'Test Person','email'=>'target@example.org','roles'=>['kassenwart'],'active'=>'1'];
    }
    private function user(): array { return (new UserRepository($this->pdo))->findById($this->target); }

    public function test_empty_password_preserves_hash_while_profile_changes_are_saved(): void
    {
        $before=$this->user()['password_hash'];
        $response=$this->postForm('/users/'.$this->target.'/edit',$this->input(['name'=>'Renamed','password'=>'']));
        $this->assertSame(302,$response->getStatusCode());
        $this->assertSame($before,$this->user()['password_hash']);
        $this->assertSame('Renamed',$this->user()['name']);
    }

    public function test_new_password_is_hashed_audited_and_invalidates_existing_session(): void
    {
        $session=new ArraySessionStore();
        $auth=new AuthService(new UserRepository($this->pdo),$session,null,new \Vereinskiste\Auth\RateLimiter($this->securityDir()));
        $this->assertNotNull($auth->login('target@example.org','richtig-richtig'));
        $password='Ein-neues-sicheres-Passwort';
        $response=$this->postForm('/users/'.$this->target.'/edit',$this->input(['password'=>$password]));
        $this->assertSame(302,$response->getStatusCode());
        $this->assertTrue(password_verify($password,$this->user()['password_hash']));
        $this->assertNull((new AuthService(new UserRepository($this->pdo),$session))->currentUser());
        $detail=$this->pdo->query("SELECT detail::text FROM audit_log WHERE action='user_password_reset'")->fetchColumn();
        $this->assertIsString($detail);
        $this->assertStringNotContainsString($password,$detail);
    }

    public function test_invalid_password_aborts_profile_changes_and_is_not_echoed(): void
    {
        $before=$this->user()['password_hash'];
        $response=$this->postForm('/users/'.$this->target.'/edit',$this->input(['name'=>'Must not save','password'=>'short123']));
        $this->assertSame(422,$response->getStatusCode());
        $this->assertSame('Test Person',$this->user()['name']);
        $this->assertSame($before,$this->user()['password_hash']);
        $this->assertStringNotContainsString('short123',(string)$response->getBody());
    }

    public function test_admin_cannot_bypass_current_password_check_for_own_account(): void
    {
        $response=$this->postForm('/users/'.$this->admin.'/edit',['name'=>'Test Person','email'=>'wer@example.org',
            'roles'=>['admin'],'active'=>'1','password'=>'Ein-neues-sicheres-Passwort']);
        $this->assertSame(422,$response->getStatusCode());
        $this->assertTrue(password_verify('richtig-richtig',(new UserRepository($this->pdo))->findById($this->admin)['password_hash']));
    }

    public function test_link_failure_rolls_back_profile_and_password_together(): void
    {
        $before=$this->user()['password_hash'];
        $response=$this->postForm('/users/'.$this->target.'/edit',$this->input([
            'name'=>'Must not save','password'=>'Ein-neues-sicheres-Passwort','member_id'=>'999999']));
        $this->assertSame(422,$response->getStatusCode());
        $this->assertSame($before,$this->user()['password_hash']);
        $this->assertSame('Test Person',$this->user()['name']);
    }

    public function test_widgets_are_only_present_for_initial_or_other_users_new_password(): void
    {
        $new=(string)$this->get('/users/new')->getBody();
        $this->assertStringContainsString('Anfangspasswort',$new);
        $this->assertStringContainsString('data-password-generate',$new);
        $edit=(string)$this->get('/users/'.$this->target.'/edit')->getBody();
        $this->assertStringContainsString('Neues Passwort vergeben',$edit);
        $this->assertStringContainsString('placeholder="••••••••"',$edit);
        $this->assertStringContainsString('Leer lassen',$edit);
        $this->assertStringNotContainsString('action="/users/'.$this->target.'/password"',$edit);
        foreach (['/users/'.$this->admin.'/edit','/account/password','/login'] as $path) {
            $this->assertStringNotContainsString('data-admin-password',(string)$this->get($path)->getBody());
        }
    }
}
