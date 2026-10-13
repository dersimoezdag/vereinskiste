<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Reports;

use PHPUnit\Framework\TestCase;
use Vereinskiste\Reports\DashboardService;

final class DashboardChartDataTest extends TestCase
{
    private function category(?int $id, string $name, string $income, string $expense = '0.00'): array
    {
        return ['category_id' => $id, 'name' => $name, 'income' => $income, 'expense' => $expense];
    }

    public function test_top_categories_sort_drop_zero_and_fold_the_tail_into_other(): void
    {
        $rows = [
            $this->category(1, 'B', '5.00'), $this->category(2, 'A', '10.00'), $this->category(3, 'C', '3.00'),
            $this->category(4, 'D', '2.00'), $this->category(5, 'E', '1.00'), $this->category(6, 'Null', '0.00', '9.00'),
            $this->category(null, 'ignored', '4.00'),
        ];
        $this->assertSame([
            ['name' => 'A', 'amount' => '10.00'],
            ['name' => 'B', 'amount' => '5.00'],
            ['name' => 'Weitere', 'amount' => '10.00'],
        ], DashboardService::topCategories($rows, 'income', 'Ohne Kategorie', 'Weitere', 3));
        $this->assertSame([['name' => 'Null', 'amount' => '9.00']],
            DashboardService::topCategories($rows, 'expense', 'Ohne Kategorie', 'Weitere'));
        $this->assertSame('Ohne Kategorie', DashboardService::topCategories([$this->category(null, 'x', '4.00')], 'income', 'Ohne Kategorie', 'Weitere')[0]['name']);
    }

    public function test_no_fold_when_rows_fit_the_limit(): void
    {
        $rows = [$this->category(1, 'A', '2.00'), $this->category(2, 'B', '1.00')];
        $this->assertCount(2, DashboardService::topCategories($rows, 'income', 'u', 'o', 2));
    }

    public function test_chart_data_converts_to_euro_numbers(): void
    {
        $data = DashboardService::chartData(
            [['month' => '2026-01', 'income' => '10.50', 'expense' => '2.25', 'result' => '8.25', 'cumulative' => '-8.25']],
            [['name' => 'A', 'amount' => '10.50']],
            []
        );
        $this->assertSame(['2026-01'], $data['months']);
        $this->assertSame([10.5], $data['income']);
        $this->assertSame([-8.25], $data['cumulative']);
        $this->assertSame([['name' => 'A', 'amount' => 10.5]], $data['incomeByCategory']);
        $this->assertSame([], $data['expenseByCategory']);
    }
}
