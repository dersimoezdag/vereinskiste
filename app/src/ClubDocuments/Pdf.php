<?php
declare(strict_types=1);
namespace Vereinskiste\ClubDocuments;
use Dompdf\Dompdf;
use Dompdf\Options;

final class Pdf
{
    public static function render(array $document, string $club): string
    {
        $escape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $options = new Options(['isRemoteEnabled'=>false,'isPhpEnabled'=>false,'isJavascriptEnabled'=>false,'defaultFont'=>'DejaVu Sans','chroot'=>dirname(__DIR__,2).'/vendor/dompdf','allowedProtocols'=>[]]);
        $pdf = new Dompdf($options);
        $meta=date('d.m.Y',strtotime($document['document_date'])).' · '.($document['label'] ?: 'Version '.$document['version']);
        if ($document['kind']==='minutes') { $meta.=' · '.($document['meeting_type']==='board'?'Vorstandssitzung':'Mitgliederversammlung'); }
        $meta.=' · '.($document['status']==='draft'?'Entwurf':'Freigegeben');
        $participants=$document['kind']==='minutes'?'<p><strong>Teilnehmer:</strong> '.nl2br($escape($document['participants'])).'</p>':'';
        $pdf->loadHtml('<!doctype html><html lang="de"><meta charset="utf-8"><style>@page{margin:20mm}body{font-family:DejaVu Sans;font-size:10pt}h1{font-size:18pt}table{border-collapse:collapse;width:100%}td,th{border:1px solid #888;padding:5px}a{color:#222}pre{white-space:pre-wrap}</style><body><p>'.$escape($club).'</p><h1>'.$escape($document['title']).'</h1><p>'.$escape($meta).'</p>'.$participants.'<hr>'.Content::clean($document['content']).'</body></html>', 'UTF-8');
        $pdf->setPaper('A4'); $pdf->render(); return $pdf->output();
    }
}
