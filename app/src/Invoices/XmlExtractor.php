<?php
declare(strict_types=1);
namespace Vereinskiste\Invoices;

use DOMDocument;
use DOMXPath;
use Vereinskiste\Files\InvoiceXmlInput;

/** Bounded field extraction only: never claims complete EN16931/KoSIT validation. */
final class XmlExtractor
{
    public function extract(string $path):array
    {
        (new InvoiceXmlInput())->validate($path);
        $previous=libxml_use_internal_errors(true);
        try {
            $doc=new DOMDocument();
            if(!$doc->load($path,LIBXML_NONET)||$doc->doctype!==null) {throw new \RuntimeException('xml_invalid');}
            $x=new DOMXPath($doc);$x->registerNamespace('cbc','urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
            $x->registerNamespace('cac','urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
            $x->registerNamespace('rsm','urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
            $x->registerNamespace('ram','urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
            $x->registerNamespace('udt','urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');
            $root=$doc->documentElement;
            $cii=$root->localName==='CrossIndustryInvoice';
            $map=$cii?[
                'number'=>'/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID',
                'invoice_date'=>'/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString',
                'supplier_name'=>'/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:Name',
                'currency'=>'/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:InvoiceCurrencyCode',
                'net'=>'/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TaxBasisTotalAmount',
                'tax'=>'/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TaxTotalAmount',
                'gross'=>'/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:GrandTotalAmount',
                'due_date'=>'/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString',
            ]:[
                'number'=>'/*/cbc:ID','invoice_date'=>'/*/cbc:IssueDate','due_date'=>'/*/cbc:DueDate',
                'supplier_name'=>'/*/cac:AccountingSupplierParty/cac:Party/cac:PartyName/cbc:Name',
                'currency'=>'/*/cbc:DocumentCurrencyCode','net'=>'/*/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount',
                'tax'=>'/*/cac:TaxTotal/cbc:TaxAmount','gross'=>'/*/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount',
            ];
            $fields=[];$sources=[];
            foreach($map as $field=>$expr) {
                $nodes=$x->query($expr);if($nodes->length!==1) {continue;}
                $value=trim($nodes->item(0)->textContent);
                if($cii&&in_array($field,['invoice_date','due_date'],true)) {
                    if($nodes->item(0)->getAttribute('format')!=='102'||!preg_match('/^[0-9]{8}\z/',$value)) {continue;}
                    $value=substr($value,0,4).'-'.substr($value,4,2).'-'.substr($value,6,2);
                }
                if(in_array($field,['net','tax','gross'],true)) {
                    if(!preg_match('/^[0-9]+(?:\.[0-9]{1,2})?\z/',$value)) {continue;}
                    try {$value=$field==='gross'?\Vereinskiste\Ledger\Money::parse($value):\Vereinskiste\Ledger\Money::parseSigned($value);}catch(\Vereinskiste\Ledger\InvalidAmount) {continue;}
                }
                if(in_array($field,['invoice_date','due_date'],true)) {
                    $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$date||$date->format('Y-m-d')!==$value) {continue;}
                }
                if($field==='currency'&&!preg_match('/^[A-Z]{3}\z/',$value)) {continue;}
                if($value!==''&&mb_strlen($value)<=InvoiceRepository::FIELDS[$field]) {$fields[$field]=$value;$sources[$field]=$expr;}
            }
            if(!$cii&&empty($fields['supplier_name'])) {
                $value=trim($x->evaluate('string(/*/cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName)'));
                if($value!==''&&mb_strlen($value)<=200) {$fields['supplier_name']=$value;$sources['supplier_name']='/*/cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName';}
            }
            $kind=$root->localName==='CreditNote'?'credit_note':'invoice';
            if($cii) {
                $type=$x->evaluate('string(/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode)');
                if($type==='381') {$kind='credit_note';}
                elseif($type!=='380') {return ['fields'=>$fields,'sources'=>$sources,'format'=>'CII','warning'=>'invoices.extraction.document_kind'];}
            } else {
                $type=$x->evaluate('string(/*/cbc:InvoiceTypeCode)');
                if($root->localName==='Invoice'&&$type!==''&&$type!=='380') {return ['fields'=>$fields,'sources'=>$sources,'format'=>'UBL','warning'=>'invoices.extraction.document_kind'];}
            }
            $fields['kind']=$kind;
            return ['fields'=>$fields,'sources'=>$sources,'format'=>$cii?'CII':'UBL','warning'=>'invoices.extraction.manual_review'];
        } finally {libxml_clear_errors();libxml_use_internal_errors($previous);}
    }
}
