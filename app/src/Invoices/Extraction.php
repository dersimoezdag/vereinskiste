<?php
declare(strict_types=1);
namespace Vereinskiste\Invoices;

use PDO;
use Closure;
use Vereinskiste\Files\DocumentStore;
use Vereinskiste\Intake\DocumentProcessor;
use Vereinskiste\Ledger\Transaction;

final class Extraction
{
    /** Injection for deterministic scanner/OCR tests. Production always uses DocumentProcessor. */
    public function __construct(private PDO $pdo, private array $roots, private ?Closure $processor = null) {}

    public function process(int $document): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM invoice_documents WHERE id=?');
        $stmt->execute([$document]); $row = $stmt->fetch();
        if (!$row || ($row['extraction_status'] !== 'pending' && $row['security_status'] !== 'error')) { return; }
        $file = (new DocumentStore($this->pdo, $this->roots))->open((int) $row['file_id']);
        $security = 'error'; $status = 'failed'; $result = ['fields'=>[], 'warning'=>'intake.security.required'];
        if ($file) {
            try {
                $processed = $this->processor ? ($this->processor)($file['path'],$file['mime']) : (new DocumentProcessor())->process($file['path'],$file['mime']);
                $security = $processed['security'];
                if ($security === 'clean') {
                    if ($file['mime'] === 'application/xml') {
                        try { $result = (new XmlExtractor())->extract($file['path']); $status = 'done'; }
                        catch (\Throwable) { $result = ['fields'=>[], 'warning'=>'invoices.extraction.failed']; }
                    } elseif (isset($processed['extraction'])) { $result = $processed['extraction']; $status = 'done'; }
                    else { $result = ['fields'=>[], 'warning'=>'invoices.extraction.manual']; $status = 'manual'; }
                } elseif ($security === 'blocked') { $result['warning'] = 'intake.security.blocked'; }
            } catch (\Throwable) { /* Fail closed, no command details in logs or UI. */ }
        }
        Transaction::run($this->pdo, function () use ($document,$security,$status,$result): void {
            $this->pdo->prepare("UPDATE invoice_documents SET security_status=?,security_checked_at=now(),extraction_status=?,extraction=?::jsonb WHERE id=? AND (extraction_status='pending' OR security_status='error')")
                ->execute([$security,$status,json_encode($result,JSON_THROW_ON_ERROR),$document]);
        });
        if ($security === 'error') { throw new \RuntimeException('Document security processing unavailable'); }
    }
}
