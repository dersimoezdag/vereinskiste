<?php
declare(strict_types=1);
namespace Vereinskiste\BankImport;

use PDO;
use Throwable;
use Vereinskiste\BankImport\Parsers\CsvReader;
use Vereinskiste\Files\FileStorage;
use Vereinskiste\Files\StoredFileRepository;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Audit\AuditLog;

final class Upload
{
    public function __construct(private PDO $db, private string $directory) {}
    public function create(int $account, string $path, string $name, int $actor, string $delimiter = '', string $encoding = ''): int
    {
        $stmt = $this->db->prepare("SELECT 1 FROM accounts WHERE id=? AND active=true AND type='bank'"); $stmt->execute([$account]);
        if (!$stmt->fetchColumn()) { throw new ValidationError(['account_id' => 'bank.account.invalid']); }
        $details = (new CsvReader())->inspect($path, $delimiter, $encoding);
        $file = (new FileStorage($this->directory))->ingest($path, ['text/csv']);
        try {
            // Recheck the stored original, not only the upload source.
            $details = (new CsvReader())->inspect($this->directory . '/' . $file['storage_name'], $details['delimiter'], $details['encoding']);
            $result = Transaction::run($this->db, function () use ($account, $file, $name, $actor, $details): array {
                $this->db->prepare('SELECT id FROM accounts WHERE id=? FOR UPDATE')->execute([$account]);
                $stmt = $this->db->prepare('SELECT id FROM bank_imports WHERE account_id=? AND sha256=?');
                $stmt->execute([$account, $file['sha256']]);
                if ($id = $stmt->fetchColumn()) { return [(int) $id, false]; }
                $fileId = (new StoredFileRepository($this->db))->register($file, 'documents', FileStorage::cleanName($name, 'csv'), $actor);
                $stmt = $this->db->prepare('INSERT INTO bank_imports(account_id,file_id,sha256,settings,total_rows,uploaded_by)
                    VALUES (?,?,?,?::jsonb,?,?) RETURNING id');
                $stmt->execute([$account, $fileId, $file['sha256'], json_encode($details, JSON_THROW_ON_ERROR), $details['total_rows'], $actor]);
                $id = (int) $stmt->fetchColumn();
                (new AuditLog($this->db))->record('bank_import_uploaded', $actor, ['import_id' => $id]);
                return [$id, true];
            });
            if (!$result[1]) { unlink($this->directory . '/' . $file['storage_name']); }
            return $result[0];
        } catch (Throwable $e) { @unlink($this->directory . '/' . $file['storage_name']); throw $e; }
    }
}
