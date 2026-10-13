<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Reports;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Reports\DashboardService;
use Vereinskiste\Tests\TestCase;

final class DashboardServiceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = $this->pdo();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->db->exec("INSERT INTO users(name,email,password_hash) VALUES ('Admin','admin@example.org','hash')");
        $this->db->exec("INSERT INTO accounts(name,type,opening_date) VALUES ('Kasse','cash','2026-01-01'), ('Bank','bank','2026-01-01')");
        $this->book(1, '2026-01-10', '100.00', 'in');
        $this->book(1, '2026-01-20', '30.00', 'out');
        $this->book(1, '2026-02-10', '50.00', 'out', 'transfer');
        $this->book(1, '2026-02-12', '999.00', 'in', 'regular', 'cancelled');
        $this->book(1, '2026-03-05', '20.50', 'in');
        $this->book(1, '2026-03-06', '70.25', 'out');
    }

    private function book(int $account, string $date, string $amount, string $direction, string $kind = 'regular', string $status = 'active'): void
    {
        $this->db->prepare('INSERT INTO bookings(account_id,booking_date,amount,direction,description,kind,status,created_by)
            VALUES (?,?,?,?,?,?,?,1)')->execute([$account, $date, $amount, $direction, 'Test', $kind, $status]);
    }

    public function test_monthly_lists_every_month_ignores_transfers_and_cancelled_and_accumulates(): void
    {
        $rows = (new DashboardService($this->db))->monthly('2026-01-01', '2026-03-31');
        $this->assertSame([
            ['month' => '2026-01', 'income' => '100.00', 'expense' => '30.00', 'result' => '70.00', 'cumulative' => '70.00'],
            ['month' => '2026-02', 'income' => '0.00', 'expense' => '0.00', 'result' => '0.00', 'cumulative' => '70.00'],
            ['month' => '2026-03', 'income' => '20.50', 'expense' => '70.25', 'result' => '-49.75', 'cumulative' => '20.25'],
        ], $rows);
    }

    public function test_range_limits_bookings_inside_partial_months_and_account_filters(): void
    {
        $service = new DashboardService($this->db);
        $this->book(2, '2026-01-12', '5.00', 'in');
        $partial = $service->monthly('2026-01-15', '2026-02-10');
        $this->assertSame(['2026-01', '2026-02'], array_column($partial, 'month'));
        $this->assertSame('0.00', $partial[0]['income']);
        $this->assertSame('30.00', $partial[0]['expense']);
        $this->assertSame('105.00', $service->monthly('2026-01-01', '2026-01-31')[0]['income']);
        $this->assertSame('100.00', $service->monthly('2026-01-01', '2026-01-31', 1)[0]['income']);
        $this->assertSame('5.00', $service->monthly('2026-01-01', '2026-01-31', 2)[0]['income']);
    }

    public function test_too_long_or_swapped_ranges_are_rejected(): void
    {
        $service = new DashboardService($this->db);
        foreach ([['2000-01-01', '2026-12-31', 'dashboard.range_too_long'], ['2026-02-01', '2026-01-01', 'validation.range.swapped']] as [$from, $to, $key]) {
            try {
                $service->monthly($from, $to);
                $this->fail($key);
            } catch (ValidationError $e) {
                $this->assertSame($key, $e->errors['to']);
            }
        }
    }
}
