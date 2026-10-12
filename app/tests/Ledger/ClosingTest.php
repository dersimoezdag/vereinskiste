<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Ledger;

use PDO;
use PDOException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Ledger\BookingService;
use Vereinskiste\Ledger\ClosedPeriod;
use Vereinskiste\Ledger\ClosingService;
use Vereinskiste\Ledger\TransferService;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Receipts\ReceiptStore;
use Vereinskiste\Tests\Receipts\ReceiptFiles;
use Vereinskiste\Tests\TestCase;
use Vereinskiste\Tests\Web\WebHarness;

final class ClosingTest extends TestCase
{
    use ReceiptFiles;
    use WebHarness;

    private PDO $pdo;
    private BookingService $bookings;
    private TransferService $transfers;
    private ClosingService $closing;
    private int $userId;
    private int $cash;
    private int $bank;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->bookings = new BookingService($this->pdo);
        $this->transfers = new TransferService($this->pdo);
        $this->closing = new ClosingService($this->pdo);
        $this->userId = (int) $this->pdo->query(
            "INSERT INTO users (email, name, password_hash) VALUES ('v@example.org', 'Vorstand', 'x') RETURNING id"
        )->fetchColumn();
        $this->cash = (int) $this->pdo->query(
            "INSERT INTO accounts (name, type, opening_date) VALUES ('Barkasse', 'cash', '2026-01-01') RETURNING id"
        )->fetchColumn();
        $this->bank = (int) $this->pdo->query(
            "INSERT INTO accounts (name, type, opening_date) VALUES ('Girokonto', 'bank', '2026-01-01') RETURNING id"
        )->fetchColumn();
    }

    protected function tearDown(): void
    {
        $this->cleanScratch();
    }

    /**
     * @param array<string,string> $override
     * @return array<string,string>
     */
    private function form(array $override = []): array
    {
        return $override + [
            'account_id' => (string) $this->cash, 'booking_date' => '2026-03-05', 'amount' => '12,50',
            'direction' => 'out', 'category_id' => '', 'description' => 'Porto', 'counterparty' => '', 'receipt_no' => '',
        ];
    }

    /** @return array<string,mixed> */
    private function booking(string $date = '2026-03-05'): array
    {
        return $this->bookings->create($this->form(['booking_date' => $date]), $this->userId);
    }

    private function rows(string $tableAndWhere): int
    {
        return (int) $this->pdo->query("SELECT count(*) FROM $tableAndWhere")->fetchColumn();
    }

    private function assertClosed(callable $action, string $closedUntil = '2026-03-31'): void
    {
        try {
            $action();
            $this->fail('ClosedPeriod erwartet');
        } catch (ClosedPeriod $e) {
            $this->assertSame($closedUntil, $e->closedUntil);
            $this->assertFalse($this->pdo->inTransaction());
        }
    }

    // --- Sperre -------------------------------------------------------------------------------------------

    public function test_nothing_is_closed_by_default(): void
    {
        $this->assertNull($this->closing->closedUntil());
        // Ohne Abschluss ist jedes Datum erlaubt, das nicht vor dem Eroeffnungsdatum des Kontos liegt.
        $this->pdo->exec("UPDATE accounts SET opening_date = '2019-01-01'");
        $this->assertSame('2020-01-01', $this->booking('2020-01-01')['booking_date']);
    }

    public function test_create_in_closed_period_is_rejected(): void
    {
        $this->closing->close('2026-03-31', $this->userId);

        $this->assertClosed(fn () => $this->booking('2026-03-31'));
        $this->assertClosed(fn () => $this->booking('2026-01-15'));
        $this->assertSame(0, $this->rows('bookings'));
    }

    public function test_create_after_closing_date_is_allowed(): void
    {
        $this->closing->close('2026-03-31', $this->userId);

        $this->assertSame('2026-04-01', $this->booking('2026-04-01')['booking_date']);
    }

    public function test_moving_booking_into_closed_period_is_rejected(): void
    {
        $booking = $this->booking('2026-04-10');
        $this->closing->close('2026-03-31', $this->userId);

        $this->assertClosed(fn () => $this->bookings->update(
            $booking['id'], ['booking_date' => '2026-03-15'], 'Datum korrigiert', $booking['version'], $this->userId
        ));
        $this->assertSame('2026-04-10', $this->bookings->update(
            $booking['id'], ['description' => 'Porto Mai'], 'Text', $booking['version'], $this->userId
        )['booking_date']);
    }

    public function test_moving_booking_out_of_closed_period_is_rejected(): void
    {
        $booking = $this->booking('2026-03-10');
        $this->closing->close('2026-03-31', $this->userId);

        $this->assertClosed(fn () => $this->bookings->update(
            $booking['id'], ['booking_date' => '2026-05-01'], 'Datum korrigiert', $booking['version'], $this->userId
        ));
    }

    public function test_edit_of_booking_in_closed_period_is_rejected(): void
    {
        $booking = $this->booking('2026-03-10');
        $this->closing->close('2026-03-31', $this->userId);
        $changes = $this->rows('booking_changes');

        $this->assertClosed(fn () => $this->bookings->update(
            $booking['id'], ['description' => 'Anders'], 'Tippfehler', $booking['version'], $this->userId
        ));
        $this->assertSame($changes, $this->rows('booking_changes'));
        $this->assertSame('Porto', (string) $this->pdo->query('SELECT description FROM bookings')->fetchColumn());
    }

    public function test_cancel_and_restore_in_closed_period_are_rejected(): void
    {
        $open = $this->booking('2026-03-10');
        $second = $this->booking('2026-03-11');
        $cancelled = $this->bookings->cancel($second['id'], 'Doppelt', $second['version'], $this->userId);
        $this->closing->close('2026-03-31', $this->userId);

        $this->assertClosed(fn () => $this->bookings->cancel($open['id'], 'Doppelt', $open['version'], $this->userId));
        $this->assertClosed(fn () => $this->bookings->restore($cancelled['id'], 'Doch richtig', $cancelled['version'], $this->userId));
        $this->assertSame(
            'active',
            (string) $this->pdo->query("SELECT status FROM bookings WHERE id = {$open['id']}")->fetchColumn()
        );
    }

    public function test_correction_dated_after_closing_is_allowed(): void
    {
        $original = $this->booking('2026-03-10');
        $this->closing->close('2026-03-31', $this->userId);

        $correction = $this->bookings->createCorrection(
            $this->form(['booking_date' => '2026-04-02', 'direction' => 'in']), $original['id'], 'Falsch gebucht', $this->userId
        );

        $this->assertSame('correction', $correction['kind']);
        $this->assertSame($original['id'], $correction['correction_of_id']);
    }

    public function test_correction_dated_inside_closed_period_is_rejected(): void
    {
        $original = $this->booking('2026-03-10');
        $this->closing->close('2026-03-31', $this->userId);

        $this->assertClosed(fn () => $this->bookings->createCorrection(
            $this->form(['booking_date' => '2026-03-31']), $original['id'], 'Falsch gebucht', $this->userId
        ));
        $this->assertSame(1, $this->rows('bookings'));
    }

    public function test_transfer_create_cancel_and_restore_respect_closing(): void
    {
        $open = $this->transfers->create($this->cash, $this->bank, '10,00', '2026-03-10', 'Einzahlung', $this->userId);
        $second = $this->transfers->create($this->cash, $this->bank, '5,00', '2026-03-12', 'Einzahlung', $this->userId);
        $cancelled = $this->transfers->cancel($second[0]['id'], 'Doppelt', $second[0]['version'], $this->userId);
        $this->closing->close('2026-03-31', $this->userId);

        $this->assertClosed(fn () => $this->transfers->create($this->cash, $this->bank, '1,00', '2026-03-31', 'X', $this->userId));
        $this->assertClosed(fn () => $this->transfers->cancel($open[0]['id'], 'Doppelt', $open[0]['version'], $this->userId));
        $this->assertClosed(fn () => $this->transfers->restore($cancelled[0]['id'], 'Doch', $cancelled[0]['version'], $this->userId));
        $this->assertSame(4, $this->rows('bookings'));

        $after = $this->transfers->create($this->cash, $this->bank, '1,00', '2026-04-01', 'Neu', $this->userId);
        $this->assertCount(2, $after);
    }

    public function test_receipt_replace_is_rejected_in_closed_period_but_add_is_allowed(): void
    {
        $booking = $this->booking('2026-03-10');
        $store = new ReceiptStore($this->pdo, $this->scratchDir() . '/store');
        $receipt = $store->add($booking['id'], $this->tmpFile($this->pdfBytes(), 'a.pdf'), 'a.pdf', $this->userId);
        $this->closing->close('2026-03-31', $this->userId);

        $this->assertClosed(fn () => $store->replace(
            $receipt['id'], $this->tmpFile($this->pdfBytes('x'), 'b.pdf'), 'b.pdf', 'Besseres Foto', $this->userId
        ));
        $this->assertSame(1, $this->rows('receipts'));

        $added = $store->add($booking['id'], $this->tmpFile($this->pdfBytes('y'), 'c.pdf'), 'c.pdf', $this->userId);
        $this->assertSame($booking['id'], $added['booking_id']);
        $this->assertSame(2, $this->rows('receipts'));
    }

    public function test_receipt_replace_locks_booking_row_before_closing_check(): void
    {
        $booking = $this->booking('2026-05-10');
        $store = new ReceiptStore($this->pdo, $this->scratchDir() . '/store');
        $receipt = $store->add($booking['id'], $this->tmpFile($this->pdfBytes(), 'a.pdf'), 'a.pdf', $this->userId);

        // Ein anderer Vorgang haelt die Buchungszeile (z. B. update/cancel in Arbeit): replace muss warten.
        $other = $this->connect();
        $other->beginTransaction();
        $other->query("SELECT 1 FROM bookings WHERE id = {$booking['id']} FOR NO KEY UPDATE");
        $this->pdo->exec("SET lock_timeout = '300ms'");
        try {
            $store->replace($receipt['id'], $this->tmpFile($this->pdfBytes('x'), 'b.pdf'), 'b.pdf', 'Besser', $this->userId);
            $this->fail('replace darf die Buchungszeile nicht ungesperrt lesen');
        } catch (PDOException $e) {
            $this->assertSame('55P03', $e->getCode());
        } finally {
            $other->rollBack();
            $this->pdo->exec('RESET lock_timeout');
        }
        $this->assertSame(1, $this->rows('receipts'));

        // Der Beleg selbst wird erst nach der Buchungszeile gesperrt: eine Sperre nur auf der Belegzeile
        // blockiert replace ebenfalls (Reihenfolge Buchung -> Abschluss -> Beleg), ohne Verklemmung.
        $holder = $this->connect();
        $holder->beginTransaction();
        $holder->query("SELECT 1 FROM receipts WHERE id = {$receipt['id']} FOR UPDATE");
        $this->pdo->exec("SET lock_timeout = '300ms'");
        try {
            $store->replace($receipt['id'], $this->tmpFile($this->pdfBytes('y'), 'c.pdf'), 'c.pdf', 'Besser', $this->userId);
            $this->fail('55P03 erwartet');
        } catch (PDOException $e) {
            $this->assertSame('55P03', $e->getCode());
        } finally {
            $holder->rollBack();
            $this->pdo->exec('RESET lock_timeout');
        }
    }

    public function test_receipt_replace_uses_date_after_concurrent_move_and_closing(): void
    {
        $booking = $this->booking('2026-05-10');
        $store = new ReceiptStore($this->pdo, $this->scratchDir() . '/store');
        $receipt = $store->add($booking['id'], $this->tmpFile($this->pdfBytes(), 'a.pdf'), 'a.pdf', $this->userId);
        $this->closing->close('2026-02-28', $this->userId);

        // Zwischen Vorab-Lesen und Pruefung: Buchung wird (erlaubt) in den Mrz verschoben, dann Abschluss bis 31.03.
        $other = $this->connect();
        (new BookingService($other))->update(
            $booking['id'], ['booking_date' => '2026-03-20'], 'Datum', $booking['version'], $this->userId
        );
        (new ClosingService($other))->close('2026-03-31', $this->userId);

        $this->assertClosed(fn () => $store->replace(
            $receipt['id'], $this->tmpFile($this->pdfBytes('x'), 'b.pdf'), 'b.pdf', 'Besser', $this->userId
        ));
        $this->assertSame(1, $this->rows('receipts'));
    }

    public function test_malformed_stored_closing_date_fails_closed(): void
    {
        $this->pdo->exec("INSERT INTO settings (key, value) VALUES ('closed_until', 'kaputt')");

        try {
            $this->closing->closedUntil();
            $this->fail('RuntimeException erwartet');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('closed_until', $e->getMessage());
        }
        try {
            $this->booking('2030-01-01');
            $this->fail('RuntimeException erwartet');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('closed_until', $e->getMessage());
        }
        $this->assertSame(0, $this->rows('bookings'));
    }

    public function test_malformed_old_date_from_database_fails_closed(): void
    {
        $id = (int) $this->pdo->query(
            "INSERT INTO bookings (account_id, booking_date, amount, direction, description, kind, status, created_by)
             VALUES ({$this->cash}, '1850-01-01', '1.00', 'out', 'Alt', 'regular', 'active', {$this->userId}) RETURNING id"
        )->fetchColumn();
        $this->closing->close('2026-03-31', $this->userId);

        try {
            $this->bookings->update($id, ['description' => 'Neu'], 'Grund', 1, $this->userId);
            $this->fail('RuntimeException erwartet');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(ClosedPeriod::class, $e);
        }
        $this->assertFalse($this->pdo->inTransaction());

        $this->pdo->beginTransaction();
        try {
            $this->closing->assertOpen('garbage');
            $this->fail('RuntimeException erwartet');
        } catch (\RuntimeException) {
            $this->pdo->rollBack();
        }
    }

    public function test_reopening_lifts_the_block(): void
    {
        $booking = $this->booking('2026-03-10');
        $this->closing->close('2026-03-31', $this->userId);
        $this->closing->reopen('2026-02-28', 'Nachtrag Februar', $this->userId);

        $this->assertSame('2026-02-28', $this->closing->closedUntil());
        $updated = $this->bookings->update($booking['id'], ['description' => 'Anders'], 'Tippfehler', $booking['version'], $this->userId);
        $this->assertSame('Anders', $updated['description']);
    }

    // --- Abschluss und Wiederoeffnen ------------------------------------------------------------------

    public function test_close_stores_date_and_writes_audit_log(): void
    {
        $this->closing->close('2026-03-31', $this->userId);
        $this->closing->close('2026-06-30', $this->userId);

        $this->assertSame('2026-06-30', $this->closing->closedUntil());
        $rows = $this->pdo->query("SELECT user_id, detail::text AS detail FROM audit_log WHERE action = 'period_closed' ORDER BY id")
            ->fetchAll();
        $this->assertCount(2, $rows);
        $this->assertSame($this->userId, (int) $rows[0]['user_id']);
        $this->assertEquals(['from' => null, 'to' => '2026-03-31'], json_decode($rows[0]['detail'], true));
        $this->assertEquals(['from' => '2026-03-31', 'to' => '2026-06-30'], json_decode($rows[1]['detail'], true));
    }

    public function test_close_only_moves_forward(): void
    {
        $this->closing->close('2026-03-31', $this->userId);

        foreach (['2026-03-31', '2026-03-30'] as $date) {
            try {
                $this->closing->close($date, $this->userId);
                $this->fail('ValidationError erwartet');
            } catch (ValidationError $e) {
                $this->assertSame(['closed_until' => 'validation.closing.not_forward'], $e->errors);
            }
        }
        $this->assertSame('2026-03-31', $this->closing->closedUntil());
        $this->assertSame(1, $this->rows("audit_log WHERE action = 'period_closed'"));
    }

    public function test_close_rejects_invalid_and_future_dates(): void
    {
        $closing = new ClosingService($this->pdo, '2026-10-12');
        $cases = [
            ['2026-02-30', 'validation.date.invalid'],
            ['gestern', 'validation.date.invalid'],
            ['', 'validation.date.invalid'],
            ['2026-10-13', 'validation.closing.future'],
        ];
        foreach ($cases as [$date, $key]) {
            try {
                $closing->close($date, $this->userId);
                $this->fail("ValidationError erwartet fuer '$date'");
            } catch (ValidationError $e) {
                $this->assertSame(['closed_until' => $key], $e->errors);
            }
        }
        $this->assertNull($closing->closedUntil());

        $closing->close('2026-10-12', $this->userId);
        $this->assertSame('2026-10-12', $closing->closedUntil());
    }

    public function test_reopen_requires_reason_and_writes_audit_log(): void
    {
        $this->closing->close('2026-06-30', $this->userId);

        foreach (['', '   ', "\u{00A0}\u{3000}"] as $reason) {
            try {
                $this->closing->reopen('2026-03-31', $reason, $this->userId);
                $this->fail('ValidationError erwartet');
            } catch (ValidationError $e) {
                $this->assertSame('validation.reason.required', $e->errors['reason'] ?? null);
            }
        }
        $this->assertSame('2026-06-30', $this->closing->closedUntil());
        $this->assertSame(0, $this->rows("audit_log WHERE action = 'period_reopened'"));

        $this->closing->reopen('2026-03-31', '  Beleg fehlte  ', $this->userId);

        $this->assertSame('2026-03-31', $this->closing->closedUntil());
        $row = $this->pdo->query("SELECT user_id, detail::text AS detail FROM audit_log WHERE action = 'period_reopened'")->fetch();
        $this->assertSame($this->userId, (int) $row['user_id']);
        $this->assertEquals(
            ['from' => '2026-06-30', 'to' => '2026-03-31', 'reason' => 'Beleg fehlte'],
            json_decode($row['detail'], true)
        );
    }

    public function test_reopen_must_be_earlier_or_open_completely(): void
    {
        try {
            $this->closing->reopen('2026-03-31', 'Nichts offen', $this->userId);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertSame('validation.closing.not_closed', $e->errors['closed_until'] ?? null);
        }

        $this->closing->close('2026-06-30', $this->userId);
        foreach (['2026-06-30', '2026-07-01', '2026-13-01'] as $date) {
            try {
                $this->closing->reopen($date, 'Grund', $this->userId);
                $this->fail('ValidationError erwartet');
            } catch (ValidationError $e) {
                $this->assertArrayHasKey('closed_until', $e->errors);
            }
        }
        $this->assertSame('2026-06-30', $this->closing->closedUntil());

        $this->closing->reopen('', 'Alles neu aufrollen', $this->userId);

        $this->assertNull($this->closing->closedUntil());
        $this->assertSame(0, $this->rows("settings WHERE key = 'closed_until'"));
        $row = $this->pdo->query("SELECT detail::text FROM audit_log WHERE action = 'period_reopened'")->fetchColumn();
        $this->assertEquals(
            ['from' => '2026-06-30', 'to' => null, 'reason' => 'Alles neu aufrollen'],
            json_decode((string) $row, true)
        );
    }

    // --- Nebenlaeufigkeit ----------------------------------------------------------------------------

    public function test_closing_and_booking_exclude_each_other(): void
    {
        $other = $this->connect();
        $other->exec("SET lock_timeout = '300ms'");

        // A: Buchung in offener Transaktion (Abschluss-Pruefung bestanden, noch nicht committed)
        $this->pdo->beginTransaction();
        $this->booking('2026-03-10');
        try {
            (new ClosingService($other))->close('2026-03-31', $this->userId);
            $this->fail('Abschluss darf nicht an einer laufenden Buchung vorbeikommen');
        } catch (PDOException $e) {
            $this->assertSame('55P03', $e->getCode());
        }
        $this->pdo->commit();

        // B: Abschluss in offener Transaktion, eine gleichzeitige Buchung muss warten
        $this->pdo->beginTransaction();
        $this->closing->close('2026-03-31', $this->userId);
        try {
            (new BookingService($other))->create($this->form(['booking_date' => '2026-03-20']), $this->userId);
            $this->fail('Buchung darf nicht an einem laufenden Abschluss vorbeikommen');
        } catch (PDOException $e) {
            $this->assertSame('55P03', $e->getCode());
        }
        $this->pdo->commit();

        $this->assertClosed(fn () => $this->booking('2026-03-20'));
    }

    // --- Seiten --------------------------------------------------------------------------------------

    public function test_only_vorstand_can_close(): void
    {
        $this->bootApp();
        $this->loginAs(['kassenwart'], 'kw@example.org');

        $this->assertSame(403, $this->get('/settings/closing')->getStatusCode());
        $res = $this->postForm('/settings/closing', ['action' => 'close', 'closed_until' => '2026-03-31']);
        $this->assertSame(403, $res->getStatusCode());
        $this->assertNull($this->closing->closedUntil());
        $this->assertSame(403, $this->get('/audit-log')->getStatusCode());
    }

    public function test_closed_period_is_reported_as_409_naming_date_and_correction(): void
    {
        $booking = $this->booking('2026-03-10');
        $this->closing->close('2026-03-31', $this->userId);
        $this->bootApp();
        $this->loginAs(['kassenwart'], 'kw@example.org');

        $res = $this->postForm("/bookings/{$booking['id']}/edit", [
            'version' => (string) $booking['version'], 'reason' => 'Tippfehler', 'description' => 'Anders',
            'account_id' => (string) $this->cash, 'booking_date' => '2026-03-10', 'amount' => '12,50', 'direction' => 'out',
        ]);
        $html = (string) $res->getBody();

        $this->assertSame(409, $res->getStatusCode());
        $this->assertStringContainsString('31.03.2026', $html);
        $this->assertStringContainsString('Korrekturbuchung', $html);

        $res = $this->postForm('/bookings/new', $this->form(['booking_date' => '2026-03-20']));
        $this->assertSame(409, $res->getStatusCode());
        $this->assertSame(1, $this->rows('bookings'));
    }

    public function test_closed_period_is_409_for_correction_transfer_and_receipt_replace_pages(): void
    {
        $booking = $this->booking('2026-03-10');
        $transfer = $this->transfers->create($this->cash, $this->bank, '10,00', '2026-03-10', 'Einzahlung', $this->userId);
        $receiptsDir = $this->scratchDir() . '/web-receipts';
        $receipt = (new ReceiptStore($this->pdo, $receiptsDir))
            ->add($booking['id'], $this->tmpFile($this->pdfBytes(), 'a.pdf'), 'a.pdf', $this->userId);
        $this->closing->close('2026-03-31', $this->userId);
        $this->store = new ArraySessionStore();
        $this->app = Kernel::create(['pdo' => $this->pdo, 'session_store' => $this->store, 'receipts_dir' => $receiptsDir]);
        $this->loginAs(['kassenwart'], 'kw@example.org');

        $correction = $this->postForm('/corrections/new', $this->form(['booking_date' => '2026-03-31', 'reason' => 'Falsch']));
        $this->assertSame(409, $correction->getStatusCode());

        $newTransfer = $this->postForm('/transfers/new', [
            'from_account_id' => (string) $this->cash, 'to_account_id' => (string) $this->bank,
            'booking_date' => '2026-03-31', 'amount' => '1,00', 'description' => 'X',
        ]);
        $this->assertSame(409, $newTransfer->getStatusCode());

        $cancel = $this->postForm("/bookings/{$transfer[0]['id']}/cancel", [
            'version' => (string) $transfer[0]['version'], 'reason' => 'Doppelt',
        ]);
        $this->assertSame(409, $cancel->getStatusCode());
        $this->assertStringContainsString('31.03.2026', (string) $cancel->getBody());

        $upload = new UploadedFile($this->tmpFile($this->pdfBytes('z'), 'n.pdf'), 'n.pdf', 'application/pdf', 10, UPLOAD_ERR_OK);
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', "/receipts/{$receipt['id']}/replace")
            ->withParsedBody(['_csrf' => $this->csrf(), 'reason' => 'Besser'])
            ->withUploadedFiles(['receipt' => $upload]);
        $replace = $this->app->handle($req);
        $this->assertSame(409, $replace->getStatusCode());
        $this->assertSame(1, $this->rows('receipts'));
    }
}
