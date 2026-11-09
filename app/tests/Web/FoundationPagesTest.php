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
        $this->loginAs(['admin']);
        $this->assertSame(200, $this->get('/settings/club')->getStatusCode());
        $values = (new Profile($this->pdo))->get()['data'];
        $values['name'] = 'Neuer Verein'; $values['version'] = '1'; $values['reason'] = 'Profil eingerichtet';
        $this->assertSame('/settings/club', $this->postForm('/settings/club', $values)->getHeaderLine('Location'));
        $this->assertSame(409, $this->postForm('/settings/club', $values)->getStatusCode());
        $values['version'] = '2'; $values['website_url'] = 'javascript:alert(1)';
        $this->assertSame(422, $this->postForm('/settings/club', $values)->getStatusCode());
        $this->pdo->exec("DELETE FROM user_roles WHERE role='board'; UPDATE user_roles SET role='treasurer'");
        $this->assertSame(403, $this->get('/settings/club')->getStatusCode());
        $this->assertSame(200, $this->get('/contacts')->getStatusCode());
    }

    public function test_contact_create_duplicate_warning_archive_readonly_and_csv(): void
    {
        $this->loginAs(['treasurer']);
        $values = ['name' => '=Lieferant', 'kind' => 'company', 'email' => 'firma@example.org', 'reason' => 'Neuer Kontakt'];
        $this->assertSame('/contacts/1', $this->postForm('/contacts/new', $values)->getHeaderLine('Location'));
        $this->assertSame(422, $this->postForm('/contacts/new', $values)->getStatusCode());
        $this->assertSame('/contacts/2', $this->postForm('/contacts/new', $values + ['duplicate_confirmed' => '1'])->getHeaderLine('Location'));
        $this->assertSame('/contacts/1', $this->postForm('/contacts/1', $values + ['version' => '1'])->getHeaderLine('Location'));
        $this->assertStringNotContainsString('href="/contacts/1"', (string) $this->get('/contacts')->getBody());
        $this->assertStringContainsString("'=Lieferant", (string) $this->get('/contacts.csv')->getBody());
        $this->pdo->exec("DELETE FROM user_roles WHERE role='board'; UPDATE user_roles SET role='auditor'");
        $this->assertSame(403, $this->postForm('/contacts/new', $values)->getStatusCode());
        $this->assertStringNotContainsString('action="/contacts/2"', (string) $this->get('/contacts/2')->getBody());
    }

    public function test_logo_upload_and_file_reference_permission(): void
    {
        $this->loginAs(['admin']);
        $path = $this->tmpFile($this->pngBytes(), 'logo.png');
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/settings/club/logo')
            ->withParsedBody(['_csrf' => $this->csrf(), 'version' => '1', 'reason' => 'Vereinslogo'])
            ->withUploadedFiles(['receipt' => new UploadedFile($path, 'logo.png', 'image/png', filesize($path), UPLOAD_ERR_OK)]);
        $this->assertSame('/settings/club', $this->app->handle($request)->getHeaderLine('Location'));
        $this->assertSame(200, $this->get('/documents/1')->getStatusCode());
        $this->assertSame(404, $this->get('/documents/999')->getStatusCode());
        $this->pdo->exec("DELETE FROM user_roles WHERE role='board'; UPDATE user_roles SET role='auditor'");
        $this->assertSame(404, $this->get('/documents/1')->getStatusCode());
    }

    public function test_svg_logo_upload_dark_mode_and_public_access(): void
    {
        $this->loginAs(['admin']);
        $version = (string) (new Profile($this->pdo))->get()['version'];
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="navy"/></svg>';
        $path = $this->tmpFile($svg, 'logo.svg');
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/settings/club/logo')
            ->withParsedBody(['_csrf' => $this->csrf(), 'version' => $version, 'reason' => 'SVG-Vereinslogo'])
            ->withUploadedFiles(['receipt' => new UploadedFile($path, 'logo.svg', 'image/svg+xml', strlen($svg), UPLOAD_ERR_OK)]);
        $this->assertSame('/settings/club', $this->app->handle($request)->getHeaderLine('Location'));

        // Public logo endpoint is available without login
        $this->logout();
        $publicRes = $this->get('/club/logo');
        $this->assertSame(200, $publicRes->getStatusCode());
        $this->assertStringContainsString('image/svg+xml', $publicRes->getHeaderLine('Content-Type'));
        $this->assertSame('inline', $publicRes->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('sandbox', $publicRes->getHeaderLine('Content-Security-Policy'));

        // When only light logo is present, requesting dark logo falls back to light
        $darkFallback = $this->get('/club/logo-dark');
        $this->assertSame(200, $darkFallback->getStatusCode());

        // Upload dark mode logo
        $this->loginAs(['admin']);
        $version = (string) (new Profile($this->pdo))->get()['version'];
        $darkSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="white"/></svg>';
        $darkPath = $this->tmpFile($darkSvg, 'logo-dark.svg');
        $darkRequest = (new ServerRequestFactory())->createServerRequest('POST', '/settings/club/logo-dark')
            ->withParsedBody(['_csrf' => $this->csrf(), 'version' => $version, 'reason' => 'SVG-Darklogo'])
            ->withUploadedFiles(['receipt' => new UploadedFile($darkPath, 'logo-dark.svg', 'image/svg+xml', strlen($darkSvg), UPLOAD_ERR_OK)]);
        $this->assertSame('/settings/club', $this->app->handle($darkRequest)->getHeaderLine('Location'));

        $this->logout();
        $darkRes = $this->get('/club/logo-dark');
        $this->assertSame(200, $darkRes->getStatusCode());
        $this->assertStringContainsString('white', (string) $darkRes->getBody());

        // Remove dark logo
        $this->loginAs(['admin']);
        $version = (string) (new Profile($this->pdo))->get()['version'];
        $delRequest = (new ServerRequestFactory())->createServerRequest('POST', '/settings/club/logo-delete')
            ->withParsedBody(['_csrf' => $this->csrf(), 'version' => $version, 'target' => 'dark', 'reason' => 'Darklogo entfernt']);
        $this->assertSame('/settings/club', $this->app->handle($delRequest)->getHeaderLine('Location'));
        $this->assertNull((new Profile($this->pdo))->get()['logo_dark_id']);
    }

    public function test_malicious_svg_rejected(): void
    {
        $this->loginAs(['admin']);
        $version = (string) (new Profile($this->pdo))->get()['version'];
        $badSvg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $path = $this->tmpFile($badSvg, 'bad.svg');
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/settings/club/logo')
            ->withParsedBody(['_csrf' => $this->csrf(), 'version' => $version, 'reason' => 'Bad SVG'])
            ->withUploadedFiles(['receipt' => new UploadedFile($path, 'bad.svg', 'image/svg+xml', strlen($badSvg), UPLOAD_ERR_OK)]);
        $res = $this->app->handle($request);
        $this->assertSame(422, $res->getStatusCode());

        $badSvg2 = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><circle cx="5" cy="5" r="5"/></svg>';
        $path2 = $this->tmpFile($badSvg2, 'bad2.svg');
        $request2 = (new ServerRequestFactory())->createServerRequest('POST', '/settings/club/logo')
            ->withParsedBody(['_csrf' => $this->csrf(), 'version' => $version, 'reason' => 'Bad SVG 2'])
            ->withUploadedFiles(['receipt' => new UploadedFile($path2, 'bad2.svg', 'image/svg+xml', strlen($badSvg2), UPLOAD_ERR_OK)]);
        $this->assertSame(422, $this->app->handle($request2)->getStatusCode());
    }
}
