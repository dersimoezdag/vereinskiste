<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use PDO;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;
use Vereinskiste\Club\Profile;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Tests\Receipts\ReceiptFiles;
use Vereinskiste\Tests\TestCase;

final class FoundationPagesTest extends TestCase
{
    use WebHarness;
    use ReceiptFiles;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->store = new ArraySessionStore();
        $this->app = Kernel::create(['pdo' => $this->pdo, 'session_store' => $this->store,
            'require_https' => false, 'security_dir' => $this->securityDir(), 'documents_dir' => $this->scratchDir() . '/documents']);
    }

    protected function tearDown(): void { $this->cleanScratch(); }

    public function test_profile_form_permissions_validation_and_conflict(): void
    {
        $this->loginAs(['vorstand']);
        $this->assertSame(200, $this->get('/settings/club')->getStatusCode());
        $values = (new Profile($this->pdo))->get()['data'];
        $values['name'] = 'Neuer Verein'; $values['version'] = '1'; $values['reason'] = 'Profil eingerichtet';
        $this->assertSame('/settings/club', $this->postForm('/settings/club', $values)->getHeaderLine('Location'));
        $this->assertSame(409, $this->postForm('/settings/club', $values)->getStatusCode());
        $values['version'] = '2'; $values['website_url'] = 'javascript:alert(1)';
        $this->assertSame(422, $this->postForm('/settings/club', $values)->getStatusCode());
        $this->pdo->exec("UPDATE user_roles SET role='kassenwart'");
        $this->assertSame(403, $this->get('/settings/club')->getStatusCode());
        $this->assertSame(200, $this->get('/contacts')->getStatusCode());
    }

    public function test_contact_create_duplicate_warning_archive_readonly_and_csv(): void
    {
        $this->loginAs(['kassenwart']);
        $values = ['name' => '=Lieferant', 'kind' => 'company', 'email' => 'firma@example.org', 'reason' => 'Neuer Kontakt'];
        $this->assertSame('/contacts/1', $this->postForm('/contacts/new', $values)->getHeaderLine('Location'));
        $this->assertSame(422, $this->postForm('/contacts/new', $values)->getStatusCode());
        $this->assertSame('/contacts/2', $this->postForm('/contacts/new', $values + ['duplicate_confirmed' => '1'])->getHeaderLine('Location'));
        $this->assertSame('/contacts/1', $this->postForm('/contacts/1', $values + ['version' => '1'])->getHeaderLine('Location'));
        $this->assertStringNotContainsString('href="/contacts/1"', (string) $this->get('/contacts')->getBody());
        $this->assertStringContainsString("'=Lieferant", (string) $this->get('/contacts.csv')->getBody());
        $this->pdo->exec("UPDATE user_roles SET role='kassenpruefer'");
        $this->assertSame(403, $this->postForm('/contacts/new', $values)->getStatusCode());
        $this->assertStringNotContainsString('action="/contacts/2"', (string) $this->get('/contacts/2')->getBody());
    }

    public function test_logo_upload_and_file_reference_permission(): void
    {
        $this->loginAs(['vorstand']);
        $path = $this->tmpFile($this->pngBytes(), 'logo.png');
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/settings/club/logo')
            ->withParsedBody(['_csrf' => $this->csrf(), 'version' => '1', 'reason' => 'Vereinslogo'])
            ->withUploadedFiles(['receipt' => new UploadedFile($path, 'logo.png', 'image/png', filesize($path), UPLOAD_ERR_OK)]);
        $this->assertSame('/settings/club', $this->app->handle($request)->getHeaderLine('Location'));
        $this->assertSame(200, $this->get('/documents/1')->getStatusCode());
        $this->assertSame(404, $this->get('/documents/999')->getStatusCode());
        $this->pdo->exec("UPDATE user_roles SET role='kassenpruefer'");
        $this->assertSame(404, $this->get('/documents/1')->getStatusCode());
    }
}
