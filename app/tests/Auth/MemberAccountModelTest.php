<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Auth;
use PDO;
use Vereinskiste\Auth\AccessControl;
use Vereinskiste\Auth\UserRepository;
use Vereinskiste\Auth\Permissions;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Members\MemberRepository;
use Vereinskiste\Members\MemberUserLink;
use Vereinskiste\Tests\TestCase;
final class MemberAccountModelTest extends TestCase
{
 private PDO $db;
 private UserRepository $users;
 protected function setUp(): void
 {
  $this->db=$this->pdo();(new Migrator($this->db,dirname(__DIR__,2).'/migrations'))->migrate();$this->users=new UserRepository($this->db);
 }
 private function user(string $email='separate@example.org',array $roles=[]): int { return $this->users->create('Separate User',$email,password_hash('secure-password',PASSWORD_DEFAULT),$roles,null); }
 private function member(string $email='member@example.org'): int { return (new MemberRepository($this->db))->save(null,['name'=>'Linked Member','email'=>$email,'phone'=>'+49 30 123456'],0,1); }
 public function test_linked_identity_has_one_editable_source_and_unlink_retains_latest_identity(): void
 {
  $user=$this->user();$member=$this->member();$link=new MemberUserLink($this->db);$link->link($member,$user,$user);
  $this->assertSame('Linked Member',$this->users->findById($user)['name']);$this->assertSame('member@example.org',$this->users->findById($user)['email']);
  $this->users->update($user,'Forged Name','forged@example.org',[],true,$user);
  $this->assertSame('Linked Member',$this->users->findById($user)['name']);
  (new MemberRepository($this->db))->save($member,['name'=>'Changed Member','email'=>'CHANGED@example.org'],1,$user);
  $this->assertNull($this->users->findByEmail('member@example.org'));
  $this->assertSame($user,$this->users->findByEmail('changed@example.org')['id']);
  $link->unlink($member,$user);$this->assertSame('Changed Member',$this->users->findById($user)['name']);$this->assertNull($this->users->findById($user)['member_id']);
 }
 public function test_three_board_functions_grant_equal_roles_without_admin_and_ignore_renaming(): void
 {
  $user=$this->user();$member=$this->member();(new MemberUserLink($this->db))->link($member,$user,$user);
  foreach (['first_chairperson','second_chairperson','finance_board_member'] as $key) {
   $this->db->prepare('INSERT INTO member_club_roles(member_id,role_id,start_date) SELECT ?,id,CURRENT_DATE FROM club_roles WHERE system_key=?')->execute([$member,$key]);
   $this->assertSame(['board'],$this->users->findById($user)['roles']);
   $this->assertFalse(AccessControl::can($this->users->findById($user),'users.manage'));
   $this->assertTrue(AccessControl::can($this->users->findById($user),'documents.write'));
   $this->db->prepare('UPDATE club_roles SET name=name || ? WHERE system_key=?')->execute([' renamed',$key]);
   $this->assertSame(['board'],$this->users->findById($user)['roles']);
   $this->db->exec('UPDATE member_club_roles SET end_date=CURRENT_DATE');$this->assertSame([],$this->users->findById($user)['roles']);
  }
  $this->assertSame(0,(int)$this->db->query('SELECT count(*) FROM user_roles')->fetchColumn());
 }
 public function test_future_functions_archiving_and_unlinking_remove_derived_access(): void
 {
  $user=$this->user();$member=$this->member();$link=new MemberUserLink($this->db);$link->link($member,$user,$user);
  $this->db->prepare("INSERT INTO member_club_roles(member_id,role_id,start_date) SELECT ?,id,CURRENT_DATE+1 FROM club_roles WHERE system_key='first_chairperson'")->execute([$member]);
  $this->assertSame([],$this->users->findById($user)['roles']);$this->db->exec('UPDATE member_club_roles SET start_date=CURRENT_DATE');$this->assertSame(['board'],$this->users->findById($user)['roles']);
  $this->db->exec("UPDATE club_roles SET active=false WHERE system_key='first_chairperson'");$this->assertSame([],$this->users->findById($user)['roles']);
  $this->db->exec("UPDATE club_roles SET active=true WHERE system_key='first_chairperson'");$link->unlink($member,$user);$this->assertSame([],$this->users->findById($user)['roles']);
 }
 public function test_admin_has_every_permission_and_is_never_inferred_from_function(): void
 {
  $user=$this->user(roles:['admin']);$account=$this->users->findById($user);
  foreach(Permissions::ALL as $right) { $this->assertTrue(AccessControl::can($account,$right)); }
  $this->assertTrue(AccessControl::can($account,'documents.read',['kind'=>'minutes','status'=>'draft','meeting_type'=>'board','historic'=>true]));
  $this->assertFalse(AccessControl::can($account,'invented.permission'));
  $this->assertFalse(AccessControl::can(null,'documents.read'));
  $account['active']=false;$this->assertFalse(AccessControl::can($account,'documents.write'));
 }
 public function test_missing_and_conflicting_member_email_leave_links_unchanged(): void
 {
  $user=$this->user();$member=(new MemberRepository($this->db))->save(null,['name'=>'No Email'],0,$user);
  try {(new MemberUserLink($this->db))->link($member,$user,$user);$this->fail('Missing email');}catch(ValidationError $e){$this->assertSame('validation.user.email.invalid',$e->errors['user']);}
  $this->assertNull($this->users->findById($user)['member_id']);
  $taken=$this->user('taken@example.org');$member=$this->member('taken@example.org');
  try {(new MemberUserLink($this->db))->link($member,$user,$user);$this->fail('Duplicate email');}catch(ValidationError $e){$this->assertSame('validation.user.email.duplicate',$e->errors['user']);}
  $this->assertNull($this->users->findById($user)['member_id']);$this->assertSame($taken,$this->users->findByEmail('taken@example.org')['id']);
 }
 public function test_duplicate_email_change_rolls_back_member_and_account_together(): void
 {
  $user=$this->user();$member=$this->member();(new MemberUserLink($this->db))->link($member,$user,$user);$this->user('taken@example.org');
  try {(new MemberRepository($this->db))->save($member,['name'=>'Must Roll Back','email'=>'taken@example.org'],1,$user);$this->fail('Duplicate');}catch(ValidationError $e){$this->assertSame('validation.user.email.duplicate',$e->errors['email']);}
  $this->assertSame('Linked Member',(new MemberRepository($this->db))->find($member)['name']);$this->assertSame('member@example.org',$this->users->findById($user)['email']);
 }
}
