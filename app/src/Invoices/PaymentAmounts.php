<?php
declare(strict_types=1);
namespace Vereinskiste\Invoices;

use PDO;

/** A single SQL definition shared by list, detail and allocation validation. */
final class PaymentAmounts
{
    public const COLUMNS = "t.paid::numeric(12,2)::text AS paid_total,
        GREATEST(COALESCE(i.gross,0)-t.paid,0)::numeric(12,2)::text AS remaining";
    public const JOIN = "LEFT JOIN LATERAL (SELECT COALESCE(SUM(a.amount),0) AS paid
        FROM invoice_payment_allocations a JOIN bookings b ON b.id=a.booking_id
        WHERE a.invoice_id=i.id AND b.status='active') t ON true";
    public function __construct(private PDO $pdo) {}
    public function forInvoice(int $id): array
    {
        $stmt=$this->pdo->prepare('SELECT '.self::COLUMNS.' FROM invoices i '.self::JOIN.' WHERE i.id=?');
        $stmt->execute([$id]);return $stmt->fetch()?:['paid_total'=>'0.00','remaining'=>'0.00'];
    }
}
