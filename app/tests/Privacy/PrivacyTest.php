<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Privacy;

use PDO;
use PDOException;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Privacy\Settings;
use Vereinskiste\Privacy\Consents;
use Vereinskiste\Privacy\MemberPrivacy;
use Vereinskiste\Members\MemberRepository;
use Vereinskiste\Members\ApplicationRepository;
use Vereinskiste\Members\MembershipPeriods;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Tests\TestCase;

final class PrivacyTest extends TestCase
{
    private PDO $db;
    protected function setUp():void
    {
        $this->db=$this->pdo();(new Migrator($this->db,dirname(__DIR__,2).'/migrations'))->migrate();
        $this->db->exec("INSERT INTO users(name,email,password_hash) VALUES('Admin','admin@example.org','not-exportable')");
    }
    private function member():int {return (new MemberRepository($this->db))->save(null,['name'=>'Erika Muster','email'=>'erika@example.org','notes'=>'private-note'],0,1);}
    public function test_consents_append_and_withdrawal_does_not_end_membership():void
    {
        $id=$this->member();$periods=new MembershipPeriods($this->db);$periods->join($id,'2026-01-01',1);
        $service=new Consents($this->db);
        foreach(['1','0'] as $granted) {$service->record($id,['kind'=>'newsletter','granted'=>$granted,'document_version'=>'v1','reason'=>'Intern erfasst'],1);}
        $this->assertCount(2,$service->list($id));$this->assertFalse($service->list($id)[0]['granted']);
        $this->assertTrue((new MemberRepository($this->db))->find($id)['current']);
        $this->expectException(PDOException::class);$this->db->exec("UPDATE member_consents SET granted=true");
    }
    public function test_preview_blocks_active_members_accounts_and_unconfigured_retention():void
    {
        $id=$this->member();$privacy=new MemberPrivacy($this->db);
        $this->assertContains('privacy.block.policy',$privacy->preview($id)['blocked']);
        (new Settings($this->db))->save(['member_retention_days'=>'0'],1,1);
        (new MembershipPeriods($this->db))->join($id,'2026-01-01',1);
        $this->db->prepare("UPDATE members SET data=data || jsonb_build_object('email','linked-privacy@example.org') WHERE id=?")->execute([$id]);
        $this->db->exec("UPDATE users SET member_id=$id WHERE id=1");
        $blocked=$privacy->preview($id)['blocked'];$this->assertContains('privacy.block.active',$blocked);$this->assertContains('privacy.block.account',$blocked);
        $this->expectException(ValidationError::class);$preview=$privacy->preview($id);$privacy->anonymize($id,1,$preview['fingerprint'],'Prüfung',1);
    }
    public function test_anonymization_redacts_member_history_applications_consents_and_mail_but_keeps_ids():void
    {
        $repo=new ApplicationRepository($this->db);$invite=$repo->createInvite(1);
        $app=$repo->submit($invite['token'],['name'=>'Erika Muster','email'=>'erika@example.org','notes'=>'private-note','consent_statute'=>'1','consent_privacy'=>'1']);
        $id=$repo->approve($app,1);$member=(new MemberRepository($this->db))->find($id);
        $today=(new \DateTimeImmutable('today'))->format('Y-m-d');(new MembershipPeriods($this->db))->leave($id,$today,'Privater Austrittsgrund',1);
        (new Settings($this->db))->save(['member_retention_days'=>'0'],1,1);
        $privacy=new MemberPrivacy($this->db);$preview=$privacy->preview($id);$this->assertSame([],$preview['blocked']);
        $this->assertStringNotContainsString('not-exportable',json_encode($privacy->disclosure($id),JSON_THROW_ON_ERROR));
        $privacy->anonymize($id,$member['version'],$preview['fingerprint'],'Aufbewahrung geprüft',1);
        $after=(new MemberRepository($this->db))->find($id);$this->assertSame($id,$after['id']);$this->assertNotNull($after['anonymized_at']);
        $disclosure=json_encode($privacy->disclosure($id),JSON_THROW_ON_ERROR);
        foreach(['Erika','erika@example.org','private-note','Privater Austrittsgrund'] as $private) {$this->assertStringNotContainsString($private,$disclosure);}
        $this->assertSame(2,(int)$this->db->query("SELECT count(*) FROM mail_outbox WHERE status='cancelled' AND body='' AND recipient='redacted@example.invalid'")->fetchColumn());
        $this->assertSame(1,(int)$this->db->query('SELECT count(*) FROM privacy_actions')->fetchColumn());
        $this->expectException(ValidationError::class);(new MemberRepository($this->db))->save($id,['name'=>'Neu'],2,1);
    }
    public function test_rejected_application_anonymization_removes_invite_and_mail_personal_copies():void
    {
        $repo=new ApplicationRepository($this->db);$invite=$repo->createInvite(1,'Erika Muster Einladung');
        $app=$repo->submit($invite['token'],['name'=>'Erika Muster','email'=>'erika@example.org','consent_statute'=>'1','consent_privacy'=>'1']);
        $repo->reject($app,'Persönlicher Grund',1);
        (new Settings($this->db))->save(['application_retention_days'=>'0'],1,1);
        $privacy=new \Vereinskiste\Privacy\ApplicationPrivacy($this->db);$preview=$privacy->preview($app);$this->assertSame([],$preview['blocked']);
        $privacy->anonymize($app,(int)$preview['application']['version'],$preview['fingerprint'],'Aufbewahrung geprüft',1);
        $after=$repo->findApplication($app);$this->assertNotNull($after['anonymized_at']);$this->assertSame('redacted@example.invalid',$after['email']);
        $this->assertSame('',$this->db->query('SELECT title FROM membership_application_invites')->fetchColumn());
        $this->assertSame(2,(int)$this->db->query("SELECT count(*) FROM member_consents WHERE snapshot='{}'::jsonb")->fetchColumn());
    }
    public function test_stale_preview_cannot_anonymize_and_retention_date_is_checked():void
    {
        $id=$this->member();$periods=new MembershipPeriods($this->db);$periods->join($id,'2026-01-01',1);$periods->leave($id,'2026-01-02','',1);
        (new Settings($this->db))->save(['member_retention_days'=>'999999'],1,1);
        $privacy=new MemberPrivacy($this->db);$this->assertContains('privacy.block.retention',$privacy->preview($id)['blocked']);
        (new Settings($this->db))->save(['member_retention_days'=>'0'],2,1);$preview=$privacy->preview($id);
        (new Consents($this->db))->record($id,['kind'=>'media','granted'=>'0','reason'=>'Widerruf'],1);
        $this->expectException(Conflict::class);$privacy->anonymize($id,1,$preview['fingerprint'],'Prüfung',1);
    }
}
