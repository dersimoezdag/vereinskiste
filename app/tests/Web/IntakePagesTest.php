<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Web;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Intake\MailboxSettings;
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
    public function test_reader_sees_escaped_email_but_cannot_configure_or_approve():void
    {
        $this->loginAs(['kassenpruefer']);
        $this->pdo->prepare("INSERT INTO receipt_mail_messages(mailbox_key,uid_validity,uid,sender,subject,status) VALUES(?,1,1,? ,?,'rejected')")
            ->execute([str_repeat('a',64),'sender@example.org','<script>alert(1)</script>']);
        $html=(string)$this->get('/receipt-inbox')->getBody();
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;',$html);$this->assertStringNotContainsString('<script>alert(1)</script>',$html);
        $this->assertSame(403,$this->get('/settings/receipt-mailbox')->getStatusCode());
        $this->assertSame(403,$this->postForm('/invoices/1/payment',['action'=>'approve','version'=>'1'])->getStatusCode());
        $this->assertSame(403,$this->postForm('/receipt-inbox/documents/1/retry',[])->getStatusCode());
    }
    public function test_settings_never_render_password_and_csrf_is_required():void
    {
        $actor=$this->loginAs(['admin']);
        (new MailboxSettings($this->scratchDir().'/config'))->save(['host'=>'mail.example.org','username'=>'belege@example.org','folder'=>'INBOX','actor_id'=>(string)$actor,'password'=>'private-secret','enabled'=>'1'],0);
        $html=(string)$this->get('/settings/receipt-mailbox')->getBody();
        $this->assertStringNotContainsString('private-secret',$html);$this->assertStringContainsString('belege@example.org',$html);
        $res=$this->app->handle((new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST','/settings/receipt-mailbox')->withParsedBody(['version'=>'1']));
        $this->assertSame(403,$res->getStatusCode());
    }
}
