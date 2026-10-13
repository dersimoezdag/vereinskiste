<?php
declare(strict_types=1);

namespace Vereinskiste\Reports;

use DateTimeImmutable;
use PDO;
use Vereinskiste\Ledger\DateInput;
use Vereinskiste\Ledger\ValidationError;

/**
 * Daten fuer das Finanz-Dashboard: Verlauf je Monat und Aufbereitung der Kategorien fuer Diagramme.
 * Es zaehlen wie in der Auswertung nur aktive Buchungen ohne Umbuchungen. Betraege bleiben bis zur Ausgabe
 * exakte Strings bzw. ganze Cent.
 */
final class DashboardService
{
    public const MAX_MONTHS = 120;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Ein Eintrag je Kalendermonat des Zeitraums (auch Monate ohne Buchungen), nur Buchungen innerhalb
     * von `$from` bis `$to` (beide inklusive).
     *
     * @return list<array{month:string,income:string,expense:string,result:string,cumulative:string}>
     * @throws ValidationError bei ungueltigem Zeitraum oder mehr als MAX_MONTHS Monaten
     */
    public function monthly(string $from, string $to, ?int $accountId = null): array
    {
        if (!DateInput::valid($from) || !DateInput::valid($to) || $from > $to) {
            throw new ValidationError(['to' => 'validation.range.swapped']);
        }
        $start = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        $months = ((int) $end->format('Y') - (int) $start->format('Y')) * 12
            + (int) $end->format('n') - (int) $start->format('n') + 1;
        if ($months > self::MAX_MONTHS) {
            throw new ValidationError(['to' => 'dashboard.range_too_long']);
        }

        $params = ['from_m' => $from, 'to_m' => $to, 'from_b' => $from, 'to_b' => $to];
        $accountCond = '';
        if ($accountId !== null) {
            $accountCond = ' AND b.account_id = :account_id';
            $params['account_id'] = $accountId;
        }
        $stmt = $this->pdo->prepare(
            "WITH months AS (
                 SELECT generate_series(date_trunc('month', CAST(:from_m AS date)), date_trunc('month', CAST(:to_m AS date)),
                                        interval '1 month')::date AS m
             )
             SELECT to_char(months.m, 'YYYY-MM') AS month,
                    " . BookingAggregation::COLUMNS . "
               FROM months
               LEFT JOIN bookings b
                 ON " . BookingAggregation::ELIGIBLE . "
                AND date_trunc('month', b.booking_date)::date = months.m
                AND b.booking_date >= CAST(:from_b AS date) AND b.booking_date <= CAST(:to_b AS date)$accountCond
              GROUP BY months.m
              ORDER BY months.m"
        );
        $stmt->execute($params);

        $rows = [];
        $cumulative = 0;
        foreach ($stmt->fetchAll() as $row) {
            $result = self::cents($row['income']) - self::cents($row['expense']);
            $cumulative += $result;
            $rows[] = [
                'month' => $row['month'],
                'income' => $row['income'],
                'expense' => $row['expense'],
                'result' => self::money($result),
                'cumulative' => self::money($cumulative),
            ];
        }
        return $rows;
    }

    /**
     * Groesste Kategorien zuerst; Nullbetraege entfallen. Gibt es mehr als `$limit` Zeilen, werden die
     * kleinsten zu einer Zeile `$other` zusammengefasst, sodass insgesamt hoechstens `$limit` Zeilen bleiben.
     *
     * @param list<array{category_id:?int,name:string,income:string,expense:string}> $byCategory
     * @param 'income'|'expense' $field
     * @return list<array{name:string,amount:string}>
     */
    public static function topCategories(array $byCategory, string $field, string $uncategorized, string $other, int $limit = 8): array
    {
        $rows = [];
        foreach ($byCategory as $row) {
            $cents = self::cents($row[$field]);
            if ($cents > 0) {
                $rows[] = ['name' => $row['category_id'] === null ? $uncategorized : $row['name'], 'cents' => $cents];
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$b['cents'], $a['name']] <=> [$a['cents'], $b['name']]);
        if (count($rows) > $limit) {
            $rest = array_slice($rows, $limit - 1);
            $rows = array_slice($rows, 0, $limit - 1);
            $rows[] = ['name' => $other, 'cents' => array_sum(array_column($rest, 'cents'))];
        }
        return array_map(static fn (array $r): array => ['name' => $r['name'], 'amount' => self::money($r['cents'])], $rows);
    }

    /**
     * Zahlen fuer die Diagramme (Euro als Gleitkommazahl, nur zur Darstellung).
     *
     * @param list<array{month:string,income:string,expense:string,result:string,cumulative:string}> $monthly
     * @param list<array{name:string,amount:string}> $income
     * @param list<array{name:string,amount:string}> $expense
     * @return array<string,mixed>
     */
    public static function chartData(array $monthly, array $income, array $expense): array
    {
        $euro = static fn (string $amount): float => self::cents($amount) / 100;
        $category = static fn (array $r): array => ['name' => $r['name'], 'amount' => $euro($r['amount'])];
        return [
            'months' => array_column($monthly, 'month'),
            'income' => array_map($euro, array_column($monthly, 'income')),
            'expense' => array_map($euro, array_column($monthly, 'expense')),
            'cumulative' => array_map($euro, array_column($monthly, 'cumulative')),
            'incomeByCategory' => array_map($category, $income),
            'expenseByCategory' => array_map($category, $expense),
        ];
    }

    /** "-12.50" -> -1250 (Betraege kommen als numeric(14,2) mit genau zwei Nachkommastellen). */
    private static function cents(string $amount): int
    {
        return (int) str_replace('.', '', $amount);
    }

    private static function money(int $cents): string
    {
        return ($cents < 0 ? '-' : '') . intdiv(abs($cents), 100) . '.' . str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }
}
