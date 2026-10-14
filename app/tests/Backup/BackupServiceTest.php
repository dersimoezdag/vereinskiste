<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Backup;

use PDO;
use Vereinskiste\Backup\BackupService;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\BookingService;
use Vereinskiste\Tests\Receipts\ReceiptFiles;
use Vereinskiste\Tests\TestCase;
use ZipArchive;

final class BackupServiceTest extends TestCase
{
    use ReceiptFiles;

    private PDO $pdo;
    private string $appDir;
    private string $receiptsDir;
    private string $documentsDir;
    private string $configDir;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();

        $this->appDir = $this->scratchDir() . '/app';
        $this->receiptsDir = $this->scratchDir() . '/receipts';
        $this->documentsDir = $this->scratchDir() . '/documents';
        $this->configDir = $this->scratchDir() . '/config';

        mkdir($this->appDir, 0700, true);
        mkdir($this->receiptsDir, 0700, true);
        mkdir($this->documentsDir, 0700, true);
        mkdir($this->configDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->cleanScratch();
    }

    private function createService(): BackupService
    {
        return new BackupService(
            $this->pdo,
            $this->appDir,
            $this->receiptsDir,
            $this->documentsDir,
            $this->configDir,
            '0.1.13',
            require dirname(__DIR__, 2) . '/src/Text/de.php'
        );
    }

    public function test_get_stats_returns_accurate_metrics(): void
    {
        $this->pdo->exec("INSERT INTO users(name,email,password_hash) VALUES ('Admin','admin@example.org','hash')");
        $this->pdo->exec("INSERT INTO accounts(name,type,opening_date,opening_balance) VALUES ('Girokonto','bank','2026-01-01', 100)");
        $this->pdo->exec("INSERT INTO categories(name) VALUES ('Spenden')");

        file_put_contents($this->receiptsDir . '/beleg1.pdf', 'dummy-receipt');
        file_put_contents($this->documentsDir . '/kontoauszug.csv', 'dummy-csv');

        $service = $this->createService();
        $stats = $service->getStats();

        $this->assertSame(1, $stats['accounts_count']);
        $this->assertSame(1, $stats['categories_count']);
        $this->assertSame(1, $stats['receipts_count']);
        $this->assertSame(1, $stats['documents_count']);
        $this->assertSame(2, $stats['total_files_count']);
        $this->assertGreaterThan(0, $stats['tables_count']);
    }

