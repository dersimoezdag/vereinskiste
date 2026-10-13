<?php
declare(strict_types=1);
namespace Vereinskiste\Invoices;

use PDO;
use Vereinskiste\Files\DocumentStore;
use Vereinskiste\Ledger\Transaction;

final class Extraction
{
    public function __construct(private PDO $pdo,private array $roots) {}
    public function process(int $document):void
    {
        $stmt=$this->pdo->prepare('SELECT * FROM invoice_documents WHERE id=?');$stmt->execute([$document]);$row=$stmt->fetch();
        if(!$row||$row['extraction_status']!=='pending') {return;}
        $file=(new DocumentStore($this->pdo,$this->roots))->open((int)$row['file_id']);
        $status='manual';$result=['fields'=>[],'warning'=>'invoices.extraction.manual'];
        if(!$file) {$status='failed';$result['warning']='invoices.extraction.failed';}
        elseif($file['mime']==='application/xml') {
            try {$result=(new XmlExtractor())->extract($file['path']);$status='done';}
            catch(\Throwable) {$status='failed';$result=['fields'=>[],'warning'=>'invoices.extraction.failed'];}
        }
        Transaction::run($this->pdo,function()use($document,$status,$result):void {
            $this->pdo->prepare("UPDATE invoice_documents SET extraction_status=?,extraction=?::jsonb WHERE id=? AND extraction_status='pending'")->execute([$status,json_encode($result,JSON_THROW_ON_ERROR),$document]);
        });
    }
}
