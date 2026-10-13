<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Reports;

use Vereinskiste\Invoices\Payments;
use Vereinskiste\Invoices\InvoiceRepository;
use Vereinskiste\Invoices\Import;
use Vereinskiste\Invoices\Extraction;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Ledger\BookingService;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Tests\Invoices\InvoicesTest;
use Vereinskiste\Tests\Receipts\ReceiptFiles;
use Vereinskiste\Tests\TestCase;

final class PaymentsTest extends TestCase
{
    use ReportFixtures;
    use ReceiptFiles;
    private int $author;
    private int $reviewer;
    private int $invoice;
    protected function setUp():void
    {
        $this->migrated();$this->pdo->exec('UPDATE finance_payment_settings SET four_eyes=true');$this->author=$this->user('author@example.org');$this->reviewer=$this->user('reviewer@example.org');
        $dir=$this->scratchDir().'/documents';
        $this->invoice=(new Import($this->pdo,$dir))->upload($this->tmpFile(InvoicesTest::xml()),'beleg.xml',$this->author);
        (new Extraction($this->pdo,['documents'=>$dir]))->process(1);
        $repo=new InvoiceRepository($this->pdo);
        $repo->save($this->invoice,['kind'=>'invoice','supplier_name'=>'Lieferant','number'=>'R-1','invoice_date'=>'2026-10-13','currency'=>'EUR','net'=>'100','tax'=>'19','gross'=>'119'],2,$this->author,'Beleg geprüft');
        $repo->transition($this->invoice,3,'review','Prüfung',false,$this->reviewer);
    }
    protected function tearDown():void {$this->cleanScratch();}
    private function input():array {return ['kind'=>'supplier','recipient'=>'Lieferant','iban'=>'DE89370400440532013000','purpose'=>'R-1','reason'=>'Sommerfest','recipient_verified'=>'1'];}
    public function test_four_eyes_and_changed_recipient_require_another_approver():void
    {
        $p=new Payments($this->pdo);$p->save($this->invoice,$this->input(),0,$this->author);
        try {$p->approve($this->invoice,1,'Selbstfreigabe',$this->author);$this->fail('self approval');}catch(ValidationError $e) {$this->assertSame('intake.payment.self_approval',$e->errors['payment']);}
        $p->save($this->invoice,$this->input(),1,$this->reviewer);
        try {$p->approve($this->invoice,2,'Selbstfreigabe nach Änderung',$this->reviewer);$this->fail('edited own');}catch(ValidationError) {}
        $third=$this->user('third@example.org');$p->approve($this->invoice,2,'Unabhängig geprüft',$third);
        $this->assertSame('approved',$p->get($this->invoice)['status']);
        $this->assertSame(0,(int)$this->pdo->query('SELECT count(*) FROM bookings')->fetchColumn());
        $this->assertSame(3,(int)$this->pdo->query('SELECT count(*) FROM invoice_payment_changes')->fetchColumn());
    }
    public function test_member_cannot_approve_own_reimbursement_and_name_is_from_verified_member():void
    {
        $this->pdo->exec("INSERT INTO members(name) VALUES('Erika')");
        $this->pdo->prepare('UPDATE users SET member_id=1 WHERE id=?')->execute([$this->reviewer]);
        $p=new Payments($this->pdo);$p->save($this->invoice,$this->input()+['member_id'=>'1'],0,$this->author);
        $input=$this->input();$input['kind']='reimbursement';$input['member_id']='1';$input['recipient']='Spoofed name';
        $p->save($this->invoice,$input,1,$this->author);
        $this->assertSame('Erika',$p->get($this->invoice)['recipient']);
        $this->expectException(ValidationError::class);$p->approve($this->invoice,2,'Eigenes Mitglied',$this->reviewer);
    }
    public function test_partial_payments_storno_and_unlink_update_remaining_exactly():void
    {
        $p=new Payments($this->pdo);$p->save($this->invoice,$this->input(),0,$this->author);$p->approve($this->invoice,1,'Prüfung',$this->reviewer);
        $a=$this->account();$b=$this->booking($a,$this->author,'2026-10-13','out','60.00');
        $p->allocate($this->invoice,$b,2,'Teilzahlung',$this->reviewer);
        $this->assertSame('59.00',$p->overview($this->invoice)['remaining']);
        $list=(new InvoiceRepository($this->pdo))->list('partially_paid')[0];
        $this->assertSame($p->overview($this->invoice)['paid'],$list['paid_total']);
        $this->assertSame($p->overview($this->invoice)['remaining'],$list['remaining']);
        $linked=new \Vereinskiste\Invoices\LinkedDocuments($this->pdo);
        $this->assertCount(1,$linked->forBooking($b));
        $this->assertSame(1,(int)$this->pdo->query('SELECT count(*) FROM stored_files')->fetchColumn());
        $this->assertSame(0,(int)$this->pdo->query('SELECT count(*) FROM receipts')->fetchColumn());
        $this->assertCount(1,(new InvoiceRepository($this->pdo))->list('partially_paid'));
        $this->assertCount(0,(new InvoiceRepository($this->pdo))->list('paid'));
        $this->assertSame('60.00',$p->overview($this->invoice)['paid']);
        $c=$this->booking($a,$this->author,'2026-10-14','out','59.00');$p->allocate($this->invoice,$c,3,'Restzahlung',$this->reviewer);
        $this->assertSame('0.00',$p->overview($this->invoice)['remaining']);
        $this->assertCount(1,(new InvoiceRepository($this->pdo))->list('paid'));
        $this->assertCount(0,(new InvoiceRepository($this->pdo))->list('overdue'));
        (new BookingService($this->pdo))->cancel($b,'Storno',1,$this->author);
        $this->assertSame('60.00',$p->overview($this->invoice)['remaining']);
        $p->unlink($this->invoice,$b,4,'Falsche Zuordnung',$this->reviewer);
        $this->assertCount(1,$p->overview($this->invoice)['allocations']);
        $this->assertSame([],$linked->forBooking($b));
        $list=(new InvoiceRepository($this->pdo))->list('partially_paid')[0];
        $this->assertSame($p->overview($this->invoice)['remaining'],$list['remaining']);
    }
    public function test_transfer_overpayment_duplicate_and_stale_version_cannot_allocate():void
    {
        $p=new Payments($this->pdo);$p->save($this->invoice,$this->input(),0,$this->author);$p->approve($this->invoice,1,'Prüfung',$this->reviewer);$a=$this->account();
        $transfer=$this->booking($a,$this->author,'2026-10-13','out','1.00','Umbuchung',null,'transfer');
        $over=$this->booking($a,$this->author,'2026-10-13','out','120.00');
        foreach([$transfer,$over] as $id) {try {$p->allocate($this->invoice,$id,2,'Unzulässig',$this->reviewer);$this->fail('invalid');}catch(ValidationError) {}}
        $valid=$this->booking($a,$this->author,'2026-10-13','out','50.00');$p->allocate($this->invoice,$valid,2,'Teil',$this->reviewer);
        try {$p->allocate($this->invoice,$valid,3,'Doppelt',$this->reviewer);$this->fail('duplicate');}catch(ValidationError) {}
        try {(new BookingService($this->pdo))->update($valid,['amount'=>'60.00'],'Ändern',1,$this->reviewer);$this->fail('linked edit');}catch(ValidationError) {}
        $this->expectException(Conflict::class);$p->unlink($this->invoice,$valid,2,'Veraltet',$this->reviewer);
    }
    public function test_single_person_mode_allows_treasurer_to_prepare_and_approve():void
    {
        $this->pdo->exec('UPDATE finance_payment_settings SET four_eyes=false');
        $p=new Payments($this->pdo);$p->save($this->invoice,$this->input(),0,$this->author);$p->approve($this->invoice,1,'Einzelbearbeitung',$this->author);
        $this->assertSame('approved',$p->get($this->invoice)['status']);
        $after=json_decode($this->pdo->query('SELECT after_data FROM invoice_payment_changes ORDER BY id DESC LIMIT 1')->fetchColumn(),true);
        $this->assertFalse($after['four_eyes_required']);
    }
    public function test_missing_verification_and_unreviewed_invoice_cannot_be_approved():void
    {
        $p=new Payments($this->pdo);$input=$this->input();unset($input['recipient_verified']);
        try {$p->save($this->invoice,$input,0,$this->author);$this->fail('not verified');}catch(ValidationError) {}
        $p->save($this->invoice,$this->input(),0,$this->author);
        $this->pdo->exec("UPDATE invoices SET status='draft'");
        $this->expectException(ValidationError::class);$p->approve($this->invoice,1,'Ungeprüft',$this->reviewer);
    }
}
