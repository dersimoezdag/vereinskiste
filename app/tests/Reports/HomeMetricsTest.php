<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Reports;

use Vereinskiste\Dashboard\Metrics;
use Vereinskiste\Ledger\MoneyFormat;
use Vereinskiste\Tests\TestCase;

final class HomeMetricsTest extends TestCase
{
    use ReportFixtures;
    protected function setUp(): void { $this->migrated(); }

    public function test_finance_uses_fiscal_year_to_today_excludes_transfers_and_future_bookings(): void
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Berlin'));
        $start = $today->modify('-1 month')->format('m-d');
        $this->pdo->prepare("UPDATE club_profile SET data=jsonb_set(data,'{year_start}',to_jsonb(?::text)) WHERE id=1")->execute([$start]);
        $user = $this->user(); $account = $this->account();
        $date = $today->format('Y-m-d');
        $this->booking($account, $user, $date, 'in', '1234.56');
        $this->booking($account, $user, $date, 'out', '34.55', 'Korrektur', null, 'correction');
        $this->booking($account, $user, $date, 'out', '400.00', 'Umbuchung', null, 'transfer');
        $this->booking($account, $user, $date, 'in', '500.00', 'Storno', null, 'regular', 'cancelled');
        $this->booking($account, $user, $today->modify('+1 day')->format('Y-m-d'), 'in', '600.00');
        $this->booking($account, $user, $today->modify('-2 months')->format('Y-m-d'), 'in', '700.00');
        $metric = (new Metrics($this->pdo))->forCard('finance');
        $this->assertSame(MoneyFormat::eur('1200.01'), $metric['value']);
        $this->assertSame(MoneyFormat::eur('1234.56'), $metric['details']['Einnahmen']);
        $this->assertSame(MoneyFormat::eur('34.55'), $metric['details']['Ausgaben']);
        $this->assertStringContainsString($today->format('d.m.Y'), $metric['description']);
    }
}
