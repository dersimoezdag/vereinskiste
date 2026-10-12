<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Reports;

use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Reports\SummaryService;
use Vereinskiste\Tests\TestCase;

final class SummaryServiceTest extends TestCase
{
    use ReportFixtures;

    protected function setUp(): void
    {
        $this->migrated();
    }

    public function test_summary_excludes_cancelled_and_transfers(): void
    {
        $u = $this->user();
        $a = $this->account();
        $b = $this->account('Bank');
        $fees = $this->category('Beiträge');
        $this->booking($a, $u, '2026-03-01', 'in', '100.10', 'Beitrag', $fees);
        $this->booking($a, $u, '2026-03-02', 'in', '999.00', 'storniert', $fees, 'regular', 'cancelled');
        $this->booking($a, $u, '2026-03-03', 'out', '40.05', 'Miete', null);
        $this->booking($a, $u, '2026-03-04', 'out', '500.00', 'Umbuchung', null, 'transfer');
        $this->booking($b, $u, '2026-03-04', 'in', '500.00', 'Umbuchung', null, 'transfer');
        $this->booking($a, $u, '2025-12-31', 'in', '7.00', 'Vorjahr', $fees);

        $s = (new SummaryService($this->pdo))->summary('2026-01-01', '2026-12-31');

        $this->assertSame('100.10', $s['income']);
        $this->assertSame('40.05', $s['expense']);
        $this->assertSame('60.05', $s['balance']);
        $this->assertNull($s['accountBalanceAtEnd']);
        $this->assertSame(
            [['category_id' => $fees, 'name' => 'Beiträge', 'income' => '100.10', 'expense' => '0.00'],
             ['category_id' => null, 'name' => SummaryService::UNCATEGORIZED, 'income' => '0.00', 'expense' => '40.05']],
            $s['byCategory']
        );
    }

    public function test_summary_includes_corrections(): void
    {
        $u = $this->user();
        $a = $this->account();
        $cat = $this->category('Spenden');
        $this->booking($a, $u, '2026-05-01', 'in', '50.00', 'Spende', $cat);
        $this->booking($a, $u, '2026-05-02', 'out', '10.00', 'Korrektur', $cat, 'correction');
        $this->booking($a, $u, '2026-05-03', 'in', '5.50', 'Korrektur Plus', $cat, 'correction');
        $this->booking($a, $u, '2026-05-04', 'in', '1.00', 'Korrektur storniert', $cat, 'correction', 'cancelled');

        $s = (new SummaryService($this->pdo))->summary('2026-05-01', '2026-05-31');

        $this->assertSame('55.50', $s['income']);
        $this->assertSame('10.00', $s['expense']);
        $this->assertSame('45.50', $s['balance']);
        $this->assertSame(['Korrektur', 'Korrektur Plus', 'Korrektur storniert'], array_column($s['corrections'], 'description'));
        $this->assertSame('cancelled', $s['corrections'][2]['status']);
    }

    public function test_period_is_inclusive_and_account_filter_adds_closing_balance(): void
    {
        $u = $this->user();
        $a = $this->account('Barkasse', '100.00');
        $b = $this->account('Bank', '0');
        $this->booking($a, $u, '2026-02-01', 'in', '10.00');
        $this->booking($a, $u, '2026-02-28', 'out', '3.00');
        $this->booking($a, $u, '2026-03-01', 'in', '1000.00');
        $this->booking($b, $u, '2026-02-10', 'in', '77.00');

        $s = (new SummaryService($this->pdo))->summary('2026-02-01', '2026-02-28', $a);

        $this->assertSame('10.00', $s['income']);
        $this->assertSame('3.00', $s['expense']);
        $this->assertSame('107.00', $s['accountBalanceAtEnd']);
        $this->assertSame('Barkasse', $s['account_name']);
    }

    public function test_empty_period_gives_zero_amounts(): void
    {
        $s = (new SummaryService($this->pdo))->summary('2026-01-01', '2026-12-31');
        $this->assertSame(['0.00', '0.00', '0.00', []], [$s['income'], $s['expense'], $s['balance'], $s['byCategory']]);
    }

    public function test_swapped_or_invalid_range_is_a_validation_error(): void
    {
        $service = new SummaryService($this->pdo);
        try {
            $service->summary('2026-12-31', '2026-01-01');
            $this->fail('expected ValidationError');
        } catch (ValidationError $e) {
            $this->assertSame(['to' => 'validation.range.swapped'], $e->errors);
        }
        try {
            $service->summary('2026-02-30', 'nope');
            $this->fail('expected ValidationError');
        } catch (ValidationError $e) {
            $this->assertSame(['from' => 'validation.date.invalid', 'to' => 'validation.date.invalid'], $e->errors);
        }
    }
}
