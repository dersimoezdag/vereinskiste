<?php
declare(strict_types=1);
namespace Vereinskiste\Invoices;

use PDO;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Finance\BankDetails;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Ledger\Money;

final class Payments
{
    public function __construct(private PDO $pdo) {}
    public function get(int $invoice): ?array
    {
        $s=$this->pdo->prepare('SELECT * FROM invoice_payment_requests WHERE invoice_id=?');$s->execute([$invoice]);return $s->fetch()?:null;
    }
    public function overview(int $invoice): array
    {
        $s=$this->pdo->prepare("SELECT a.*,b.booking_date,b.description,b.status FROM invoice_payment_allocations a JOIN bookings b ON b.id=a.booking_id WHERE invoice_id=? ORDER BY a.id");$s->execute([$invoice]);$allocations=$s->fetchAll();
        $paid=0;foreach($allocations as $a) {if($a['status']==='active') {$paid+=Money::toCents($a['amount']);}}
        $row=(new InvoiceRepository($this->pdo))->find($invoice);
        $remaining=max(0,($row['gross']!==null?Money::toCents($row['gross']):0)-$paid);
        $c=$this->pdo->prepare("SELECT id,booking_date,amount,description,counterparty FROM bookings WHERE status='active' AND direction='out' AND kind<>'transfer'
            AND amount<=?::numeric AND NOT EXISTS(SELECT 1 FROM invoice_payment_allocations WHERE booking_id=bookings.id)
            ORDER BY booking_date DESC,id DESC LIMIT 50");$c->execute([Money::fromCents($remaining)]);
        $h=$this->pdo->prepare('SELECT c.*,u.name AS actor FROM invoice_payment_changes c JOIN users u ON u.id=c.actor_id WHERE invoice_id=? ORDER BY c.id DESC LIMIT 50');$h->execute([$invoice]);
        return ['request'=>$this->get($invoice),'paid'=>Money::fromCents($paid),'remaining'=>Money::fromCents($remaining),'allocations'=>$allocations,'candidates'=>$c->fetchAll(),'history'=>$h->fetchAll()];
    }
    public function save(int $invoice,array $input,int $version,int $actor): void
    {
        $values=Fields::read($input,['kind'=>20,'recipient'=>200,'iban'=>34,'purpose'=>200,'reason'=>500],['iban','purpose','reason']);
        if($values['kind']==='supplier'&&$values['recipient']==='') {throw new ValidationError(['recipient'=>'foundation.required']);}
        $values['iban']=BankDetails::read($values['iban'],'')['iban'];
        if(!in_array($values['kind'],['supplier','reimbursement'],true)||($input['recipient_verified']??'')!=='1') {throw new ValidationError(['payment'=>'intake.payment.verify']);}
        $member=$values['kind']==='reimbursement'?Fields::version($input['member_id']??null):null;
        Transaction::run($this->pdo,function()use($invoice,$values,$member,$version,$actor):void {
            $row=$this->lockInvoice($invoice);
            if($row['kind']!=='invoice'||$row['status']==='cancelled') {throw new ValidationError(['payment'=>'foundation.invalid']);}
            $before=$this->get($invoice);$this->checkVersion($before,$version);
            if($before&&$before['status']!=='draft') {throw new ValidationError(['payment'=>'intake.payment.locked']);}
            if($member!==null) {
                $s=$this->pdo->prepare('SELECT name FROM members WHERE id=? AND anonymized_at IS NULL');$s->execute([$member]);
                $name=$s->fetchColumn();if($name===false) {throw new ValidationError(['member_id'=>'foundation.invalid']);}
                // Recipient is the selected member, not an arbitrary mail/OCR suggestion.
                $values['recipient']=$name;
            }
            $this->pdo->prepare("INSERT INTO invoice_payment_requests(invoice_id,kind,member_id,recipient,iban,purpose,created_by) VALUES(?,?,?,?,?,?,?)
                ON CONFLICT(invoice_id) DO UPDATE SET kind=EXCLUDED.kind,member_id=EXCLUDED.member_id,recipient=EXCLUDED.recipient,iban=EXCLUDED.iban,purpose=EXCLUDED.purpose,created_by=EXCLUDED.created_by,version=invoice_payment_requests.version+1")
                ->execute([$invoice,$values['kind'],$member,$values['recipient'],$values['iban'],$values['purpose'],$actor]);
            $this->history($invoice,$before??[],$this->get($invoice),$actor,$values['reason']);
        });
    }
    public function approve(int $invoice,int $version,string $reason,int $actor,bool $reject=false): void
    {
        Fields::read(['reason'=>$reason],['reason'=>500],['reason']);
        Transaction::run($this->pdo,function()use($invoice,$version,$reason,$actor,$reject):void {
            $row=$this->lockInvoice($invoice);$before=$this->get($invoice);$this->checkVersion($before,$version);
            if(!$before||$before['status']!=='draft') {throw new ValidationError(['payment'=>'intake.payment.locked']);}
            if(!$reject) {
                if($row['status']!=='reviewed'||$row['kind']!=='invoice') {throw new ValidationError(['payment'=>'intake.payment.review_first']);}
                $s=$this->pdo->prepare('SELECT member_id FROM users WHERE id=?');$s->execute([$actor]);$member=$s->fetchColumn();
                if((int)$before['created_by']===$actor||(int)$row['created_by']===$actor||($member!==false&&$member!==null&&(int)$member===(int)$before['member_id'])) {
                    throw new ValidationError(['payment'=>'intake.payment.self_approval']);
                }
                $s=$this->pdo->prepare("SELECT count(*) FROM invoice_documents WHERE invoice_id=? AND security_status NOT IN ('clean','legacy')");$s->execute([$invoice]);
                if((int)$s->fetchColumn()>0) {throw new ValidationError(['payment'=>'intake.security.required']);}
            }
            $this->pdo->prepare('UPDATE invoice_payment_requests SET status=?,approved_by=?,approved_at=now(),version=version+1 WHERE invoice_id=?')
                ->execute([$reject?'rejected':'approved',$reject?null:$actor,$invoice]);
            $this->history($invoice,$before,$this->get($invoice),$actor,$reason);
        });
    }
    public function allocate(int $invoice,int $booking,int $version,string $reason,int $actor): void
    {
        Fields::read(['reason'=>$reason],['reason'=>500],['reason']);
        Transaction::run($this->pdo,function()use($invoice,$booking,$version,$reason,$actor):void {
            $row=$this->lockInvoice($invoice);$request=$this->get($invoice);$this->checkVersion($request,$version);
            if(!$request||$request['status']!=='approved'||$row['status']!=='reviewed') {throw new ValidationError(['payment'=>'intake.payment.review_first']);}
            $s=$this->pdo->prepare('SELECT * FROM bookings WHERE id=? FOR UPDATE');$s->execute([$booking]);$b=$s->fetch();
            $used=$this->pdo->prepare('SELECT 1 FROM invoice_payment_allocations WHERE booking_id=?');$used->execute([$booking]);
            $remaining=$this->overview($invoice)['remaining'];
            if(!$b||$b['status']!=='active'||$b['direction']!=='out'||$b['kind']==='transfer'||$used->fetchColumn()!==false||Money::toCents($b['amount'])>Money::toCents($remaining)) {
                throw new ValidationError(['booking_id'=>'intake.payment.invalid_booking']);
            }
            $this->pdo->prepare('INSERT INTO invoice_payment_allocations(invoice_id,booking_id,amount,actor_id,reason) VALUES(?,?,?,?,?)')->execute([$invoice,$booking,$b['amount'],$actor,$reason]);
            $this->pdo->prepare('UPDATE invoice_payment_requests SET version=version+1 WHERE invoice_id=?')->execute([$invoice]);
            $this->history($invoice,[],['booking_id'=>$booking,'amount'=>$b['amount']],$actor,$reason);
        });
    }
    public function unlink(int $invoice,int $booking,int $version,string $reason,int $actor): void
    {
        Fields::read(['reason'=>$reason],['reason'=>500],['reason']);
        Transaction::run($this->pdo,function()use($invoice,$booking,$version,$reason,$actor):void {
            $this->lockInvoice($invoice);$this->checkVersion($this->get($invoice),$version);
            $s=$this->pdo->prepare('DELETE FROM invoice_payment_allocations WHERE invoice_id=? AND booking_id=? RETURNING amount');$s->execute([$invoice,$booking]);$amount=$s->fetchColumn();
            if($amount===false) {throw new ValidationError(['payment'=>'foundation.invalid']);}
            $this->pdo->prepare('UPDATE invoice_payment_requests SET version=version+1 WHERE invoice_id=?')->execute([$invoice]);
            $this->history($invoice,['booking_id'=>$booking,'amount'=>$amount],['unlinked'=>true],$actor,$reason);
        });
    }
    private function lockInvoice(int $invoice): array
    {
        return (new InvoiceRepository($this->pdo))->find($invoice,true)??throw new ValidationError(['invoice'=>'foundation.invalid']);
    }
    private function checkVersion(?array $row,int $version): void {if((int)($row['version']??0)!==$version) {throw new Conflict();}}
    private function history(int $invoice,array $before,array $after,int $actor,string $reason): void
    {
        $this->pdo->prepare('INSERT INTO invoice_payment_changes(invoice_id,actor_id,reason,before_data,after_data) VALUES(?,?,?,?::jsonb,?::jsonb)')
            ->execute([$invoice,$actor,$reason,json_encode($before,JSON_THROW_ON_ERROR),json_encode($after,JSON_THROW_ON_ERROR)]);
    }
}
