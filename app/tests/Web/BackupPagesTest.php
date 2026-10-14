<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use PDO;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Tests\TestCase;
use ZipArchive;

final class BackupPagesTest extends TestCase
{
    use WebHarness;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->bootApp();
    }

    public function test_backup_page_requires_vorstand_permission(): void
    {
        // Nicht angemeldet -> Redirect zu /login
        $res = $this->get('/settings/backup');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/login', $res->getHeaderLine('Location'));

        // Benutzer ohne settings.manage (z. B. nur kassenpruefer) -> 403
        $this->loginAs(['auditor'], 'pruefer@example.org');
        $res = $this->get('/settings/backup');
        $this->assertSame(403, $res->getStatusCode());

        // Vorstand -> 200 OK
        $this->loginAs(['admin'], 'vorstand@example.org');
        $res = $this->get('/settings/backup');
        $this->assertSame(200, $res->getStatusCode());
    }

    public function test_backup_page_shows_stats_and_download_forms(): void
    {
        $this->loginAs(['admin']);

        $html = (string) $this->get('/settings/backup')->getBody();
        $this->assertStringContainsString('Backup &amp; Datenexport', $html);
        $this->assertStringContainsString('action="/settings/backup/full"', $html);
        $this->assertStringContainsString('action="/settings/backup/database"', $html);
        $this->assertStringContainsString('action="/settings/backup/csv-all"', $html);
        $this->assertStringContainsString('/settings/backup/accounts.csv', $html);
        $this->assertStringContainsString('/settings/backup/categories.csv', $html);
        $this->assertStringContainsString('/settings/backup/invoices.csv', $html);
        $this->assertStringContainsString('action="/settings/backup/verify"', $html);
        $this->assertStringContainsString('action="/settings/backup/restore"', $html);
    }

    public function test_download_database_backup_delivers_valid_zip(): void
    {
        $this->loginAs(['admin']);

        $res = $this->postForm('/settings/backup/database', []);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('application/zip', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment; filename="vereinskiste-datenbank-', $res->getHeaderLine('Content-Disposition'));

        $body = (string) $res->getBody();
        $tmp = tempnam(sys_get_temp_dir(), 'vk_test_db_zip');
        file_put_contents($tmp, $body);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tmp, ZipArchive::RDONLY));
        $this->assertNotFalse($zip->locateName('manifest.json'));
        $this->assertNotFalse($zip->locateName('database/schema.json'));
        $zip->close();
        @unlink($tmp);
    }

    public function test_download_full_backup_delivers_zip(): void
    {
        $this->loginAs(['admin']);

        $res = $this->postForm('/settings/backup/full', []);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('application/zip', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment; filename="vereinskiste-backup-', $res->getHeaderLine('Content-Disposition'));
    }

    public function test_download_csv_package_and_single_csvs(): void
    {
        $this->loginAs(['admin']);

        $res = $this->postForm('/settings/backup/csv-all', []);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('application/zip', $res->getHeaderLine('Content-Type'));

        $resAccounts = $this->get('/settings/backup/accounts.csv');
        $this->assertSame(200, $resAccounts->getStatusCode());
        $this->assertSame('text/csv; charset=utf-8', $resAccounts->getHeaderLine('Content-Type'));

        $resCategories = $this->get('/settings/backup/categories.csv');
        $this->assertSame(200, $resCategories->getStatusCode());
        $this->assertSame('text/csv; charset=utf-8', $resCategories->getHeaderLine('Content-Type'));

        $resInvoices = $this->get('/settings/backup/invoices.csv');
        $this->assertSame(200, $resInvoices->getStatusCode());
        $this->assertSame('text/csv; charset=utf-8', $resInvoices->getHeaderLine('Content-Type'));

        $resInvoicesZip = $this->get('/settings/backup/invoices.zip');
        $this->assertSame(200, $resInvoicesZip->getStatusCode());
        $this->assertSame('application/zip', $resInvoicesZip->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment; filename="vereinskiste-rechnungen-', $resInvoicesZip->getHeaderLine('Content-Disposition'));
    }

    public function test_restore_without_confirmation_phrase_is_rejected(): void
    {
        $this->loginAs(['admin']);

        $res = $this->postForm('/settings/backup/restore', [
            'confirm_overwrite' => '1',
            'confirm_phrase' => 'FALSCH',
        ]);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/settings/backup', $res->getHeaderLine('Location'));

        $html = (string) $this->get('/settings/backup')->getBody();
        $this->assertStringContainsString('Bitte bestätige das Überschreiben und gib das Bestätigungswort', $html);
    }

    public function test_verify_and_restore_with_uploaded_zip(): void
    {
        $this->loginAs(['admin']);

        // Zuerst ein echtes DB-Backup herunterladen
        $backupRes = $this->postForm('/settings/backup/database', []);
        $zipBytes = (string) $backupRes->getBody();

        $tmpUploadVerify = tempnam(sys_get_temp_dir(), 'vk_v_');
        file_put_contents($tmpUploadVerify, $zipBytes);
        $uploadedFile = new UploadedFile($tmpUploadVerify, 'test-backup.zip', 'application/zip', strlen($zipBytes), UPLOAD_ERR_OK);

        // Verify per POST mit Datei
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/settings/backup/verify')
            ->withParsedBody(['_csrf' => $this->csrf()])
            ->withUploadedFiles(['backup_file' => $uploadedFile]);
        $verifyRes = $this->app->handle($req);
        $this->assertSame(200, $verifyRes->getStatusCode());
        $html = (string) $verifyRes->getBody();
        $this->assertStringContainsString('Ergebnis der Backup-Prüfung', $html);
        $this->assertStringContainsString('Das Backup-Archiv ist intakt', $html);

        // Restore per POST mit Datei und Bestätigung
        $tmpUploadRestore = tempnam(sys_get_temp_dir(), 'vk_r_');
        file_put_contents($tmpUploadRestore, $zipBytes);
        $uploadedFileRestore = new UploadedFile($tmpUploadRestore, 'test-backup.zip', 'application/zip', strlen($zipBytes), UPLOAD_ERR_OK);

        $reqRestore = (new ServerRequestFactory())->createServerRequest('POST', '/settings/backup/restore')
            ->withParsedBody([
                '_csrf' => $this->csrf(),
                'confirm_overwrite' => '1',
                'confirm_phrase' => 'WIEDERHERSTELLEN',
            ])
            ->withUploadedFiles(['backup_file' => $uploadedFileRestore]);
        $restoreRes = $this->app->handle($reqRestore);
        $this->assertSame(302, $restoreRes->getStatusCode());
        $this->assertSame('/settings/backup', $restoreRes->getHeaderLine('Location'));

        // Nach Restore prüfen wir die Erfolgsmeldung
        $html = (string) $this->get('/settings/backup')->getBody();
        $this->assertStringContainsString('Das Backup wurde erfolgreich wiederhergestellt', $html);
    }
}
