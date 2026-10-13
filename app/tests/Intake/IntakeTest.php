<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Intake;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Intake\DocumentInspection;
use Vereinskiste\Intake\OcrSuggestions;
use Vereinskiste\Invoices\Import;
use Vereinskiste\Invoices\Extraction;
use Vereinskiste\Invoices\InvoiceRepository;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Tests\Invoices\InvoicesTest;
use Vereinskiste\Tests\Receipts\ReceiptFiles;
use Vereinskiste\Tests\TestCase;

final class IntakeTest extends TestCase
{
    use ReceiptFiles;
    private PDO $db;
    protected function setUp():void
    {
        $this->db=$this->pdo();(new Migrator($this->db,dirname(__DIR__,2).'/migrations'))->migrate();
        $this->db->exec("INSERT INTO users(name,email,password_hash) VALUES('Finanzer','finanzer@example.org','x')");
    }
    protected function tearDown():void {$this->cleanScratch();}
    public function test_upload_is_format_checked_and_duplicate_is_rejected():void
    {
        $import=new Import($this->db,$this->scratchDir().'/documents');$path=$this->tmpFile(InvoicesTest::xml());
        $id=$import->upload($path,'../beleg.xml',1);
        $this->assertSame('validated',$this->db->query('SELECT security_status FROM invoice_documents')->fetchColumn());
        $this->assertSame('beleg.xml',$this->db->query('SELECT original_name FROM stored_files')->fetchColumn());
        $this->assertSame(0,(int)$this->db->query('SELECT count(*) FROM invoice_payment_requests')->fetchColumn());
        $this->expectException(ValidationError::class);$import->upload($path,'duplicate.xml',1);
    }
    public function test_active_pdf_and_obfuscated_names_are_rejected_without_files_or_rows():void
    {
        foreach(['/JavaScript','/J#53','/OpenAction','/EmbeddedFile','/ObjStm','/Encrypt'] as $feature) {
            $path=$this->tmpFile($this->pdfBytes().$feature."\n%%EOF");
            try {(new Import($this->db,$this->scratchDir().'/documents'))->upload($path,'x.pdf',1);$this->fail('active PDF');}catch(ValidationError $e) {$this->assertSame('intake.document.active_pdf',$e->errors['receipt']);}
        }
        $this->assertSame(0,(int)$this->db->query('SELECT count(*) FROM invoices')->fetchColumn());
        $this->assertCount(0,glob($this->scratchDir().'/documents/*'));
    }
    public function test_ocr_text_is_untrusted_and_bound_to_draft_document():void
    {
        $dir=$this->scratchDir().'/documents';$id=(new Import($this->db,$dir))->upload($this->tmpFile($this->pdfBytes()),'beleg.pdf',1);
        $extract=new Extraction($this->db,['documents'=>$dir]);
        $extract->saveOcr($id,1,"<script>alert(1)</script>\nGesamtbetrag: 12,50 EUR\n13.10.2026");
        $doc=(new InvoiceRepository($this->db))->documents($id)[0];
        $this->assertSame('12.50',$doc['extraction']['fields']['gross']);
        $this->assertSame('',$this->db->query('SELECT number FROM invoices')->fetchColumn());
        $this->assertNull($this->db->query('SELECT gross FROM invoices')->fetchColumn());
        try {$extract->saveOcr($id+1,1,'Wrong invoice');$this->fail('binding');}catch(ValidationError) {}
        $this->db->exec("UPDATE invoices SET status='cancelled'");
        $this->expectException(ValidationError::class);$extract->saveOcr($id,1,'Late OCR');
    }
    public function test_ocr_never_overwrites_xml_or_accepts_unbounded_text():void
    {
        $id=(new Import($this->db,$this->scratchDir().'/documents'))->upload($this->tmpFile(InvoicesTest::xml()),'x.xml',1);
        $e=new Extraction($this->db,[]);
        try {$e->saveOcr($id,1,'Text');$this->fail('XML OCR');}catch(ValidationError) {}
        $this->expectException(ValidationError::class);$e->saveOcr($id,1,str_repeat('a',80001));
    }
    public function test_ocr_suggestions_are_exact_and_do_not_infer_payment_recipient():void
    {
        $r=OcrSuggestions::extract("Rechnungsnummer: FEST-42\n13.10.2026\nGesamtbetrag: 1.234,56 EUR\nIBAN DE89370400440532013000");
        $this->assertSame('1234.56',$r['fields']['gross']);$this->assertSame('2026-10-13',$r['fields']['invoice_date']);$this->assertSame('FEST-42',$r['fields']['number']);
        $this->assertArrayNotHasKey('iban',$r['fields']);$this->assertArrayNotHasKey('recipient',$r['fields']);
        $this->assertArrayNotHasKey('invoice_date',OcrSuggestions::extract('31.02.2026')['fields']);
    }
}
