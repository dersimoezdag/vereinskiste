<?php
declare(strict_types=1);
namespace Vereinskiste\Invoices;

use PDO;
use Throwable;
use Vereinskiste\Files\FileStorage;
use Vereinskiste\Files\StoredFileRepository;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

final class Import
{
    public function __construct(private PDO $pdo,private string $directory) {}
    public function upload(string $path,string $name,int $actor,?int $invoice=null):int
    {
        $file=(new FileStorage($this->directory))->ingest($path,['application/pdf','image/jpeg','image/png','application/xml']);
        try {
            (new \Vereinskiste\Intake\DocumentInspection())->inspect($this->directory.'/'.$file['storage_name'],$file['mime']);
            return Transaction::run($this->pdo,function()use($file,$name,$actor,$invoice):int {
                $this->pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(?))')->execute([$file['sha256']]);
                $stmt=$this->pdo->prepare('SELECT invoice_id FROM invoice_documents WHERE original_sha256=?');$stmt->execute([$file['sha256']]);
                if($stmt->fetchColumn()!==false) {throw new ValidationError(['receipt'=>'invoices.error.file_duplicate']);}
                $repo=new InvoiceRepository($this->pdo);
                if($invoice===null) {$invoice=$repo->save(null,['kind'=>'invoice','currency'=>'EUR'],0,$actor,'');}
                else {$existing=$repo->find($invoice,true);if(!$existing||$existing['status']!=='draft') {throw new ValidationError(['status'=>'invoices.error.immutable']);}}
                $fileId=(new StoredFileRepository($this->pdo))->register($file,'documents',FileStorage::cleanName($name,$file['ext']),$actor);
                $xml=$file['mime']==='application/xml';
                $stmt=$this->pdo->prepare("INSERT INTO invoice_documents(invoice_id,file_id,original_sha256,role,extraction_status,extraction,security_status,security_checked_at) VALUES(?,?,?,?,?,?::jsonb,'validated',now()) RETURNING id");
                $stmt->execute([$invoice,$fileId,$file['sha256'],count($repo->documents($invoice))===0?'original':'supplement',$xml?'pending':'manual',json_encode($xml?(object)[]:['fields'=>[],'warning'=>'invoices.extraction.manual'],JSON_THROW_ON_ERROR)]);$document=(int)$stmt->fetchColumn();
                if($xml) {(new \Vereinskiste\Jobs\Queue($this->pdo))->enqueue('invoice_extract',['document_id'=>$document],(string)$document,20);}
                $this->pdo->prepare('UPDATE invoices SET version=version+1 WHERE id=?')->execute([$invoice]);
                return $invoice;
            });
        } catch(Throwable $e) {@unlink($this->directory.'/'.$file['storage_name']);throw $e;}
    }
}
