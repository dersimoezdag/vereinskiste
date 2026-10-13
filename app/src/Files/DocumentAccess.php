<?php
declare(strict_types=1);

namespace Vereinskiste\Files;

use PDO;
use Vereinskiste\Auth\AuthService;

/** IDs allein sind keine Berechtigung: jede Datei braucht eine zugelassene Fachreferenz. */
final class DocumentAccess
{
    public function __construct(private PDO $pdo) {}

    public function canRead(int $id, AuthService $auth): bool
    {
        if ($auth->currentUser() === null) { return false; }
        if ($auth->can('invoices.read')) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM invoice_documents WHERE file_id = ?');
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() !== false) { return true; }
        }
        if ($auth->can('imports.read')) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM bank_imports WHERE file_id = ?');
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() !== false) { return true; }
        }
        if ($auth->can('settings.manage')) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM club_profile WHERE logo_id = ?');
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() !== false) { return true; }
        }
        if ($auth->can('bookings.read')) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM receipts r JOIN bookings b ON b.id = r.booking_id WHERE r.stored_file_id = ?');
            $stmt->execute([$id]);
            return $stmt->fetchColumn() !== false;
        }
        return false;
    }
}
