<?php
declare(strict_types=1);
namespace Vereinskiste\ClubDocuments;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Vereinskiste\Ledger\ValidationError;
use ZipArchive;

final class Docx
{
    public static function read(string $path): string
    {
        if (filesize($path)>10485760) { throw new ValidationError(['receipt'=>'validation.receipt.too_large']); }
        $zip=new ZipArchive();
        if ($zip->open($path,ZipArchive::RDONLY)!==true) { self::invalid(); }
        try {
            $stat=$zip->statName('word/document.xml');
            if (!$stat || $stat['size']>2097152 || $zip->locateName('[Content_Types].xml')===false) { self::invalid(); }
            $xml=$zip->getFromName('word/document.xml');
        } finally { $zip->close(); }
        if (!is_string($xml) || stripos($xml,'<!DOCTYPE')!==false) { self::invalid(); }
        $dom=new DOMDocument();
        if (!@$dom->loadXML($xml,LIBXML_NONET)) { self::invalid(); }
        $xpath=new DOMXPath($dom);
        $xpath->registerNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $body=$xpath->query('/w:document/w:body')->item(0);
        if (!$body) { self::invalid(); }
        $html='';
        foreach ($body->childNodes as $node) {
            if ($node instanceof DOMElement) { $html.=self::element($node,$xpath); }
        }
        if (strlen($html)>200000) { throw new ValidationError(['content'=>'foundation.invalid']); }
        return Content::clean($html);
    }

    private static function element(DOMElement $node,DOMXPath $xpath): string
    {
        $tags=['p'=>'p','tbl'=>'table','tr'=>'tr','tc'=>'td'];
        if ($node->localName==='p') {
            $text='';
            foreach ($xpath->query('.//w:r',$node) as $run) {
                $part='';
                foreach ($run->childNodes as $child) {
                    $part.=match($child->localName) {
                        't'=>htmlspecialchars($child->textContent,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),
                        'br','cr'=>'<br>', 'tab'=>' ', default=>'',
                    };
                }
                foreach (['b'=>'strong','i'=>'em','u'=>'u'] as $property=>$tag) {
                    if ($xpath->query('w:rPr/w:'.$property.'[not(@w:val="0") and not(@w:val="false") and not(@w:val="none")]',$run)->length) { $part='<'.$tag.'>'.$part.'</'.$tag.'>'; }
                }
                $text.=$part;
            }
        } else {
            $text='';
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement && isset($tags[$child->localName])) { $text.=self::element($child,$xpath); }
            }
        }
        $tag=$tags[$node->localName] ?? 'div';
        return '<'.$tag.'>'.$text.'</'.$tag.'>';
    }

    private static function invalid(): never
    {
        throw new ValidationError(['receipt'=>'documents.docx_invalid']);
    }
}
