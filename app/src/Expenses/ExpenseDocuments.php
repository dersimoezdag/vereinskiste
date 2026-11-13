<?php

declare(strict_types=1);

namespace Vereinskiste\Expenses;

use PDO;
use Throwable;
use Vereinskiste\Files\FileStorage;
use Vereinskiste\Files\StoredFileRepository;
use Vereinskiste\Foundation\History;
use Vereinskiste\Intake\DocumentInspection;
use Vereinskiste\Ledger\Transaction;

final class ExpenseDocuments
{
    public function __construct(private PDO $pdo, private string $directory) {}

    public function forExpense(int $expenseId): array
    {
        $stmt = $this->pdo->prepare('SELECT d.id, d.file_id, d.review_status, d.reviewed_at,
            f.original_name, f.mime, f.size, f.uploaded_at
            FROM expense_documents d JOIN stored_files f ON f.id = d.file_id
            WHERE d.expense_id = ? ORDER BY d.id');
        $stmt->execute([$expenseId]);
        return $stmt->fetchAll();
    }

    /** @param list<array{path:string,name:string}> $uploads */
    public function addMany(int $expenseId, array $uploads, int $actor): void
    {
        $paths = [];
        try {
            Transaction::run($this->pdo, function () use ($expenseId, $uploads, $actor, &$paths): void {
                foreach ($uploads as $upload) {
                    $file = (new FileStorage($this->directory))->ingest($upload['path'], ['application/pdf', 'image/jpeg', 'image/png']);
                    $path = $this->directory . '/' . $file['storage_name'];
                    $paths[] = $path;
                    (new DocumentInspection())->inspect($path, $file['mime']);
                    $fileId = (new StoredFileRepository($this->pdo))->register(
                        $file,
                        'documents',
                        FileStorage::cleanName($upload['name'], $file['ext']),
                        $actor
                    );
                    $this->pdo->prepare('INSERT INTO expense_documents(expense_id,file_id) VALUES (?,?)')->execute([$expenseId, $fileId]);
                }
            });
        } catch (Throwable $e) {
            foreach ($paths as $path) {
                @unlink($path);
            }
            throw $e;
        }
    }

    public function review(int $expenseId, int $documentId, int $actor): bool
    {
        return Transaction::run($this->pdo, function () use ($expenseId, $documentId, $actor): bool {
            $stmt = $this->pdo->prepare("UPDATE expense_documents SET review_status='reviewed', reviewed_by=?, reviewed_at=now()
                WHERE id=? AND expense_id=? AND review_status='pending'");
            $stmt->execute([$actor, $documentId, $expenseId]);
            if ($stmt->rowCount() !== 1) {
                return false;
            }
            (new History($this->pdo))->record(
                'expense',
                $expenseId,
                ['receipt_review' => 'pending'],
                ['receipt_review' => 'reviewed'],
                $actor,
                'Beleg geprüft'
            );
            return true;
        });
    }
}
