<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Web;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Tests\TestCase;
use Vereinskiste\Tests\Receipts\ReceiptFiles;

final class IntakePagesTest extends TestCase
{
    use WebHarness;
    use ReceiptFiles;
    private PDO $pdo;
    protected function setUp():void
    {
        $this->pdo=$this->pdo();(new Migrator($this->pdo,dirname(__DIR__,2).'/migrations'))->migrate();$this->store=new ArraySessionStore();
        $this->app=Kernel::create(['app_dir'=>$this->scratchDir(),'pdo'=>$this->pdo,'session_store'=>$this->store,'require_https'=>false,'security_dir'=>$this->securityDir()]);
    }
    protected function tearDown():void {$this->cleanScratch();}
    public function test_reader_cannot_configure_payments_or_submit_ocr():void
    {
        $this->loginAs(['kassenpruefer']);
        $redirect=$this->get('/receipt-inbox');
        $this->assertSame(302,$redirect->getStatusCode());
        $this->assertSame('/invoices?state=draft',$redirect->getHeaderLine('Location'));
        $this->assertSame(200,$this->get('/invoices?state=draft')->getStatusCode());
        $this->assertSame(403,$this->get('/settings/payments')->getStatusCode());
        $this->assertSame(403,$this->postForm('/invoices/1/payment',['action'=>'approve','version'=>'1'])->getStatusCode());
        $this->assertSame(403,$this->postForm('/invoices/1/ocr/1',['text'=>'fake'])->getStatusCode());
        $this->assertSame(404,$this->get('/settings/receipt-mailbox')->getStatusCode());
    }
    public function test_finance_navigation_groups_existing_functions_without_duplicate_inbox():void
    {
        $this->loginAs(['admin']);
        $html=(string)$this->get('/bookings')->getBody();
        $this->assertSame(1,preg_match('/id="cashbook-menu">(.*?)<\/div>/s',$html,$match));
        $this->assertSame(3,substr_count($match[1],'dropdown-item'));
        foreach(['/bookings','/accounts','/invoices'] as $href) {$this->assertStringContainsString('href="'.$href.'"',$match[1]);}
        $this->assertStringNotContainsString('/receipt-inbox',$match[1]);
        $this->assertStringContainsString('href="/imports/bank"',$html);
        $master=(string)$this->get('/accounts')->getBody();
        foreach(['/categories','/contacts'] as $href) {$this->assertStringContainsString('href="'.$href.'"',$master);}
        $this->assertSame(200,$this->get('/settings/closing')->getStatusCode());
    }
    public function test_booking_reader_can_download_only_assigned_invoice_originals():void
    {
        $actor=$this->loginAs(['schriftfuehrer']);
        $directory=$this->scratchDir().'/documents';
        $invoice=(new \Vereinskiste\Invoices\Import($this->pdo,$directory))->upload($this->tmpFile(\Vereinskiste\Tests\Invoices\InvoicesTest::xml()),'original.xml',$actor);
        $file=(new \Vereinskiste\Invoices\InvoiceRepository($this->pdo))->documents($invoice)[0]['file_id'];
        $this->assertSame(404,$this->get('/documents/'.$file)->getStatusCode());
        $this->pdo->exec("INSERT INTO accounts(name,type,opening_date) VALUES('Kasse','cash','2026-01-01')");
        $this->pdo->prepare("INSERT INTO bookings(account_id,booking_date,amount,direction,description,created_by) VALUES(1,'2026-10-13',10,'out','Belegzahlung',?)")->execute([$actor]);
        $this->pdo->prepare("INSERT INTO invoice_payment_requests(invoice_id,kind,recipient,iban,purpose,created_by) VALUES(?,'supplier','Lieferant','DE89370400440532013000','Beleg',?)")->execute([$invoice,$actor]);
        $this->pdo->prepare("INSERT INTO invoice_payment_allocations(invoice_id,booking_id,amount,actor_id,reason) VALUES(?,1,10,?,'Zuordnung')")->execute([$invoice,$actor]);
        $html=(string)$this->get('/bookings/1')->getBody();
        $this->assertStringContainsString('href="/documents/'.$file.'"',$html);
        $this->assertStringNotContainsString('href="/invoices/'.$invoice.'"',$html);
        $this->assertSame(403,$this->get('/invoices/'.$invoice)->getStatusCode());
        // Download uses the configured documents directory, independent of the original importer.
        $this->app=Kernel::create(['app_dir'=>$this->scratchDir(),'pdo'=>$this->pdo,'session_store'=>$this->store,'require_https'=>false,'security_dir'=>$this->securityDir(),'documents_dir'=>$directory]);
        $this->assertSame(200,$this->get('/documents/'.$file)->getStatusCode());
        $this->pdo->exec("UPDATE invoice_documents SET security_status='blocked'");
        $this->assertSame(404,$this->get('/documents/'.$file)->getStatusCode());
        $this->pdo->exec("UPDATE invoice_documents SET security_status='validated'; DELETE FROM invoice_payment_allocations");
        $this->assertSame(404,$this->get('/documents/'.$file)->getStatusCode());
    }
    public function test_payment_policy_is_configurable_versioned_and_audited():void
    {
        $this->loginAs(['admin']);
        $this->assertSame(200,$this->get('/settings/payments')->getStatusCode());
        $this->assertFalse((new \Vereinskiste\Invoices\PaymentPolicy($this->pdo))->get()['four_eyes']);
        $this->assertSame(302,$this->postForm('/settings/payments',['version'=>'1','four_eyes'=>'1','reason'=>'Vereinsbeschluss'])->getStatusCode());
        $this->assertTrue((new \Vereinskiste\Invoices\PaymentPolicy($this->pdo))->get()['four_eyes']);
        $this->assertSame(409,$this->postForm('/settings/payments',['version'=>'1','reason'=>'Veraltet'])->getStatusCode());
        $this->assertSame(1,(int)$this->pdo->query("SELECT count(*) FROM foundation_changes WHERE subject='payment_policy'")->fetchColumn());
    }
    public function test_payment_settings_require_csrf():void
    {
        $this->loginAs(['admin']);
        $res=$this->app->handle((new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST','/settings/payments')->withParsedBody(['version'=>'1']));
        $this->assertSame(403,$res->getStatusCode());
    }
}
