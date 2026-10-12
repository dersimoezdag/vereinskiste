<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Ledger;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\BookingService;
use Vereinskiste\Ledger\TransferService;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Tests\TestCase;

/** Fachregel: Buchungen duerfen nicht vor dem Eroeffnungsdatum ihres Kontos liegen. */
final class OpeningDateTest extends TestCase
{
    private PDO $pdo;
    private BookingService $bookings;
    private TransferService $transfers;
    private int $userId;
    private int $cash;
    private int $bank;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->bookings = new BookingService($this->pdo);
        $this->transfers = new TransferService($this->pdo);
        $this->userId = (int) $this->pdo->query(
            "INSERT INTO users (email, name, password_hash) VALUES ('k@example.org', 'Kassenwart', 'x') RETURNING id"
        )->fetchColumn();
        $this->cash = (int) $this->pdo->query(
            "INSERT INTO accounts (name, type, opening_date) VALUES ('Bar', 'cash', '2026-03-01') RETURNING id"
        )->fetchColumn();
        $this->bank = (int) $this->pdo->query(
            "INSERT INTO accounts (name, type, opening_date) VALUES ('Giro', 'bank', '2026-06-01') RETURNING id"
        )->fetchColumn();
    }

    /**
     * @param array<string,string|int> $override
     * @return array<string,string|int>
     */
    private function input(array $override = []): array
    {
        return $override + [
            'account_id' => $this->cash, 'booking_date' => '2026-03-01', 'amount' => '10',
            'direction' => 'in', 'category_id' => '', 'description' => 'Test',
        ];
    }

    private function bookingCount(): int
    {
        return (int) $this->pdo->query('SELECT count(*) FROM bookings')->fetchColumn();
    }

    /** @param callable():mixed $call */
    private function assertBeforeOpening(callable $call, string $field, string $dateText): void
    {
        try {
            $call();
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertArrayHasKey($field, $e->errors);
            $this->assertStringContainsString('before_opening', $e->errors[$field]);
            $this->assertStringContainsString($dateText, $e->errors[$field]);
        }
    }

    public function test_create_rejects_date_before_opening_and_accepts_the_opening_date(): void
    {
        $this->assertBeforeOpening(
            fn () => $this->bookings->create($this->input(['booking_date' => '2026-02-28']), $this->userId),
            'booking_date',
            '2026-03-01'
        );
        $this->assertSame(0, $this->bookingCount());

        $booking = $this->bookings->create($this->input(['booking_date' => '2026-03-01']), $this->userId);
        $this->assertSame('2026-03-01', $booking['booking_date']);
    }

    public function test_update_rejects_moving_the_date_before_opening(): void
    {
        $booking = $this->bookings->create($this->input(['booking_date' => '2026-03-10']), $this->userId);
        $this->assertBeforeOpening(
            fn () => $this->bookings->update($booking['id'], ['booking_date' => '2026-01-15'], 'Datum', 1, $this->userId),
            'booking_date',
            '2026-03-01'
        );
    }

    public function test_update_rejects_moving_to_an_account_opened_later(): void
    {
        $booking = $this->bookings->create($this->input(['booking_date' => '2026-03-10']), $this->userId);
        $this->assertBeforeOpening(
            fn () => $this->bookings->update($booking['id'], ['account_id' => (string) $this->bank], 'Konto', 1, $this->userId),
            'booking_date',
            '2026-06-01'
        );
        $this->assertSame($this->cash, (int) $this->pdo->query('SELECT account_id FROM bookings')->fetchColumn());
    }

    public function test_correction_rejects_date_before_opening(): void
    {
        $this->assertBeforeOpening(
            fn () => $this->bookings->createCorrection($this->input(['booking_date' => '2026-02-01']), null, 'Korrektur', $this->userId),
            'booking_date',
            '2026-03-01'
        );
        $this->assertSame(0, $this->bookingCount());
    }

    public function test_cancel_and_restore_still_work_for_legacy_bookings_before_opening(): void
    {
        $id = (int) $this->pdo->query(
            "INSERT INTO bookings (account_id, booking_date, amount, direction, description, kind, status, version, created_by)
             VALUES ({$this->cash}, '2026-01-05', 5, 'in', 'Alt', 'regular', 'active', 1, {$this->userId}) RETURNING id"
        )->fetchColumn();

        $cancelled = $this->bookings->cancel($id, 'Irrtum', 1, $this->userId);
        $this->assertSame('cancelled', $cancelled['status']);
        $restored = $this->bookings->restore($id, 'Doch', 2, $this->userId);
        $this->assertSame('active', $restored['status']);
    }

    public function test_transfer_checks_both_accounts(): void
    {
        // Datum liegt nach dem Eroeffnungsdatum der Bar-, aber vor dem der Bankkasse.
        $this->assertBeforeOpening(
            fn () => $this->transfers->create($this->cash, $this->bank, '5', '2026-04-01', 'Einzahlung', $this->userId),
            'booking_date',
            '2026-06-01'
        );
        $this->assertBeforeOpening(
            fn () => $this->transfers->create($this->bank, $this->cash, '5', '2026-02-01', 'Abhebung', $this->userId),
            'booking_date',
            '2026-06-01'
        );
        $this->assertSame(0, $this->bookingCount());

        $legs = $this->transfers->create($this->cash, $this->bank, '5', '2026-06-01', 'Einzahlung', $this->userId);
        $this->assertCount(2, $legs);
    }
}
