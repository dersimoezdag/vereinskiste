<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Mail;

use PDO;
use PHPMailer\PHPMailer\PHPMailer;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Members\ApplicationRepository;
use Vereinskiste\Privacy\Settings;
use Vereinskiste\Privacy\Consents;
use Vereinskiste\Mail\Outbox;
use Vereinskiste\Mail\Mailer;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Tests\TestCase;

final class ApplicationOutboxTest extends TestCase
{
    private PDO $db;
    protected function setUp():void
    {
        $this->db=$this->pdo();(new Migrator($this->db,dirname(__DIR__,2).'/migrations'))->migrate();
        $this->db->exec("INSERT INTO users(name,email,password_hash) VALUES('Admin','admin@example.org','x')");
    }
    private function application():int
    {
        $repo=new ApplicationRepository($this->db);$invite=$repo->createInvite(1,'',14,null,true);
        return $repo->submit($invite['token'],['name'=>'Erika Muster','email'=>'erika@example.org','consent_statute'=>'1','consent_privacy'=>'1','account_holder'=>'Erika','iban'=>'DE89370400440532013000','sepa_mandate_consent'=>'1']);
    }
    public function test_receipt_internal_and_decision_notifications_are_transactional_and_contain_no_bank_data():void
    {
        (new Settings($this->db))->save(['application_mail_to'=>'board@example.org'],1,1);$id=$this->application();
        $outbox=new Outbox($this->db);$this->assertCount(2,$outbox->list($id));
        (new ApplicationRepository($this->db))->approve($id,1,['subject'=>'Willkommen','message'=>'Unsere Zusage']);
        $this->assertCount(3,$outbox->list($id));
        $this->assertSame(3,(int)$this->db->query('SELECT count(*) FROM member_consents WHERE member_id IS NOT NULL')->fetchColumn());
        foreach($this->db->query('SELECT body FROM mail_outbox')->fetchAll(PDO::FETCH_COLUMN) as $body) {$this->assertStringNotContainsString('DE89',$body);}
        $outbox->process(new Mailer());$this->assertSame('pending',$outbox->list($id)[0]['status']);
        $sent=0;$mailer=new Mailer(['host'=>'smtp.example.org','from'=>'noreply@example.org'],function(PHPMailer $mail)use(&$sent):void {$sent++;});
        for($i=0;$i<5;$i++) {$outbox->process($mailer);} $this->assertSame(3,$sent);
    }
    public function test_ambiguous_failure_requires_explicit_retry_and_never_retries_silently():void
    {
        $id=$this->application();$outbox=new Outbox($this->db);$sent=0;
        $mailer=new Mailer(['host'=>'smtp.example.org','from'=>'noreply@example.org'],function(PHPMailer $mail)use(&$sent):void {$sent++;throw new \RuntimeException('private transport detail');});
        $outbox->process($mailer);$outbox->process($mailer);$this->assertSame(1,$sent);
        $row=$outbox->list($id)[0];$this->assertSame('uncertain',$row['status']);
        $outbox->retry((int)$row['id'],(int)$row['version'],1);$outbox->process($mailer);$this->assertSame(2,$sent);
    }
    public function test_changed_document_snapshot_and_invalid_public_text_do_not_consume_the_invite():void
    {
        $repo=new ApplicationRepository($this->db);$invite=$repo->createInvite(1);
        $old=(new Consents($this->db))->fingerprint();(new Settings($this->db))->save(['privacy_version'=>'2'],1,1);
        try {$repo->submit($invite['token'],['name'=>'Erika Muster','email'=>'erika@example.org','consent_statute'=>'1','consent_privacy'=>'1','document_fingerprint'=>$old]);$this->fail('stale');}
        catch(ValidationError $e) {$this->assertArrayHasKey('document_fingerprint',$e->errors);}
        $this->assertSame(0,(int)$this->db->query('SELECT count(*) FROM membership_applications')->fetchColumn());
        $this->assertSame(0,(int)$this->db->query('SELECT used_count FROM membership_application_invites')->fetchColumn());
    }
}
