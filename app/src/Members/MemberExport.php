<?php
declare(strict_types=1);

namespace Vereinskiste\Members;

use PDO;
use Vereinskiste\Reports\CsvExporter;

final class MemberExport
{
    public function __construct(private PDO $pdo) {}

    /** UTF-8 CSV suitable for Excel; no user credentials or application/SEPA data. @return resource */
    public function csv(string $search = '', string $state = 'all')
    {
        $stream = fopen('php://temp/maxmemory:2097152', 'w+');
        if ($stream === false) { throw new \RuntimeException('Export nicht verfügbar.'); }
        try {
            fwrite($stream, CsvExporter::BOM);
            fputcsv($stream, ['Mitgliedsnummer', 'Name', 'Anrede', 'Titel', 'Vorname', 'Nachname',
                'Adresse', 'PLZ', 'Ort', 'Land', 'E-Mail', 'Telefon', 'Geburtsdatum', 'Notizen',
                'Aktuelle Mitgliedschaft'], ';', '"', '', "\r\n");
            foreach ((new MemberRepository($this->pdo))->forExport($search, $state) as $member) {
                $cells = [(string) $member['member_no'], $member['name']];
                foreach (array_keys(MemberRepository::FIELDS) as $field) {
                    $value = $member['data'][$field];
                    $cells[] = $field === 'birth_date' && $value !== ''
                        ? (new \DateTimeImmutable($value))->format('d.m.Y') : $value;
                }
                $cells[] = $member['current'] ? 'Ja' : 'Nein';
                fputcsv($stream, array_map(CsvExporter::safe(...), $cells), ';', '"', '', "\r\n");
            }
            rewind($stream);
            return $stream;
        } catch (\Throwable $e) {
            fclose($stream);
            throw $e;
        }
    }
}
