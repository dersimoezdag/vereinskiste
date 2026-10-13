<?php
declare(strict_types=1);
namespace Vereinskiste\Invoices;

use PDO;
use Vereinskiste\Files\DocumentStore;
use Vereinskiste\Intake\OcrSuggestions;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

final class Extraction
{
    public function __construct(private PDO $pdo,private array $roots) {}
    public function process(int $document): void
    {
        $stmt=$this->pdo->prepare('SELECT * FROM invoice_documents WHERE id=?');$stmt->execute([$document]);$row=$stmt->fetch();
        if(!$row||$row['extraction_status']!=='pending'||!in_array($row['security_status'],['validated','legacy','clean'],true)) {return;}
        $file=(new DocumentStore($this->pdo,$this->roots))->open((int)$row['file_id']);
        $status='manual';$result=['fields'=>[],'warning'=>'invoices.extraction.manual'];
        if(!$file) {$status='failed';$result['warning']='invoices.extraction.failed';}
        elseif($file['mime']==='application/xml') {
            try {$result=(new XmlExtractor())->extract($file['path']);$status='done';}
            catch(\Throwable) {$status='failed';$result=['fields'=>[],'warning'=>'invoices.extraction.failed'];}
        }
        $this->pdo->prepare("UPDATE invoice_documents SET extraction_status=?,extraction=?::jsonb WHERE id=? AND extraction_status='pending'")
            ->execute([$status,json_encode($result,JSON_THROW_ON_ERROR),$document]);
    }
    public function saveOcr(int $invoice,int $document,string $text): void
    {
        if(strlen($text)>80000||!mb_check_encoding($text,'UTF-8')||str_contains($text,"\0")) {throw new ValidationError(['ocr'=>'foundation.invalid']);}
        Transaction::run($this->pdo,function()use($invoice,$document,$text):void {
            $row=(new InvoiceRepository($this->pdo))->find($invoice,true);
            if(!$row||$row['status']!=='draft') {throw new ValidationError(['ocr'=>'invoices.error.immutable']);}
            $stmt=$this->pdo->prepare("SELECT 1 FROM invoice_documents d JOIN stored_files f ON f.id=d.file_id WHERE d.id=? AND d.invoice_id=? AND d.security_status IN ('validated','clean','legacy') AND f.mime IN ('application/pdf','image/jpeg','image/png') FOR UPDATE OF d");
            $stmt->execute([$document,$invoice]);if(!$stmt->fetchColumn()) {throw new ValidationError(['ocr'=>'foundation.invalid']);}
            $result=OcrSuggestions::extract($text);
            $this->pdo->prepare("UPDATE invoice_documents SET extraction_status='done',extraction=?::jsonb WHERE id=?")->execute([json_encode($result,JSON_THROW_ON_ERROR),$document]);
        });
    }
}
