<?php
declare(strict_types=1);

namespace Vereinskiste\Contacts;

use PDO;
use Vereinskiste\Reports\CsvExporter;

final class ContactExport
{
    public function __construct(private PDO $pdo) {}

    /** @return resource */
    public function csv()
    {
        $stream = fopen('php://temp/maxmemory:2097152', 'w+');
        if ($stream === false) { throw new \RuntimeException('Export nicht verfügbar.'); }
        fputcsv($stream, ['Name', 'Art', 'Adresse', 'PLZ', 'Ort', 'Land', 'E-Mail', 'Telefon', 'Aktiv'], ';', '"', '');
        $lastId = 0;
        do {
            $stmt = $this->pdo->prepare('SELECT id,name,kind,data,active FROM finance_contacts WHERE id>? ORDER BY id LIMIT 500');
            $stmt->execute([$lastId]); $rows = $stmt->fetchAll();
            foreach ($rows as $row) {
                $data = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
                $cells = [$row['name'], $row['kind'] === 'person' ? 'Person' : 'Firma'];
                foreach (['address', 'zip', 'city', 'country', 'email', 'phone'] as $key) { $cells[] = $data[$key] ?? ''; }
                $cells[] = $row['active'] ? 'Ja' : 'Nein';
                fputcsv($stream, array_map(CsvExporter::safe(...), $cells), ';', '"', '');
                $lastId = (int) $row['id'];
            }
        } while (count($rows) === 500);
        rewind($stream);
        return $stream;
    }
}
