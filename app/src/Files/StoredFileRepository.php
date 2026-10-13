<?php
declare(strict_types=1);

namespace Vereinskiste\Files;

use PDO;
use Vereinskiste\Ledger\Transaction;

final class StoredFileRepository
{
    public function __construct(private PDO $pdo) {}

    public function register(array $file, string $root, string $name, int $actor): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO stored_files
            (root,storage_name,original_name,mime,size,sha256,uploaded_by,uploaded_at) VALUES (?,?,?,?,?,?,?,COALESCE(?::timestamptz,now()))
            ON CONFLICT (root,storage_name) DO UPDATE SET storage_name = EXCLUDED.storage_name RETURNING id');
        $stmt->execute([$root, $file['storage_name'], $name, $file['mime'], $file['size'], $file['sha256'], $actor, $file['uploaded_at'] ?? null]);
        return (int) $stmt->fetchColumn();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM stored_files WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Nur Metadaten, keine Dateikopie; nach Abbruch beliebig wiederholbar. */
    public function registerReceipts(int $limit = 50): int
    {
        return Transaction::run($this->pdo, function () use ($limit): int {
            $stmt = $this->pdo->prepare('SELECT * FROM receipts WHERE stored_file_id IS NULL ORDER BY id LIMIT ? FOR UPDATE SKIP LOCKED');
            $stmt->bindValue(1, max(1, min(250, $limit)), PDO::PARAM_INT); $stmt->execute();
            $rows = $stmt->fetchAll();
            foreach ($rows as $row) {
                $id = $this->register($row, 'receipts', $row['original_name'], (int) $row['uploaded_by']);
                $this->pdo->prepare('UPDATE receipts SET stored_file_id = ? WHERE id = ?')->execute([$id, $row['id']]);
            }
            return count($rows);
        });
    }
}
