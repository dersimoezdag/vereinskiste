<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Web;

use PDO;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;
use Vereinskiste\BankImport\ParseService;
use Vereinskiste\BankImport\Repository;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Tests\Receipts\ReceiptFiles;
use Vereinskiste\Tests\TestCase;

final class BankImportPagesTest extends TestCase
{
    use WebHarness;
    use ReceiptFiles;
    private PDO $pdo;
    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->pdo->exec("INSERT INTO accounts(name,type,opening_date) VALUES ('Bank','bank','2026-01-01')");
        $this->store = new ArraySessionStore();
        $this->app = Kernel::create(['pdo' => $this->pdo,'session_store' => $this->store,'require_https' => false,
            'security_dir' => $this->securityDir(),'documents_dir' => $this->scratchDir() . '/documents']);
    }
    protected function tearDown(): void { $this->cleanScratch(); }
    public function test_upload_preview_confirmation_permissions_and_private_original(): void
    {
        $this->loginAs(['kassenwart']);
        $this->assertSame(200, $this->get('/imports/bank/new')->getStatusCode());
        $path = $this->tmpFile("Datum;Betrag;Name\n02.01.2026;-10,50;<script>alert(1)</script>\n");
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/imports/bank/new')
            ->withParsedBody(['_csrf' => $this->csrf(),'account_id' => '1'])
            ->withUploadedFiles(['receipt' => new UploadedFile($path, 'bank.csv','text/csv',filesize($path))]);
        $response = $this->app->handle($req);
        $this->assertSame(302, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('/imports/bank/1', $response->getHeaderLine('Location'));
        $this->assertStringContainsString('&lt;script&gt;', (string) $this->get('/imports/bank/1')->getBody());
        $this->assertSame(200, $this->get('/documents/1')->getStatusCode());
        $this->assertSame(422, $this->postForm('/imports/bank/1/mapping', ['version' => '1'])->getStatusCode());
        $this->assertSame(302, $this->postForm('/imports/bank/1/mapping', ['version' => '1','booking_date' => '0',
            'amount' => '1','counterparty' => '2','date_format' => 'd.m.Y','decimal_format' => 'comma','account_checked' => '1'])->getStatusCode());
        (new ParseService($this->pdo, $this->scratchDir() . '/documents'))->step(1);
        $status = $this->get('/imports/bank/1/status'); $this->assertSame('no-store', $status->getHeaderLine('Cache-Control'));
        $this->assertSame('review', json_decode((string) $status->getBody(), true)['status']);
        $this->assertSame(200, $this->get('/imports/bank/1')->getStatusCode());
        $version = (string) (new Repository($this->pdo))->find(1)['version'];
        $this->assertSame(422, $this->postForm('/imports/bank/1/confirm', ['version' => $version])->getStatusCode());
        $this->pdo->exec("UPDATE user_roles SET role='kassenpruefer'");
        $this->assertSame(200, $this->get('/imports/bank/1')->getStatusCode());
        $this->assertSame(403, $this->postForm('/imports/bank/1/confirm', ['version' => $version,'checked' => '1'])->getStatusCode());
        $this->pdo->exec("UPDATE user_roles SET role='schriftfuehrer'");
        $this->assertSame(403, $this->get('/imports/bank/1')->getStatusCode());
        $this->assertSame(404, $this->get('/documents/1')->getStatusCode());
    }
    public function test_missing_csrf_cannot_upload_or_confirm(): void
    {
        $this->loginAs(['kassenwart']);
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/imports/bank/new')->withParsedBody(['account_id' => '1']);
        $this->assertSame(403, $this->app->handle($req)->getStatusCode());
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM bank_imports')->fetchColumn());
    }
}
