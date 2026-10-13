<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Intake;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Intake\MailIntake;
use Vereinskiste\Intake\MimeParts;
use Vereinskiste\Intake\OcrSuggestions;
use Vereinskiste\Intake\MailboxSettings;
use Vereinskiste\Invoices\Import;
use Vereinskiste\Invoices\Extraction;
use Vereinskiste\Invoices\InvoiceRepository;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Tests\Invoices\InvoicesTest;
use Vereinskiste\Tests\Receipts\ReceiptFiles;
use Vereinskiste\Tests\TestCase;

final class IntakeTest extends TestCase
{
    use ReceiptFiles;
    private PDO $db;
    protected function setUp():void
    {
        $this->db=$this->pdo();(new Migrator($this->db,dirname(__DIR__,2).'/migrations'))->migrate();
        $this->db->exec("INSERT INTO users(name,email,password_hash) VALUES('Worker','worker@example.org','x'); INSERT INTO user_roles(user_id,role) VALUES(1,'kassenwart')");
    }
    protected function tearDown():void {$this->cleanScratch();}
    public function test_mail_is_idempotent_and_sender_only_suggests_member():void
    {
        $this->db->exec("INSERT INTO members(member_no,name,data) VALUES(1,'Erika','{\"email\":\"erika@example.org\"}')");
        $intake=new MailIntake($this->db,$this->scratchDir().'/documents');
        $mail=['sender'=>'erika@example.org','subject'=>'Sommerfest','body'=>'<script>alert(1)</script>','files'=>[['name'=>'../beleg.xml','data'=>InvoicesTest::xml()]]];
        $id=$intake->receive(str_repeat('a',64),1,1,$mail,1);
        $this->assertSame(1,$id);$this->assertNull($intake->receive(str_repeat('a',64),1,1,$mail,1));
        $this->assertSame(1,(int)$this->db->query('SELECT suggested_member_id FROM receipt_mail_messages')->fetchColumn());
        $this->assertSame(0,(int)$this->db->query('SELECT count(*) FROM invoice_payment_requests')->fetchColumn());
        $this->assertSame('pending',$this->db->query('SELECT security_status FROM invoice_documents')->fetchColumn());
        $this->assertSame('beleg.xml',$this->db->query('SELECT original_name FROM stored_files')->fetchColumn());
    }
    public function test_bad_attachment_rolls_back_whole_message_and_ineligible_actor_is_rejected():void
    {
        $intake=new MailIntake($this->db,$this->scratchDir().'/documents');
        $mail=['sender'=>'unknown@example.org','subject'=>'x','body'=>'','files'=>[['name'=>'safe.xml','data'=>InvoicesTest::xml()],['name'=>'evil.exe','data'=>'MZ executable']]];
        try {$intake->receive(str_repeat('b',64),1,1,$mail,1);$this->fail('bad attachment');}catch(ValidationError) {}
        $this->assertSame(0,(int)$this->db->query('SELECT count(*) FROM invoices')->fetchColumn());
        $this->assertSame(0,(int)$this->db->query('SELECT count(*) FROM receipt_mail_messages')->fetchColumn());
        $this->db->exec("UPDATE user_roles SET role='kassenpruefer'");
        $this->expectException(ValidationError::class);$intake->validateActor(1);
    }
    public function test_security_errors_never_unlock_files_and_can_be_retried():void
    {
        $dir=$this->scratchDir().'/documents';$id=(new Import($this->db,$dir))->upload($this->tmpFile(InvoicesTest::xml()),'x.xml',1);
        $doc=(int)$this->db->query('SELECT id FROM invoice_documents')->fetchColumn();
        try {(new Extraction($this->db,['documents'=>$dir],static fn():array=>['security'=>'error']))->process($doc);$this->fail('scanner error');}catch(\RuntimeException) {}
        $this->assertSame('error',$this->db->query('SELECT security_status FROM invoice_documents')->fetchColumn());
        (new Extraction($this->db,['documents'=>$dir],static fn():array=>['security'=>'clean']))->process($doc);
        $this->assertSame('clean',$this->db->query('SELECT security_status FROM invoice_documents')->fetchColumn());
        $this->assertSame('done',(new InvoiceRepository($this->db))->documents($id)[0]['extraction_status']);
    }
    public function test_blocked_document_has_no_ocr_suggestions_and_cannot_be_reviewed():void
    {
        $dir=$this->scratchDir().'/documents';$id=(new Import($this->db,$dir))->upload($this->tmpFile(InvoicesTest::xml()),'x.xml',1);
        $repo=new InvoiceRepository($this->db);$doc=$repo->documents($id)[0];
        (new Extraction($this->db,['documents'=>$dir],static fn():array=>['security'=>'blocked','extraction'=>['fields'=>['gross'=>'1.00']]]))->process((int)$doc['id']);
        $this->assertSame([],$repo->documents($id)[0]['extraction']['fields']);
        try {$repo->transition($id,2,'review','Geprüft',false,1);$this->fail('blocked');}catch(ValidationError $e) {$this->assertSame('intake.security.required',$e->errors['receipt']);}
    }
    public function test_ocr_suggestions_are_exact_and_do_not_infer_payment_recipient():void
    {
        $r=OcrSuggestions::extract("Rechnungsnummer: FEST-42\n13.10.2026\nGesamtbetrag: 1.234,56 EUR\nIBAN DE89370400440532013000");
        $this->assertSame('1234.56',$r['fields']['gross']);$this->assertSame('2026-10-13',$r['fields']['invoice_date']);$this->assertSame('FEST-42',$r['fields']['number']);
        $this->assertArrayNotHasKey('iban',$r['fields']);$this->assertArrayNotHasKey('recipient',$r['fields']);
        $this->assertArrayNotHasKey('invoice_date',OcrSuggestions::extract('31.02.2026')['fields']);
    }
    public function test_mime_html_is_kept_as_text_but_archives_and_nested_messages_are_rejected():void
    {
        $parts=MimeParts::flatten((object)['type'=>0,'subtype'=>'HTML','encoding'=>4]);
        $this->assertTrue($parts[0]['html']);$this->assertTrue($parts[0]['text']);
        $this->assertSame('<script>',MimeParts::decode('=3Cscript=3E',4));
        try {MimeParts::flatten((object)['type'=>3,'subtype'=>'ZIP','disposition'=>'ATTACHMENT']);$this->fail('zip');}catch(\DomainException) {}
        $this->expectException(\DomainException::class);MimeParts::flatten((object)['type'=>2,'subtype'=>'RFC822','parts'=>[(object)['type'=>0,'subtype'=>'PLAIN']]]);
    }
    public function test_mailbox_secrets_are_private_and_unsafe_hosts_rejected():void
    {
        $settings=new MailboxSettings($this->scratchDir().'/config');
        $settings->save(['host'=>'mail.example.org','username'=>'belege@example.org','folder'=>'INBOX','actor_id'=>'1','password'=>'secret','enabled'=>'1'],0);
        $this->assertSame('secret',$settings->get()['config']['password']);
        $this->assertSame(0600,fileperms($this->scratchDir().'/config/receipt-mailbox.json')&0777);
        $settings->save(['host'=>'mail.example.org','username'=>'belege@example.org','folder'=>'INBOX','actor_id'=>'1','password'=>'','enabled'=>'1'],1);
        $this->assertSame('secret',$settings->get()['config']['password']);
        $this->expectException(ValidationError::class);$settings->save(['host'=>'host/novalidate-cert','folder'=>'INBOX'],2);
    }
}
