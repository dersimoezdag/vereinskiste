<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Web;
use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Auth\UserRepository;
use Vereinskiste\Members\MemberRepository;
use Vereinskiste\Members\MemberUserLink;
use Vereinskiste\Tests\TestCase;
final class AccessControlPagesTest extends TestCase
{
 use WebHarness;
 private PDO $pdo;
 protected function setUp(): void { $this->pdo=$this->pdo();(new Migrator($this->pdo,dirname(__DIR__,2).'/migrations'))->migrate();$this->bootApp(); }
 public function test_secretary_cannot_assign_or_end_privileged_functions(): void
 {
  $actor=$this->loginAs(['secretary']);$member=(new MemberRepository($this->pdo))->save(null,['name'=>'Test Member'],0,$actor);
  $this->assertSame(403,$this->postForm('/members/'.$member.'/club-roles',['role_id'=>'6'])->getStatusCode());
  $this->assertSame(0,(int)$this->pdo->query('SELECT count(*) FROM member_club_roles')->fetchColumn());
  $this->pdo->prepare('INSERT INTO member_club_roles(member_id,role_id,start_date) VALUES (?,6,CURRENT_DATE)')->execute([$member]);
  $this->assertSame(403,$this->postForm('/members/'.$member.'/club-roles/1/end',['date'=>date('Y-m-d')])->getStatusCode());
  $html=(string)$this->get('/members/'.$member)->getBody();
  $this->assertStringNotContainsString('value="6">1. Vorsitzender',$html);
  $this->pdo->exec("UPDATE user_roles SET role='admin'");
  $this->assertSame(302,$this->postForm('/members/'.$member.'/club-roles/1/end',['date'=>date('Y-m-d')])->getStatusCode());
 }
 public function test_linked_login_email_changes_require_user_management_and_member_data_is_readonly_on_user_form(): void
 {
  $actor=$this->loginAs(['secretary']);$target=(new UserRepository($this->pdo))->create('Account','account@example.org',password_hash('valid-password',PASSWORD_DEFAULT),[],null);
  $member=(new MemberRepository($this->pdo))->save(null,['name'=>'Canonical Member','email'=>'member@example.org','phone'=>'+49 30 123456'],0,$actor);
  (new MemberUserLink($this->pdo))->link($member,$target,$actor);
  $this->assertSame(403,$this->postForm('/members/'.$member,['name'=>'Canonical Member','email'=>'takeover@example.org','version'=>'1'])->getStatusCode());
  $this->assertSame('member@example.org',(new UserRepository($this->pdo))->findById($target)['email']);
  $this->pdo->exec("UPDATE user_roles SET role='admin'");
  $html=(string)$this->get('/users/'.$target.'/edit')->getBody();$this->assertMatchesRegularExpression('/data-linked-email[^>]*readonly/',$html);$this->assertStringContainsString('+49 30 123456',$html);
  $this->assertSame(302,$this->postForm('/members/'.$member,['name'=>'Canonical Member','email'=>'changed@example.org','version'=>'1'])->getStatusCode());
  $this->assertSame('changed@example.org',(new UserRepository($this->pdo))->findById($target)['email']);
 }
 public function test_derived_secretary_is_not_selected_or_saved_as_a_manual_role(): void
 {
  $actor=$this->loginAs(['admin']);$target=(new UserRepository($this->pdo))->create('Target','target@example.org',password_hash('valid-password',PASSWORD_DEFAULT),[],null);
  $member=(new MemberRepository($this->pdo))->save(null,['name'=>'Member','email'=>'member@example.org'],0,$actor);(new MemberUserLink($this->pdo))->link($member,$target,$actor);
  $this->pdo->exec("INSERT INTO club_roles(name,kind,access_role) VALUES('Secretary function','function','secretary')");
  $this->pdo->prepare("INSERT INTO member_club_roles(member_id,role_id,start_date) SELECT ?,id,CURRENT_DATE FROM club_roles WHERE name='Secretary function'")->execute([$member]);
  $html=(string)$this->get('/users/'.$target.'/edit')->getBody();$this->assertStringContainsString('Secretary function',$html);$this->assertDoesNotMatchRegularExpression('/value="secretary"[^>]*checked/',$html);
  $this->assertSame(302,$this->postForm('/users/'.$target.'/edit',['member_id'=>(string)$member,'active'=>'1','roles'=>[]])->getStatusCode());
  $this->assertSame([],(new UserRepository($this->pdo))->findById($target)['manual_roles']);
  $this->pdo->exec('UPDATE member_club_roles SET end_date=CURRENT_DATE');$this->assertSame([],(new UserRepository($this->pdo))->findById($target)['roles']);
 }
}
