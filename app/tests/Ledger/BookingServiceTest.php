<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Ledger;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\BookingRepository;
use Vereinskiste\Ledger\BookingService;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Tests\TestCase;

final class BookingServiceTest extends TestCase
{
    private PDO $pdo;
    private BookingService $service;
    private int $userId;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->service = new BookingService($this->pdo);
        $this->userId = (int) $this->pdo->query(
            "INSERT INTO users (email, name, password_hash) VALUES ('k@example.org', 'Kassenwart', 'x') RETURNING id"
        )->fetchColumn();
        $this->accountId = (int) $this->pdo->query(
            "INSERT INTO accounts (name, type, opening_date) VALUES ('Barkasse', 'cash', '2026-01-01') RETURNING id"
        )->fetchColumn();
    }

    /**
     * @param array<string,string|int> $override
     * @return array<string,string|int>
     */
    private function input(array $override = []): array
    {
        return $override + [
            'account_id' => $this->accountId, 'booking_date' => '2026-03-05', 'amount' => '12,5',
            'direction' => 'in', 'category_id' => '', 'description' => 'Mitgliedsbeitrag',
            'counterparty' => 'Max Muster', 'receipt_no' => 'R-1',
        ];
    }

    public function test_create_sets_created_by_and_version_1(): void
    {
        $booking = $this->service->create($this->input(), $this->userId);

        $this->assertSame($this->userId, $booking['created_by']);
        $this->assertSame(1, $booking['version']);
        $this->assertSame('regular', $booking['kind']);
        $this->assertSame('active', $booking['status']);
        $this->assertSame('12.50', $booking['amount']);
        $this->assertSame('2026-03-05', $booking['booking_date']);
        $this->assertNull($booking['category_id']);
        $this->assertSame('Max Muster', $booking['counterparty']);
        $this->assertSame($booking['id'], (new BookingRepository($this->pdo))->find($booking['id'])['id']);
    }

    public function test_create_rejects_inactive_account(): void
    {
        $this->pdo->exec('UPDATE accounts SET active = false');
        try {
            $this->service->create($this->input(), $this->userId);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertArrayHasKey('account_id', $e->errors);
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM bookings')->fetchColumn());
    }

    public function test_create_rejects_inactive_category(): void
    {
        $cat = (int) $this->pdo->query("INSERT INTO categories (name, active) VALUES ('Alt', false) RETURNING id")->fetchColumn();
        try {
            $this->service->create($this->input(['category_id' => (string) $cat]), $this->userId);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertArrayHasKey('category_id', $e->errors);
        }
    }

    public function test_create_reports_all_field_errors(): void
    {
        try {
            $this->service->create([
                'account_id' => '999', 'booking_date' => '2026-02-30', 'amount' => '0', 'direction' => 'x',
                'description' => '   ',
            ], $this->userId);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertEqualsCanonicalizing(
                ['account_id', 'booking_date', 'amount', 'direction', 'description'],
                array_keys($e->errors)
            );
        }
    }

    public function test_create_stores_empty_optional_fields_as_null_and_trims(): void
    {
        $booking = $this->service->create(
            $this->input(['counterparty' => '  ', 'receipt_no' => '', 'description' => ' Miete ']),
            $this->userId
        );
        $this->assertNull($booking['counterparty']);
        $this->assertNull($booking['receipt_no']);
        $this->assertSame('Miete', $booking['description']);
    }
}
