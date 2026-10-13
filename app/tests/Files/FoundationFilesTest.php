<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Files;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Files\FileStorage;
use Vereinskiste\Files\StoredFileRepository;
use Vereinskiste\Files\UnregisteredUploads;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Receipts\ReceiptStore;
use Vereinskiste\Tests\Receipts\ReceiptFiles;
use Vereinskiste\Tests\TestCase;

final class FoundationFilesTest extends TestCase
{
    use ReceiptFiles;
    private PDO $db;
    protected function setUp(): void
    {
        $this->db = $this->pdo();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->db->exec("INSERT INTO users(name,email,password_hash) VALUES ('Admin','admin@example.org','hash')");
        $this->db->exec("INSERT INTO accounts(name,type,opening_date) VALUES ('Kasse','cash','2026-01-01')");
        $this->db->exec("INSERT INTO bookings(account_id,booking_date,amount,direction,description,created_by)
            VALUES (1,'2026-10-12',10,'in','Spende',1)");
    }
    protected function tearDown(): void { $this->cleanScratch(); }

    public function test_receipt_registration_repeats_without_moving_original_or_changing_hash(): void
    {
        $dir = $this->scratchDir() . '/receipts';
        $receipt = (new ReceiptStore($this->db, $dir))->add(1, $this->tmpFile($this->pdfBytes()), 'original.pdf', 1);
        $this->db->exec('UPDATE receipts SET stored_file_id=NULL');
        $registry = new StoredFileRepository($this->db);
        $this->assertSame(1, $registry->registerReceipts());
        $this->assertSame(0, $registry->registerReceipts());
        $this->assertSame(1, (int) $this->db->query('SELECT count(*) FROM stored_files')->fetchColumn());
        $this->assertSame($receipt['sha256'], hash_file('sha256', $dir . '/' . $receipt['storage_name']));
        $this->assertSame($receipt['storage_name'], $this->db->query('SELECT storage_name FROM stored_files')->fetchColumn());
    }

    public function test_safe_invoice_xml_allowed_only_explicitly_and_dtd_rejected(): void
    {
        $storage = new FileStorage($this->scratchDir() . '/documents');
        $xml = $this->tmpFile('<?xml version="1.0"?><Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"/>');
        $file = $storage->ingest($xml, ['application/xml']);
        $this->assertSame('application/xml', $file['mime']);
        try { $storage->ingest($xml); $this->fail('Belege akzeptieren nicht automatisch XML'); }
        catch (ValidationError $e) { $this->assertArrayHasKey('receipt', $e->errors); }
        $bad = $this->tmpFile('<?xml version="1.0"?><!DOCTYPE Invoice [<!ENTITY x SYSTEM "file:///etc/passwd">]>
            <Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2">&x;</Invoice>');
        $this->expectException(ValidationError::class); $storage->ingest($bad, ['application/xml']);
    }

    public function test_cleanup_preserves_registered_and_recent_files(): void
    {
        $dir = $this->scratchDir() . '/documents';
        $file = (new FileStorage($dir))->ingest($this->tmpFile($this->pdfBytes()));
        (new StoredFileRepository($this->db))->register($file, 'documents', 'protected.pdf', 1);
        touch($dir . '/' . $file['storage_name'], time() - 172800);
        $orphan = str_repeat('a', 32) . '.pdf'; file_put_contents($dir . '/' . $orphan, $this->pdfBytes());
        touch($dir . '/' . $orphan, time() - 172800);
        $recent = str_repeat('b', 32) . '.pdf'; file_put_contents($dir . '/' . $recent, $this->pdfBytes());
        $this->assertSame(1, (new UnregisteredUploads($this->db, $dir))->clean());
        $this->assertFileExists($dir . '/' . $file['storage_name']); $this->assertFileExists($dir . '/' . $recent);
        $this->assertFileDoesNotExist($dir . '/' . $orphan);
    }
}
