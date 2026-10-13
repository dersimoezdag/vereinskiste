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
        $this->assertSame(200,$this->get('/receipt-inbox')->getStatusCode());
        $this->assertSame(403,$this->get('/settings/payments')->getStatusCode());
        $this->assertSame(403,$this->postForm('/invoices/1/payment',['action'=>'approve','version'=>'1'])->getStatusCode());
        $this->assertSame(403,$this->postForm('/invoices/1/ocr/1',['text'=>'fake'])->getStatusCode());
        $this->assertSame(404,$this->get('/settings/receipt-mailbox')->getStatusCode());
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
