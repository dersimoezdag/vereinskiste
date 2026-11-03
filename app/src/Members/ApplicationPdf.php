<?php
declare(strict_types=1);

namespace Vereinskiste\Members;

use Dompdf\Dompdf;
use Dompdf\Options;
use Vereinskiste\ClubDocuments\Content;

/** Mitgliedsantrag im Stil des Papierformulars, erzeugt aus den gespeicherten Snapshots. */
final class ApplicationPdf
{
    /**
     * @param array<string,mixed> $application aus ApplicationRepository::findApplication()
     * @param list<array<string,mixed>> $signatures aus ApplicationSignatures::forApplication()
     * @param array<int,string> $pngs Unterschrift-PNG je Signatur-ID
     */
    public static function render(array $application, array $signatures, array $pngs, string $club): string
    {
        $e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $data = $application['data'];
        $person = '';
        foreach ([
            'Name' => trim(($data['last_name'] ?? '') . ''), 'Vorname' => $data['first_name'] ?? '',
            'Anschrift' => trim(trim(($data['address'] ?? '') . ' ' . ($data['house_number'] ?? '')) . ', ' . trim(($data['zip'] ?? '') . ' ' . ($data['city'] ?? '')), ', '),
            'Geburtsdatum' => ($data['birth_date'] ?? '') !== '' ? (new \DateTimeImmutable($data['birth_date']))->format('d.m.Y') : '',
            'Telefonnummer' => $data['phone'] ?? '', 'E-Mail-Adresse' => $application['email'],
        ] as $label => $value) {
            $person .= '<tr><th>' . $e($label) . ':</th><td>' . $e($value) . '</td></tr>';
        }
        if (($data['iban'] ?? '') !== '') {
            $person .= '<tr><th>Kontoinhaber:</th><td>' . $e($data['account_holder'] ?? '') . '</td></tr><tr><th>IBAN:</th><td>' . $e($data['iban']) . '</td></tr>';
        }
        $sections = '';
        foreach ($signatures as $i => $s) {
            $snap = $s['snapshot'];
            $choice = match ($s['kind']) {
                'confirmation' => '<p>☒ Bestätigt</p>',
                'consent' => '<p>' . ($s['granted'] ? '☒' : '☐') . ' Ich willige ein<br>' . ($s['granted'] ? '☐' : '☒') . ' Ich willige nicht ein</p>',
                default => '',
            };
            $signature = isset($pngs[$s['id']])
                ? '<img class="sig" src="data:image/png;base64,' . base64_encode($pngs[$s['id']]) . '">'
                : '';
            $foot = $s['has_signature']
                ? '<table class="sign"><tr><td>Ort, Datum: ' . $e($s['signed_place']) . ', ' . $e((new \DateTimeImmutable($s['signed_at']))->format('d.m.Y')) . '</td><td>Unterschrift: ' . $signature . '</td></tr></table>'
                : '';
            // Wahl, Unterschrift und Pruefsumme bleiben beim Seitenumbruch zusammen.
            $sections .= '<h2>' . ($i + 2) . '. ' . $e($snap['title'] ?? '') . '</h2>' . Content::clean((string) ($snap['content'] ?? ''))
                . '<div class="keep">' . $choice . $foot . '<p class="hash">Version ' . $e($s['version']) . ' · SHA-256 ' . $e($s['snapshot_hash']) . '</p></div>';
        }
        $html = '<!doctype html><html lang="de"><meta charset="utf-8"><style>@page{margin:20mm}body{font-family:DejaVu Sans;font-size:10pt;line-height:1.4}'
            . 'h1{font-size:16pt;margin:0}h2{font-size:12pt;margin-top:18pt}table{width:100%;border-collapse:collapse}th{text-align:left;width:32%;font-weight:normal;padding:3pt 0}'
            . 'td{padding:3pt 0;border-bottom:0.5pt solid #999}.sign td{border:0;vertical-align:bottom;width:50%}.sig{height:40pt}.hash{color:#666;font-size:7pt}.keep{page-break-inside:avoid}</style><body>'
            . '<h1>Mitgliedsantrag</h1><p>' . $e($club) . '</p><h2>1. Angaben zur Person</h2><table>' . $person . '</table>' . $sections
            . '<p class="hash">Antrag #' . $e($application['id']) . ' · eingegangen ' . $e((new \DateTimeImmutable($application['created_at']))->format('d.m.Y H:i')) . '</p></body></html>';
        $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans',
            'chroot' => dirname(__DIR__, 2) . '/vendor/dompdf', 'allowedProtocols' => ['data://' => ['rules' => []]]]);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        return (string) $pdf->output();
    }
}
