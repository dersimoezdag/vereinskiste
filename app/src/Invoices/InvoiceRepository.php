<?php
declare(strict_types=1);
namespace Vereinskiste\Invoices;

use PDO;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Ledger\Money;
use Vereinskiste\Ledger\InvalidAmount;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

final class InvoiceRepository
{
    public const FIELDS=['supplier_name'=>200,'number'=>100,'invoice_date'=>10,'due_date'=>10,'currency'=>3,'net'=>30,'tax'=>30,'gross'=>30,'notes'=>2000,'kind'=>20];
    public function __construct(private PDO $pdo) {}
    public function list(string $state='all',string $search='',int $page=1):array
    {
        $state=in_array($state,['draft','reviewed','cancelled','overdue','unpaid','partially_paid','paid','all'],true)?$state:'all';
        $stmt=$this->pdo->prepare("SELECT i.*,p.status AS payment_status,COALESCE(t.paid,0)::numeric(12,2)::text AS paid_total,
            GREATEST(COALESCE(i.gross,0)-COALESCE(t.paid,0),0)::numeric(12,2)::text AS remaining
            FROM invoices i LEFT JOIN invoice_payment_requests p ON p.invoice_id=i.id
            LEFT JOIN LATERAL (SELECT SUM(a.amount) AS paid FROM invoice_payment_allocations a JOIN bookings b ON b.id=a.booking_id WHERE a.invoice_id=i.id AND b.status='active') t ON true
            WHERE (i.number ILIKE ? OR i.supplier_name ILIKE ?) AND (?='all' OR i.status=?
                OR (?='overdue' AND i.status='reviewed' AND i.kind='invoice' AND i.due_date<CURRENT_DATE AND COALESCE(t.paid,0)<i.gross)
                OR (?='unpaid' AND i.status='reviewed' AND i.kind='invoice' AND COALESCE(t.paid,0)=0)
                OR (?='partially_paid' AND i.status='reviewed' AND i.kind='invoice' AND t.paid>0 AND t.paid<i.gross)
                OR (?='paid' AND i.status='reviewed' AND i.kind='invoice' AND t.paid>=i.gross)) ORDER BY i.id DESC LIMIT 50 OFFSET ?");
        $q='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],mb_substr($search,0,100)).'%';
        $stmt->execute([$q,$q,$state,$state,$state,$state,$state,$state,(max(1,min(100000,$page))-1)*50]);return $stmt->fetchAll();
    }

    public function find(int $id,bool $lock=false):?array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM invoices WHERE id=?'.($lock?' FOR UPDATE':''));$stmt->execute([$id]);return $stmt->fetch()?:null;
    }
    public function documents(int $id):array
    {
        $stmt=$this->pdo->prepare('SELECT d.*,f.original_name,f.mime FROM invoice_documents d JOIN stored_files f ON f.id=d.file_id WHERE invoice_id=? ORDER BY d.id');$stmt->execute([$id]);
        return array_map(static function(array $r):array {$r['extraction']=json_decode($r['extraction'],true,512,JSON_THROW_ON_ERROR);return $r;},$stmt->fetchAll());
    }
    public function duplicates(array $data,int $except):array
    {
        if(($data['number']??'')===''||empty($data['invoice_date'])||empty($data['gross'])) {return [];}
        $stmt=$this->pdo->prepare("SELECT id,status FROM invoices WHERE id<>? AND status<>'cancelled' AND lower(supplier_name)=lower(?) AND number=? AND invoice_date=? AND gross=? AND kind=? ORDER BY id LIMIT 10");
        $stmt->execute([$except,$data['supplier_name'],$data['number'],$data['invoice_date'],$data['gross'],$data['kind']]);return $stmt->fetchAll();
    }
    public function save(?int $id,array $input,int $version,int $actor,string $reason):int
    {
        $data=$this->read($input);
        return Transaction::run($this->pdo,function()use($id,$data,$version,$actor,$reason):int {
            $before=$id===null?null:$this->find($id,true);
            if($id!==null&&(!$before||(int)$before['version']!==$version)) {throw new Conflict();}
            if($before&&$before['status']!=='draft') {throw new ValidationError(['status'=>'invoices.error.immutable']);}
            if($before) {Fields::read(['reason'=>$reason],['reason'=>500],['reason']);}
            if($id===null) {
                $stmt=$this->pdo->prepare('INSERT INTO invoices(kind,supplier_id,supplier_name,number,invoice_date,due_date,currency,net,tax,gross,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id');
                $stmt->execute([$data['kind'],$data['supplier_id'],$data['supplier_name'],$data['number'],$data['invoice_date'],$data['due_date'],$data['currency'],$data['net'],$data['tax'],$data['gross'],$data['notes'],$actor]);$id=(int)$stmt->fetchColumn();
            } else {
                $this->pdo->prepare('UPDATE invoices SET kind=?,supplier_id=?,supplier_name=?,number=?,invoice_date=?,due_date=?,currency=?,net=?,tax=?,gross=?,notes=?,version=version+1 WHERE id=?')
                    ->execute([$data['kind'],$data['supplier_id'],$data['supplier_name'],$data['number'],$data['invoice_date'],$data['due_date'],$data['currency'],$data['net'],$data['tax'],$data['gross'],$data['notes'],$id]);
            }
            $this->history($id,$before??[],$this->find($id),$actor,$before?$reason:'Angelegt');return $id;
        });
    }
    public function transition(int $id,int $version,string $action,string $reason,bool $duplicateConfirmed,int $actor):void
    {
        $reason=Fields::read(['reason'=>$reason],['reason'=>500],['reason'])['reason'];
        Transaction::run($this->pdo,function()use($id,$version,$action,$reason,$duplicateConfirmed,$actor):void {
            $before=$this->find($id,true);if(!$before||(int)$before['version']!==$version) {throw new Conflict();}
            if($action==='review'&&$before['status']==='draft') {
                $errors=[];foreach(['supplier_name','number','invoice_date','gross'] as $field) {if(empty($before[$field])) {$errors[$field]='foundation.required';}}
                if($before['currency']!=='EUR') {$errors['currency']='invoices.error.currency';}
                if($before['net']===null||$before['tax']===null||$before['gross']===null||Money::toCents((string)$before['net'],true)+Money::toCents((string)$before['tax'],true)!==Money::toCents((string)$before['gross'])) {$errors['gross']='invoices.error.sum';}
                if(!$duplicateConfirmed&&$this->duplicates($before,$id)!==[]) {$errors['duplicate_confirmed']='invoices.error.duplicate';}
                $docs=$this->documents($id);if($docs===[]) {$errors['receipt']='invoices.error.document';}
                foreach($docs as $doc) {if(!in_array($doc['security_status'],['clean','legacy'],true)) {$errors['receipt']='intake.security.required';} if($doc['extraction_status']==='pending') {$errors['receipt']='invoices.error.processing';}}
                if($errors!==[]) {throw new ValidationError($errors);}
                $this->pdo->prepare("UPDATE invoices SET status='reviewed',reviewed_by=?,reviewed_at=now(),version=version+1 WHERE id=?")->execute([$actor,$id]);
            } elseif($action==='cancel'&&$before['status']!=='cancelled') {
                $this->pdo->prepare("UPDATE invoices SET status='cancelled',version=version+1 WHERE id=?")->execute([$id]);
            } else {throw new ValidationError(['status'=>'foundation.invalid']);}
            $this->history($id,$before,$this->find($id),$actor,$reason);
        });
    }
    public function historyRows(int $id):array
    {
        $stmt=$this->pdo->prepare('SELECT c.*,u.name AS actor FROM invoice_changes c JOIN users u ON u.id=c.actor_id WHERE invoice_id=? ORDER BY c.id DESC LIMIT 50');$stmt->execute([$id]);return $stmt->fetchAll();
    }
    private function read(array $input):array
    {
        $data=Fields::read($input,self::FIELDS);$errors=[];
        if(!in_array($data['kind'],['invoice','credit_note'],true)) {$errors['kind']='foundation.invalid';}
        if($data['currency']==='') {$data['currency']='EUR';}
        if(!preg_match('/^[A-Z]{3}\z/',$data['currency'])) {$errors['currency']='foundation.invalid';}
        foreach(['invoice_date','due_date'] as $field) {
            $value=$data[$field];$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
            if($value!==''&&(!$date||$date->format('Y-m-d')!==$value)) {$errors[$field]='foundation.invalid';}
            if($value==='') {$data[$field]=null;}
        }
        foreach(['net','tax','gross'] as $field) {
            try {$data[$field]=$data[$field]===''?null:($field==='gross'?Money::parse($data[$field]):Money::parseSigned($data[$field]));
                if($data[$field]!==null&&Money::toCents($data[$field],true)<0) {$errors[$field]='foundation.invalid';}
            } catch(InvalidAmount) {$errors[$field]='validation.amount.invalid';}
        }
        $supplier=$input['supplier_id']??'';$data['supplier_id']=null;
        if($supplier!=='') {
            if(!is_string($supplier)||!preg_match('/^[1-9][0-9]{0,8}\z/',$supplier)) {$errors['supplier_id']='foundation.invalid';}
            else {$contact=(new \Vereinskiste\Contacts\ContactRepository($this->pdo))->find((int)$supplier);
                if(!$contact) {$errors['supplier_id']='foundation.invalid';}else {$data['supplier_id']=$contact['id'];$data['supplier_name']=$contact['name'];}}
        }
        if($errors!==[]) {throw new ValidationError($errors);}return $data;
    }
    private function history(int $id,array $before,array $after,int $actor,string $reason):void
    {
        $this->pdo->prepare('INSERT INTO invoice_changes(invoice_id,actor_id,reason,before_data,after_data) VALUES(?,?,?,?::jsonb,?::jsonb)')->execute([$id,$actor,$reason,json_encode($before,JSON_THROW_ON_ERROR),json_encode($after,JSON_THROW_ON_ERROR)]);
        (new \Vereinskiste\Audit\AuditLog($this->pdo))->record('invoice_updated',$actor,['invoice_id'=>$id]);
    }
}
