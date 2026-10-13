<?php
declare(strict_types=1);
namespace Vereinskiste\Reports;

use PDO;
use Vereinskiste\Ledger\DateInput;
use Vereinskiste\Ledger\ValidationError;

/** Shared exact aggregation for overview, category report and monthly charts. */
final class BookingAggregation
{
    public const ELIGIBLE = "b.status = 'active' AND b.kind <> 'transfer'";
    public const COLUMNS = "COALESCE(SUM(b.amount) FILTER (WHERE b.direction = 'in'),0)::numeric(14,2)::text AS income,
        COALESCE(SUM(b.amount) FILTER (WHERE b.direction = 'out'),0)::numeric(14,2)::text AS expense,
        COALESCE(SUM(CASE WHEN b.direction='in' THEN b.amount ELSE -b.amount END),0)::numeric(14,2)::text AS balance";
    public function __construct(private PDO $pdo) {}
    public function totals(string $from,string $to): array
    {
        if(!DateInput::valid($from)||!DateInput::valid($to)||$from>$to) {throw new ValidationError(['to'=>'validation.range.swapped']);}
        $stmt=$this->pdo->prepare('SELECT '.self::COLUMNS.' FROM bookings b WHERE '.self::ELIGIBLE.' AND b.booking_date BETWEEN ? AND ?');
        $stmt->execute([$from,$to]);return $stmt->fetch();
    }
    public function categories(array $params,string $accountCond): array
    {
        $stmt=$this->pdo->prepare('SELECT c.id AS category_id,c.name,GROUPING(c.id,c.name) AS is_total,'.self::COLUMNS.'
            FROM bookings b LEFT JOIN categories c ON c.id=b.category_id WHERE '.self::ELIGIBLE.'
            AND b.booking_date>=CAST(:from AS date) AND b.booking_date<=CAST(:to AS date)'.$accountCond.'
            GROUP BY ROLLUP((c.id,c.name)) ORDER BY GROUPING(c.id,c.name),c.name NULLS LAST,c.id');
        $stmt->execute($params);return $stmt->fetchAll();
    }
}
