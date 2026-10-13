<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Invoices;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Invoices\InvoiceRepository;
use Vereinskiste\Invoices\Import;
use Vereinskiste\Invoices\Extraction;
use Vereinskiste\Invoices\XmlExtractor;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Tests\Receipts\ReceiptFiles;
use Vereinskiste\Tests\TestCase;

final class InvoicesTest extends TestCase
{
    use ReceiptFiles;
    private PDO $db;
    protected function setUp():void
    {
        $this->db=$this->pdo();(new Migrator($this->db,dirname(__DIR__,2).'/migrations'))->migrate();
        $this->db->exec("INSERT INTO users(name,email,password_hash) VALUES('Admin','admin@example.org','x')");
    }
    protected function tearDown():void {$this->cleanScratch();}
    public static function xml():string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"><cbc:ID>INV-2026-01</cbc:ID><cbc:IssueDate>2026-10-13</cbc:IssueDate><cbc:DueDate>2026-11-13</cbc:DueDate><cbc:InvoiceTypeCode>380</cbc:InvoiceTypeCode><cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode><cac:AccountingSupplierParty><cac:Party><cac:PartyName><cbc:Name>Muster GmbH</cbc:Name></cac:PartyName></cac:Party></cac:AccountingSupplierParty><cac:TaxTotal><cbc:TaxAmount currencyID="EUR">19.00</cbc:TaxAmount></cac:TaxTotal><cac:LegalMonetaryTotal><cbc:TaxExclusiveAmount currencyID="EUR">100.00</cbc:TaxExclusiveAmount><cbc:TaxInclusiveAmount currencyID="EUR">119.00</cbc:TaxInclusiveAmount><cbc:PayableAmount currencyID="EUR">119.00</cbc:PayableAmount></cac:LegalMonetaryTotal></Invoice>';
    }
    private function data():array {return ['kind'=>'invoice','supplier_name'=>'Muster GmbH','number'=>'INV-2026-01','invoice_date'=>'2026-10-13','due_date'=>'2026-11-13','currency'=>'EUR','net'=>'100','tax'=>'19','gross'=>'119'];}
    public function test_import_original_extraction_and_review_never_create_a_booking():void
    {
        $directory=$this->scratchDir().'/documents';$file=$this->tmpFile(self::xml());
        $id=(new Import($this->db,$directory))->upload($file,'rechnung.xml',1);
        $repo=new InvoiceRepository($this->db);$documents=$repo->documents($id);$this->assertCount(1,$documents);$this->assertSame('pending',$documents[0]['extraction_status']);
        (new Extraction($this->db,['documents'=>$directory]))->process((int)$documents[0]['id']);
        $doc=$repo->documents($id)[0];$this->assertSame('done',$doc['extraction_status']);$this->assertSame('119.00',$doc['extraction']['fields']['gross']);
        $this->assertSame('draft',$repo->find($id)['status']);$this->assertSame('',$repo->find($id)['number']);
        $repo->save($id,$this->data(),(int)$repo->find($id)['version'],1,'Original geprüft');
        $repo->transition($id,(int)$repo->find($id)['version'],'review','Prüfung abgeschlossen',false,1);
        $this->assertSame('reviewed',$repo->find($id)['status']);$this->assertSame(0,(int)$this->db->query('SELECT count(*) FROM bookings')->fetchColumn());
        $this->assertSame(hash_file('sha256',$file),$this->db->query('SELECT sha256 FROM stored_files')->fetchColumn());
        $this->expectException(ValidationError::class);$repo->save($id,$this->data(),(int)$repo->find($id)['version'],1,'Überschreiben');
    }
    public function test_pdf_is_ready_for_manual_review_without_a_cron_extraction_job():void
    {
        $repo=new InvoiceRepository($this->db);
        $id=(new Import($this->db,$this->scratchDir().'/documents'))->upload($this->tmpFile($this->pdfBytes()),'rechnung.pdf',1);
        $this->assertSame('manual',$repo->documents($id)[0]['extraction_status']);
        $this->assertSame(0,(int)$this->db->query("SELECT count(*) FROM jobs WHERE kind='invoice_extract'")->fetchColumn());
        $repo->save($id,$this->data(),2,1,'Manuell geprüft');$repo->transition($id,3,'review','Bestätigt',false,1);
        $this->assertSame('reviewed',$repo->find($id)['status']);
    }
    public function test_duplicate_file_is_rejected_without_orphan_files_and_draft_data_is_versioned():void
    {
        $directory=$this->scratchDir().'/documents';$path=$this->tmpFile(self::xml());$import=new Import($this->db,$directory);$id=$import->upload($path,'a.xml',1);
        try {$import->upload($path,'b.xml',1);$this->fail('duplicate');}catch(ValidationError $e){$this->assertSame('invoices.error.file_duplicate',$e->errors['receipt']);}
        $this->assertCount(1,glob($directory.'/*.xml'));$this->assertSame(1,(int)$this->db->query('SELECT count(*) FROM invoices')->fetchColumn());
        $repo=new InvoiceRepository($this->db);$this->expectException(Conflict::class);$repo->save($id,$this->data(),999,1,'stale');
    }
    public function test_review_requires_exact_totals_and_a_document_and_cancellation_is_audited():void
    {
        $repo=new InvoiceRepository($this->db);$data=$this->data();$data['gross']='120';$id=$repo->save(null,$data,0,1,'');
        try {$repo->transition($id,1,'review','Prüfung',false,1);$this->fail('sum');}catch(ValidationError $e){$this->assertArrayHasKey('gross',$e->errors);$this->assertArrayHasKey('receipt',$e->errors);}
        $repo->transition($id,1,'cancel','Falscher Entwurf',false,1);$this->assertSame('cancelled',$repo->find($id)['status']);$this->assertCount(2,$repo->historyRows($id));
    }
    public function test_xml_external_entities_are_rejected_and_multiple_amount_candidates_not_guessed():void
    {
        $xml=str_replace('<cac:TaxTotal>', '<cac:TaxTotal>',self::xml());
        $xml=str_replace('</cac:TaxTotal>','</cac:TaxTotal><cac:TaxTotal><cbc:TaxAmount>999</cbc:TaxAmount></cac:TaxTotal>',$xml);
        $result=(new XmlExtractor())->extract($this->tmpFile($xml));$this->assertArrayNotHasKey('tax',$result['fields']);
        $bad='<?xml version="1.0"?><!DOCTYPE Invoice [<!ENTITY x SYSTEM "file:///etc/passwd">]><Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2">&x;</Invoice>';
        $this->expectException(ValidationError::class);(new XmlExtractor())->extract($this->tmpFile($bad));
    }
    public function test_cii_dates_use_declared_format_and_non_eur_is_not_confirmed():void
    {
        $xml='<?xml version="1.0"?><rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100" xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100"><rsm:ExchangedDocument><ram:ID>CII-1</ram:ID><ram:TypeCode>380</ram:TypeCode><ram:IssueDateTime><udt:DateTimeString format="102">20261013</udt:DateTimeString></ram:IssueDateTime></rsm:ExchangedDocument></rsm:CrossIndustryInvoice>';
        $fields=(new XmlExtractor())->extract($this->tmpFile($xml))['fields'];$this->assertSame('2026-10-13',$fields['invoice_date']);$this->assertSame('invoice',$fields['kind']);
        $repo=new InvoiceRepository($this->db);$data=$this->data();$data['currency']='USD';$id=$repo->save(null,$data,0,1,'');
        try {$repo->transition($id,1,'review','Prüfung',false,1);$this->fail('currency');}catch(ValidationError $e){$this->assertSame('invoices.error.currency',$e->errors['currency']);}
    }
}
