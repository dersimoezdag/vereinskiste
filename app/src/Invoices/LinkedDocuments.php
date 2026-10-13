<?php
declare(strict_types=1);
namespace Vereinskiste\Invoices;
use PDO;

/** Referenced originals, never copied into a second receipt store. */
final class LinkedDocuments
{
    public function __construct(private PDO $pdo) {}
    public function forBooking(int $booking): array
    {
        $stmt=$this->pdo->prepare("SELECT d.invoice_id,d.file_id,f.original_name,f.mime,f.size,f.sha256,f.uploaded_at,u.name AS uploaded_by_name
            FROM invoice_payment_allocations a JOIN invoice_documents d ON d.invoice_id=a.invoice_id
            JOIN stored_files f ON f.id=d.file_id JOIN users u ON u.id=f.uploaded_by
            WHERE a.booking_id=? AND d.security_status IN ('validated','clean','legacy') ORDER BY d.id");
        $stmt->execute([$booking]);return $stmt->fetchAll();
    }
}