    public function test_create_database_backup_zip_contains_expected_files(): void
    {
        $this->pdo->exec("INSERT INTO users(name,email,password_hash) VALUES ('Vorstand','vorstand@example.org','hash')");
        $this->pdo->exec("INSERT INTO accounts(name,type,opening_date) VALUES ('Kasse','cash','2026-01-01')");

        $service = $this->createService();
        $zipPath = $service->createDatabaseBackupZip();

        $this->assertFileExists($zipPath);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::RDONLY));

        $this->assertNotFalse($zip->locateName('manifest.json'));
        $this->assertNotFalse($zip->locateName('database.sql'));
        $this->assertNotFalse($zip->locateName('database/schema.json'));
        $this->assertNotFalse($zip->locateName('database/complete.json'));

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $this->assertSame('database', $manifest['type']);
        $this->assertSame('0.1.13', $manifest['version']);

        $zip->close();
        @unlink($zipPath);
    }

    public function test_create_full_backup_zip_includes_storage_and_restores_completely(): void
    {
        $this->pdo->exec("INSERT INTO users(name,email,password_hash) VALUES ('Admin','admin@example.org','hash')");
        $this->pdo->exec("INSERT INTO accounts(name,type,opening_date) VALUES ('Kasse','cash','2026-01-01')");

        $bookingService = new BookingService($this->pdo);
        $booking = $bookingService->create([
            'account_id' => '1',
            'booking_date' => '2026-10-01',
            'amount' => '42,00',
            'direction' => 'in',
            'description' => 'Testbuchung Spende',
        ], 1);

        file_put_contents($this->receiptsDir . '/beleg42.pdf', 'pdf-content-42');
        file_put_contents($this->documentsDir . '/rechnung.pdf', 'pdf-content-rechnung');
        file_put_contents($this->configDir . '/config.php', "<?php return ['test' => true];\n");

        $service = $this->createService();
        $zipPath = $service->createFullBackupZip();

        $this->assertFileExists($zipPath);

        // Prüfung des Archivs
        $verification = $service->verifyBackupZip($zipPath);
        $this->assertTrue($verification['valid']);
        $this->assertTrue($verification['has_storage']);
        $this->assertSame(1, $verification['receipts_count']);
        $this->assertSame(1, $verification['documents_count']);

        // Datenbank und Dateien verändern, um Wiederherstellung zu testen
        $this->pdo->exec("CREATE TABLE extra_table_to_drop (id int PRIMARY KEY)");
        $bookingService->update($booking['id'], ['description' => 'Veränderter Text'], 'Korrekturgrund', 1, 1);
        @unlink($this->receiptsDir . '/beleg42.pdf');
        @unlink($this->documentsDir . '/rechnung.pdf');
        $this->assertFileDoesNotExist($this->receiptsDir . '/beleg42.pdf');

        // Wiederherstellung ausführen
        $restoreResult = $service->restoreBackupZip($zipPath, 1);
        $this->assertGreaterThan(0, $restoreResult['tables_count']);
        $this->assertSame(1, $restoreResult['receipts_restored']);
        $this->assertSame(1, $restoreResult['documents_restored']);

        // Daten und Dateien nach Restore prüfen
        $this->assertNull($this->pdo->query("SELECT to_regclass('public.extra_table_to_drop')")->fetchColumn());
        $restoredBookings = $this->pdo->query("SELECT description, amount FROM bookings")->fetchAll();
        $this->assertCount(1, $restoredBookings);
        $this->assertSame('Testbuchung Spende', $restoredBookings[0]['description']);
        $this->assertSame('42.00', $restoredBookings[0]['amount']);

        $this->assertFileExists($this->receiptsDir . '/beleg42.pdf');
        $this->assertSame('pdf-content-42', file_get_contents($this->receiptsDir . '/beleg42.pdf'));
        $this->assertFileExists($this->documentsDir . '/rechnung.pdf');
        $this->assertSame('pdf-content-rechnung', file_get_contents($this->documentsDir . '/rechnung.pdf'));

        @unlink($zipPath);
    }

    public function test_verify_backup_rejects_corrupted_archive(): void
    {
        $this->pdo->exec("INSERT INTO users(name,email,password_hash) VALUES ('Admin','admin@example.org','hash')");
        $service = $this->createService();
        $zipPath = $service->createDatabaseBackupZip();

        // Archiv manipulieren: eine JSONL-Datei verfälschen
        $tempExtract = $this->scratchDir() . '/corrupted_zip';
        mkdir($tempExtract, 0700, true);
        $zip = new ZipArchive();
        $zip->open($zipPath);
        $zip->extractTo($tempExtract);
        $zip->close();
        @unlink($zipPath);

        // 0.jsonl verändern
        file_put_contents($tempExtract . '/database/0.jsonl', '{"tampered":true}' . "\n");

        // Neu verpacken
        $newZip = new ZipArchive();
        $newZip->open($zipPath, ZipArchive::CREATE);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tempExtract, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $rel = substr($file->getRealPath(), strlen($tempExtract) + 1);
            $newZip->addFile($file->getRealPath(), str_replace('\\', '/', $rel));
        }
        $newZip->close();

        $verification = $service->verifyBackupZip($zipPath);
        $this->assertFalse($verification['valid']);
        $this->assertNotEmpty($verification['errors']);

        @unlink($zipPath);
    }

    public function test_csv_exports_contain_bom_and_proper_columns(): void
    {
        $userId = (int) $this->pdo->query("INSERT INTO users(name,email,password_hash) VALUES ('Export User','export@example.org','hash') RETURNING id")->fetchColumn();

        $this->pdo->exec("INSERT INTO accounts(name,type,opening_date,opening_balance) VALUES ('Vereinskasse','cash','2026-01-01', 50.50)");
        $this->pdo->exec("INSERT INTO categories(name) VALUES ('Mitgliedsbeiträge')");

        // Rechnung mit Dokument anlegen
        $stmt = $this->pdo->prepare("INSERT INTO invoices(kind,supplier_name,number,invoice_date,due_date,net,tax,gross,status,notes,created_by)
            VALUES ('invoice','Stadtwerke','RE-101','2026-10-01','2026-10-15',100.00,19.00,119.00,'reviewed','Stromkosten',?) RETURNING id");
        $stmt->execute([$userId]);
        $invId = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare("INSERT INTO stored_files(root,storage_name,original_name,mime,size,sha256,uploaded_by)
            VALUES ('documents','a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4.pdf','Rechnung Strom.pdf','application/pdf',1234,repeat('a',64),?) RETURNING id");
        $stmt->execute([$userId]);
        $fileId = (int) $stmt->fetchColumn();

        $this->pdo->prepare("INSERT INTO invoice_documents(invoice_id,file_id,original_sha256,role,extraction_status,extraction,security_status)
            VALUES (?,?,repeat('a',64),'original','done','{}'::jsonb,'validated')")->execute([$invId, $fileId]);
        file_put_contents($this->documentsDir . '/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4.pdf', '%PDF-strom');

        // Buchung mit Beleg anlegen
        $bookingService = new BookingService($this->pdo);
        $booking = $bookingService->create([
            'account_id' => '1',
            'booking_date' => '2026-10-02',
            'amount' => '25,00',
            'direction' => 'out',
            'description' => 'Kauf Papier',
        ], $userId);
        $bookingId = (int) $booking['id'];

        $stmt = $this->pdo->prepare("INSERT INTO receipts(booking_id,storage_name,original_name,mime,size,sha256,uploaded_by)
            VALUES (?,'b1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4.pdf','Quittung Papier.pdf','application/pdf',567,repeat('b',64),?) RETURNING id");
        $stmt->execute([$bookingId, $userId]);
        $receiptId = (int) $stmt->fetchColumn();
        file_put_contents($this->receiptsDir . '/b1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4.pdf', '%PDF-quittung');

        $service = $this->createService();

        $accountsCsv = $service->exportAccountsCsv();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $accountsCsv);
        $this->assertStringContainsString('Vereinskasse', $accountsCsv);
        $this->assertStringContainsString('50,50', $accountsCsv);
        $this->assertStringContainsString('Barkasse', $accountsCsv);

        $categoriesCsv = $service->exportCategoriesCsv();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $categoriesCsv);
        $this->assertStringContainsString('Mitgliedsbeiträge', $categoriesCsv);

        $invoicesCsv = $service->exportInvoicesCsv();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $invoicesCsv);
        $this->assertStringContainsString('Stadtwerke', $invoicesCsv);
        $this->assertStringContainsString('RE-101', $invoicesCsv);
        $expectedInvoicePath = 'rechnungen/' . $invId . '_Rechnung_Strom.pdf';
        $expectedReceiptPath = 'belege/' . $bookingId . '_' . $receiptId . '_Quittung_Papier.pdf';
        $this->assertStringContainsString($expectedInvoicePath, $invoicesCsv);

        // Gesamtpaket testen (inkl. rechnungen/ und belege/)
        $zipPath = $service->createCsvPackageZip();
        $this->assertFileExists($zipPath);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::RDONLY));
        $this->assertNotFalse($zip->locateName('buchungen.csv'));
        $this->assertNotFalse($zip->locateName('konten.csv'));
        $this->assertNotFalse($zip->locateName('kategorien.csv'));
        $this->assertNotFalse($zip->locateName('mitglieder.csv'));
        $this->assertNotFalse($zip->locateName('finanzkontakte.csv'));
        $this->assertNotFalse($zip->locateName('rechnungen.csv'));
        $this->assertNotFalse($zip->locateName($expectedInvoicePath));
        $this->assertNotFalse($zip->locateName($expectedReceiptPath));
        $this->assertSame('%PDF-strom', $zip->getFromName($expectedInvoicePath));
        $this->assertSame('%PDF-quittung', $zip->getFromName($expectedReceiptPath));
        $zip->close();
        @unlink($zipPath);

        // Rechnungs-Paket testen (inkl. rechnungen.csv und rechnungen/...)
        $invZipPath = $service->createInvoicesPackageZip();
        $this->assertFileExists($invZipPath);
        $invZip = new ZipArchive();
        $this->assertTrue($invZip->open($invZipPath, ZipArchive::RDONLY));
        $this->assertNotFalse($invZip->locateName('rechnungen.csv'));
        $this->assertNotFalse($invZip->locateName($expectedInvoicePath));
        $this->assertSame('%PDF-strom', $invZip->getFromName($expectedInvoicePath));
        $invZip->close();
        @unlink($invZipPath);
    }
}
