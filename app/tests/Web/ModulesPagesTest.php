<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Web;

use PDO;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Members\MemberRepository;
use Vereinskiste\Members\ApplicationRepository;
use Vereinskiste\Invoices\InvoiceRepository;
use Vereinskiste\Invoices\Extraction;
use Vereinskiste\Tests\Invoices\InvoicesTest;
use Vereinskiste\Tests\Receipts\ReceiptFiles;
use Vereinskiste\Tests\TestCase;

final class ModulesPagesTest extends TestCase
{
    use WebHarness, ReceiptFiles;
    private PDO $pdo;
    protected function setUp():void
    {
        $this->pdo=$this->pdo();(new Migrator($this->pdo,dirname(__DIR__,2).'/migrations'))->migrate();$this->store=new ArraySessionStore();
        $this->app=Kernel::create(['pdo'=>$this->pdo,'session_store'=>$this->store,'require_https'=>false,'security_dir'=>$this->securityDir(),'documents_dir'=>$this->scratchDir().'/documents']);
    }
    protected function tearDown():void {$this->cleanScratch();}
    public function test_invoice_upload_masks_private_download_and_permissions():void
    {
        $this->loginAs(['kassenwart']);$this->assertSame(200,$this->get('/invoices')->getStatusCode());
        $path=$this->tmpFile(InvoicesTest::xml());
        $req=(new ServerRequestFactory())->createServerRequest('POST','/invoices/import')->withParsedBody(['_csrf'=>$this->csrf()])
            ->withUploadedFiles(['receipt'=>new UploadedFile($path,'invoice.xml','application/xml',filesize($path))]);
        $res=$this->app->handle($req);$this->assertSame(200,$res->getStatusCode(),(string)$res->getBody());
        $this->assertSame(200,$this->get('/documents/1')->getStatusCode());
        (new Extraction($this->pdo,['documents'=>$this->scratchDir().'/documents']))->process(1);
        $this->assertSame(200,$this->get('/documents/1')->getStatusCode());
        $html=(string)$this->get('/invoices/1')->getBody();$this->assertStringContainsString('INV-2026-01',$html);$this->assertStringContainsString('invoice_date',$html);
        $this->assertSame(302,$this->postForm('/invoices/1',['version'=>'2','kind'=>'invoice','currency'=>'EUR','supplier_name'=>'Muster GmbH','number'=>'1','invoice_date'=>'2026-10-13','net'=>'100','tax'=>'19','gross'=>'119','reason'=>'Prüfung'])->getStatusCode());
        $this->pdo->exec("UPDATE user_roles SET role='kassenpruefer'");$this->assertSame(200,$this->get('/invoices/1')->getStatusCode());$this->assertSame(403,$this->postForm('/invoices/1',['version'=>'3'])->getStatusCode());
        $this->pdo->exec("UPDATE user_roles SET role='schriftfuehrer'");$this->assertSame(403,$this->get('/invoices')->getStatusCode());$this->assertSame(404,$this->get('/documents/1')->getStatusCode());
    }
    public function test_member_privacy_settings_disclosure_and_anonymization_have_explicit_preview_and_permission():void
    {
        $actor=$this->loginAs(['schriftfuehrer']);$id=(new MemberRepository($this->pdo))->save(null,['name'=>'Erika Muster','email'=>'erika@example.org'],0,$actor);
        $this->assertSame(403,$this->get('/members/'.$id.'/privacy')->getStatusCode());$this->assertSame(403,$this->get('/settings/privacy')->getStatusCode());
        $this->pdo->exec("UPDATE user_roles SET role='vorstand'");$this->assertSame(200,$this->get('/settings/privacy')->getStatusCode());
        $html=(string)$this->get('/members/'.$id.'/privacy')->getBody();$this->assertStringContainsString('Aufbewahrungsdauer',$html);
        $res=$this->get('/members/'.$id.'/disclosure.json');$this->assertSame('no-store',$res->getHeaderLine('Cache-Control'));$this->assertStringContainsString('Erika',(string)$res->getBody());
        $this->assertSame(422,$this->postForm('/members/'.$id.'/anonymize',['version'=>'1'])->getStatusCode());
        $this->assertSame(302,$this->postForm('/settings/privacy',['version'=>'1','member_retention_days'=>'0'])->getStatusCode());
        $html=(string)$this->get('/members/'.$id.'/privacy')->getBody();$this->assertSame(1,preg_match('/name="fingerprint" value="([^"]+)"/',$html,$m));
        $this->assertSame(302,$this->postForm('/members/'.$id.'/anonymize',['version'=>'1','fingerprint'=>$m[1],'reason'=>'Aufbewahrung geprüft','confirmed'=>'1'])->getStatusCode());
        $this->assertStringNotContainsString('Erika',(string)$this->get('/members/'.$id)->getBody());
    }
    public function test_application_decision_preview_does_not_apply_or_send_until_confirmation():void
    {
        $actor=$this->loginAs(['schriftfuehrer']);$repo=new ApplicationRepository($this->pdo);$invite=$repo->createInvite($actor);
        $id=$repo->submit($invite['token'],['name'=>'Erika Muster','email'=>'erika@example.org','consent_statute'=>'1','consent_privacy'=>'1']);
        $this->assertSame(200,$this->get('/members/applications/'.$id.'/decision')->getStatusCode());
        $input=['decision'=>'approved','subject'=>'Willkommen','message'=>'Geprüfte Nachricht','reason'=>'','stage'=>'preview'];
        $res=$this->postForm('/members/applications/'.$id.'/decision',$input);$this->assertSame(200,$res->getStatusCode(),(string)$res->getBody());
        $this->assertSame('pending',$repo->findApplication($id)['status']);$this->assertSame(1,(int)$this->pdo->query('SELECT count(*) FROM mail_outbox')->fetchColumn());
        $input['stage']='confirm';$this->assertSame(302,$this->postForm('/members/applications/'.$id.'/decision',$input)->getStatusCode());
        $this->assertSame('approved',$repo->findApplication($id)['status']);$this->assertSame(2,(int)$this->pdo->query('SELECT count(*) FROM mail_outbox')->fetchColumn());
        $this->assertSame(422,$this->postForm('/members/applications/'.$id.'/decision',$input)->getStatusCode());
    }
}
