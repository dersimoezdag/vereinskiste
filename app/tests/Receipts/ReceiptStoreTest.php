<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Receipts;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\BookingNotFound;
use Vereinskiste\Ledger\BookingRepository;
use Vereinskiste\Ledger\BookingService;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Receipts\ReceiptNotFound;
use Vereinskiste\Receipts\ReceiptStore;
use Vereinskiste\Tests\TestCase;

final class ReceiptStoreTest extends TestCase
{
    use ReceiptFiles;

    private PDO $pdo;
    private int $userId;
    private int $bookingId;
    private string $dir;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->userId = (int) $this->pdo->query(
            "INSERT INTO users (email, name, password_hash) VALUES ('k@example.org', 'Kassenwart', 'x') RETURNING id"
        )->fetchColumn();
        $cash = (int) $this->pdo->query(
            "INSERT INTO accounts (name, type, opening_date) VALUES ('Barkasse', 'cash', '2026-01-01') RETURNING id"
        )->fetchColumn();
        $this->bookingId = (new BookingService($this->pdo))->create([
            'account_id' => (string) $cash, 'booking_date' => '2026-03-05', 'amount' => '12,50', 'direction' => 'out',
            'category_id' => '', 'description' => 'Porto', 'counterparty' => '', 'receipt_no' => '',
        ], $this->userId)['id'];
        $this->dir = $this->scratchDir() . '/store';
    }

    protected function tearDown(): void
    {
        $this->cleanScratch();
    }

    private function store(int $max = 10485760): ReceiptStore
    {
        return new ReceiptStore($this->pdo, $this->dir, $max);
    }

    /** @return list<string> */
    private function storedFiles(): array
    {
        return is_dir($this->dir) ? array_values(array_diff(scandir($this->dir), ['.', '..'])) : [];
    }

    private function assertRejected(string $path, string $name, string $errorKey): void
    {
        try {
            $this->store()->add($this->bookingId, $path, $name, $this->userId);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertSame($errorKey, $e->errors['receipt'] ?? null);
        }
        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM receipts')->fetchColumn());
    }

    public function test_rejects_executable_renamed_to_pdf(): void
    {
        $elf = "\x7FELF\x02\x01\x01\x00" . str_repeat("\x00", 120);
        $this->assertRejected($this->tmpFile($elf, 'rechnung.pdf'), 'rechnung.pdf', 'validation.receipt.type');
        $this->assertRejected($this->tmpFile("#!/bin/sh\necho hi\n", 'x.png'), 'x.png', 'validation.receipt.type');
        $this->assertRejected($this->tmpFile('<html><script>1</script></html>', 'x.jpg'), 'x.jpg', 'validation.receipt.type');
    }

    public function test_rejects_over_10mb(): void
    {
        $path = $this->tmpFile($this->pdfBytes(str_repeat('a', 10485760)), 'gross.pdf');
        $this->assertGreaterThan(10485760, filesize($path));
        $this->assertRejected($path, 'gross.pdf', 'validation.receipt.too_large');

        // Genau am Limit ist erlaubt.
        $exact = $this->pdfBytes();
        $ok = $this->store(strlen($exact))->add($this->bookingId, $this->tmpFile($exact), 'ok.pdf', $this->userId);
        $this->assertSame(strlen($exact), $ok['size']);
    }

    public function test_rejects_empty_file(): void
    {
        $this->assertRejected($this->tmpFile(''), 'leer.pdf', 'validation.receipt.empty');
    }

    public function test_rejects_heic_with_clear_message(): void
    {
        $this->assertRejected($this->tmpFile($this->heicBytes(), 'IMG_1.jpg'), 'IMG_1.jpg', 'validation.receipt.heic');
    }

    public function test_rejects_image_above_40_megapixels_without_decoding(): void
    {
        // 50 MP; die Datei hat keine Bilddaten, ein Dekodierversuch wuerde anders scheitern.
        $this->assertRejected($this->tmpFile($this->pngBytes(10000, 5000)), 'riesig.png', 'validation.receipt.megapixels');
        $this->assertRejected($this->tmpFile($this->jpegBytes(8000, 6000)), 'riesig.jpg', 'validation.receipt.megapixels');

        // 40 MP genau ist erlaubt.
        $ok = $this->store()->add($this->bookingId, $this->tmpFile($this->pngBytes(8000, 5000)), 'ok.png', $this->userId);
        $this->assertSame('image/png', $ok['mime']);
    }

    public function test_rejects_image_without_readable_dimensions(): void
    {
        $this->assertRejected($this->tmpFile("\x89PNG\r\n\x1a\nkaputt"), 'kaputt.png', 'validation.receipt.type');
    }

    public function test_stores_sha256_and_random_storage_name(): void
    {
        $bytes = $this->pdfBytes('inhalt');
        $path = $this->tmpFile($bytes, 'passwd.pdf');
        $one = $this->store()->add($this->bookingId, $path, '../../etc/passwd.pdf', $this->userId);
        $two = $this->store()->add($this->bookingId, $path, '../../etc/passwd.pdf', $this->userId);

        $this->assertSame(hash('sha256', $bytes), trim($one['sha256']));
        $this->assertSame('application/pdf', $one['mime']);
        $this->assertSame(strlen($bytes), $one['size']);
        $this->assertSame('passwd.pdf', $one['original_name']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', $one['storage_name']);
        $this->assertNotSame($one['storage_name'], $two['storage_name']);
        $this->assertFileExists($this->dir . '/' . $one['storage_name']);
        $this->assertSame(hash('sha256', $bytes), hash_file('sha256', $this->dir . '/' . $one['storage_name']));
        $this->assertCount(2, $this->storedFiles(), 'keine Temp-Dateien uebrig');
        $this->assertSame($this->userId, $one['uploaded_by']);
        $this->assertSame($this->bookingId, $one['booking_id']);
        $this->assertNull($one['replaced_at']);
    }

    public function test_add_records_change_log_entry_and_works_on_cancelled_booking(): void
    {
        $this->pdo->exec("UPDATE bookings SET status = 'cancelled' WHERE id = {$this->bookingId}");
        $this->store()->add($this->bookingId, $this->tmpFile($this->jpegBytes()), 'Quittung.jpg', $this->userId);

        $row = $this->pdo->query('SELECT changes::text AS c, reason, changed_by FROM booking_changes')->fetch();
        $this->assertSame('Beleg hinzugefügt', $row['reason']);
        $this->assertSame($this->userId, (int) $row['changed_by']);
        $this->assertEquals(
            [['field' => 'receipt', 'from' => null, 'to' => 'Quittung.jpg']],
            json_decode($row['c'], true)
        );
    }

    public function test_add_to_unknown_booking_fails_without_file(): void
    {
        try {
            $this->store()->add(999999, $this->tmpFile($this->pdfBytes()), 'a.pdf', $this->userId);
            $this->fail('BookingNotFound erwartet');
        } catch (BookingNotFound) {
            $this->assertSame([], $this->storedFiles());
        }
    }

    public function test_database_failure_removes_stored_file(): void
    {
        try {
            $this->store()->add($this->bookingId, $this->tmpFile($this->pdfBytes()), 'a.pdf', 987654);
            $this->fail('PDOException erwartet');
        } catch (\PDOException) {
            // erwartet: Fremdschluessel uploaded_by
        }
        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM receipts')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM booking_changes')->fetchColumn());
    }

    public function test_failed_file_move_leaves_no_row(): void
    {
        $blocker = $this->scratchDir() . '/blocker';
        file_put_contents($blocker, 'x');
        $store = new ReceiptStore($this->pdo, $blocker . '/sub');
        try {
            $store->add($this->bookingId, $this->tmpFile($this->pdfBytes()), 'a.pdf', $this->userId);
            $this->fail('RuntimeException erwartet');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM receipts')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM booking_changes')->fetchColumn());
    }

    public function test_replace_keeps_old_file_marked_replaced(): void
    {
        $old = $this->store()->add($this->bookingId, $this->tmpFile($this->pdfBytes('alt')), 'alt.pdf', $this->userId);
        $new = $this->store()->replace(
            $old['id'], $this->tmpFile($this->jpegBytes()), 'neu.jpg', 'Falsche Datei hochgeladen', $this->userId
        );

        $this->assertSame($this->bookingId, $new['booking_id']);
        $this->assertSame('image/jpeg', $new['mime']);
        $this->assertNull($new['replaced_at']);
        $this->assertFileExists($this->dir . '/' . $old['storage_name']);
        $this->assertFileExists($this->dir . '/' . $new['storage_name']);
        $this->assertNotNull($this->pdo->query("SELECT replaced_at FROM receipts WHERE id = {$old['id']}")->fetchColumn());

        $log = $this->pdo->query(
            "SELECT changes::text AS c, reason FROM booking_changes WHERE reason <> 'Beleg hinzugefügt'"
        )->fetch();
        $this->assertSame('Falsche Datei hochgeladen', $log['reason']);
        $this->assertEquals(
            [['field' => 'receipt', 'from' => 'alt.pdf', 'to' => 'neu.jpg']],
            json_decode($log['c'], true)
        );

        $list = $this->store()->forBooking($this->bookingId);
        $this->assertSame([$old['id'], $new['id']], array_column($list, 'id'));
        $this->assertNotNull($list[0]['replaced_at']);
        $this->assertNull($list[1]['replaced_at']);

        $opened = $this->store()->open($old['id']);
        $this->assertSame('application/pdf', $opened['mime']);
        $this->assertSame('alt.pdf', $opened['name']);
        $this->assertSame($this->dir . '/' . $old['storage_name'], $opened['path']);
    }

    public function test_replace_requires_reason_and_leaves_state_untouched(): void
    {
        $old = $this->store()->add($this->bookingId, $this->tmpFile($this->pdfBytes()), 'alt.pdf', $this->userId);
        foreach (['', '   ', "\u{00A0}\u{200B}"] as $reason) {
            try {
                $this->store()->replace($old['id'], $this->tmpFile($this->jpegBytes()), 'neu.jpg', $reason, $this->userId);
                $this->fail('ValidationError erwartet');
            } catch (ValidationError $e) {
                $this->assertSame('validation.reason.required', $e->errors['reason']);
            }
        }
        $this->assertCount(1, $this->storedFiles());
        $this->assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM receipts')->fetchColumn());
        $this->assertNull($this->pdo->query("SELECT replaced_at FROM receipts WHERE id = {$old['id']}")->fetchColumn());
    }

    public function test_replace_invalid_file_leaves_old_receipt_active(): void
    {
        $old = $this->store()->add($this->bookingId, $this->tmpFile($this->pdfBytes()), 'alt.pdf', $this->userId);
        try {
            $this->store()->replace($old['id'], $this->tmpFile("MZ\x90\x00"), 'x.pdf', 'Grund', $this->userId);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertSame('validation.receipt.type', $e->errors['receipt']);
        }
        $this->assertNull($this->pdo->query("SELECT replaced_at FROM receipts WHERE id = {$old['id']}")->fetchColumn());
        $this->assertCount(1, $this->storedFiles());
    }

    public function test_replace_of_already_replaced_receipt_is_rejected(): void
    {
        $old = $this->store()->add($this->bookingId, $this->tmpFile($this->pdfBytes()), 'alt.pdf', $this->userId);
        $this->store()->replace($old['id'], $this->tmpFile($this->jpegBytes()), 'neu.jpg', 'Grund', $this->userId);
        try {
            $this->store()->replace($old['id'], $this->tmpFile($this->pngBytes()), 'neu2.png', 'Nochmal', $this->userId);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertSame('validation.receipt.already_replaced', $e->errors['receipt']);
        }
        $this->assertCount(2, $this->storedFiles(), 'verworfene Datei wurde entfernt');
    }

    public function test_open_unknown_receipt_throws(): void
    {
        $this->expectException(ReceiptNotFound::class);
        $this->store()->open(4711);
    }

    public function test_original_name_is_sanitised_but_kept_as_data(): void
    {
        $r = $this->store()->add(
            $this->bookingId, $this->tmpFile($this->pdfBytes()), "C:\\Users\\x\\Rech\r\nnung \"2026\".pdf", $this->userId
        );
        $this->assertSame('Rechnung "2026".pdf', $r['original_name']);

        $r = $this->store()->add($this->bookingId, $this->tmpFile($this->pdfBytes()), '', $this->userId);
        $this->assertSame('beleg.pdf', $r['original_name']);
    }

    public function test_rejects_blank_directory(): void
    {
        foreach (['', '   '] as $dir) {
            try {
                new ReceiptStore($this->pdo, $dir);
                $this->fail('InvalidArgumentException erwartet');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_rejects_svg_renamed_to_png(): void
    {
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>';
        $this->assertRejected($this->tmpFile($svg, 'logo.png'), 'logo.png', 'validation.receipt.type');
    }

    public function test_add_without_log_change_writes_no_history_entry(): void
    {
        $r = $this->store()->add($this->bookingId, $this->tmpFile($this->pdfBytes()), 'a.pdf', $this->userId, false);

        $this->assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM receipts')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM booking_changes')->fetchColumn());
        $this->assertFalse((new BookingRepository($this->pdo))->find($this->bookingId)['changed']);
        $this->assertFileExists($this->dir . '/' . $r['storage_name']);
    }

    public function test_later_add_writes_entry_and_marks_booking_changed(): void
    {
        $repo = new BookingRepository($this->pdo);
        $this->assertFalse($repo->find($this->bookingId)['changed']);
        $this->store()->add($this->bookingId, $this->tmpFile($this->pdfBytes()), 'spaeter.pdf', $this->userId);

        $this->assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM booking_changes')->fetchColumn());
        $this->assertTrue($repo->find($this->bookingId)['changed']);
    }

    public function test_stored_file_is_mode_0640_and_size_comes_from_stored_copy(): void
    {
        $r = $this->store()->add($this->bookingId, $this->tmpFile($this->pdfBytes('x')), 'a.pdf', $this->userId);
        $path = $this->dir . '/' . $r['storage_name'];

        $this->assertSame(filesize($path), $r['size']);
        $this->assertSame(0640, fileperms($path) & 0777);
    }
}
