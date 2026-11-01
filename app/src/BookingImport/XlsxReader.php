<?php
declare(strict_types=1);

namespace Vereinskiste\BookingImport;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Vereinskiste\Ledger\ValidationError;
use ZipArchive;

/**
 * Liest die Werte einer .xlsx-Datei ohne weitere Bibliothek. Formeln werden nie ausgewertet, es zaehlt
 * der von Excel gespeicherte Wert. Datumszellen werden zu JJJJ-MM-TT, Zahlen auf hoechstens zwei
 * Nachkommastellen gerundet (Excel speichert z. B. 2700.790000000001).
 */
final class XlsxReader
{
    public const MAX_BYTES = 10485760;
    public const MAX_PART_BYTES = 4194304;
    public const MAX_SHEETS = 30;
    public const MAX_ROWS = 2000;
    public const MAX_COLUMNS = 64;
    public const MAX_CELL = 1000;
    private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const RELS = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const DOC_RELS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    /** Eingebaute Excel-Zahlenformate mit Datum. */
    private const DATE_FORMATS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 30, 36, 45, 46, 47, 50, 57];

    /** @return list<array{name:string,rows:list<array{n:int,c:list<string>}>}> */
    public function read(string $path): array
    {
        if ((int) filesize($path) > self::MAX_BYTES) { $this->fail('booking_import.file_size'); }
        if (!class_exists(ZipArchive::class)) { $this->fail('booking_import.zip_missing'); }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) { $this->fail('booking_import.file_invalid'); }
        try {
            $workbook = $this->xml($zip, 'xl/workbook.xml');
            $relations = $this->relations($zip);
            $strings = $this->sharedStrings($zip);
            [$dateStyles] = [$this->dateStyles($zip)];
            $date1904 = (new DOMXPath($workbook))->evaluate('string(/*/*[local-name()="workbookPr"]/@date1904)');
            $sheets = [];
            foreach ($workbook->getElementsByTagNameNS(self::MAIN, 'sheet') as $sheet) {
                if (count($sheets) >= self::MAX_SHEETS) { $this->fail('booking_import.file_limit'); }
                $target = $relations[$sheet->getAttributeNS(self::DOC_RELS, 'id')] ?? null;
                if ($target === null) { continue; }
                $rows = $this->rows($this->xml($zip, $target), $strings, $dateStyles, in_array($date1904, ['1', 'true'], true));
                if ($rows !== []) {
                    $sheets[] = ['name' => mb_substr($sheet->getAttribute('name'), 0, 100), 'rows' => $rows];
                }
            }
        } finally {
            $zip->close();
        }
        if ($sheets === []) { $this->fail('booking_import.file_empty'); }
        return $sheets;
    }

    public static function columnName(int $index): string
    {
        $name = '';
        for ($index++; $index > 0; $index = (int) (($index - 1) / 26)) {
            $name = chr(65 + ($index - 1) % 26) . $name;
        }
        return $name;
    }

    private function rows(DOMDocument $dom, array $strings, array $dateStyles, bool $date1904): array
    {
        $rows = [];
        foreach ($dom->getElementsByTagNameNS(self::MAIN, 'row') as $row) {
            $cells = [];
            foreach ($row->getElementsByTagNameNS(self::MAIN, 'c') as $cell) {
                $column = $this->columnIndex($cell->getAttribute('r'));
                if ($column === null || $column >= self::MAX_COLUMNS) { continue; }
                $value = $this->cellValue($cell, $strings, $dateStyles, $date1904);
                if ($value !== '') { $cells[$column] = mb_substr($value, 0, self::MAX_CELL); }
            }
            if ($cells === []) { continue; }
            if (count($rows) >= self::MAX_ROWS) { $this->fail('booking_import.file_limit'); }
            $dense = array_fill(0, max(array_keys($cells)) + 1, '');
            $rows[] = ['n' => (int) $row->getAttribute('r'), 'c' => array_replace($dense, $cells)];
        }
        return $rows;
    }

    private function cellValue(DOMElement $cell, array $strings, array $dateStyles, bool $date1904): string
    {
        $type = $cell->getAttribute('t');
        if ($type === 'inlineStr') { return trim($cell->textContent); }
        $raw = '';
        foreach ($cell->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'v') { $raw = $child->textContent; }
        }
        if ($raw === '' || $type === 'e' || $type === 'b') { return ''; }
        if ($type === 's') { return trim($strings[(int) $raw] ?? ''); }
        if ($type === 'str') { return trim($raw); }
        if (!is_numeric($raw)) { return ''; }
        if (isset($dateStyles[(int) $cell->getAttribute('s')])) {
            $days = (int) floor((float) $raw);
            return gmdate('Y-m-d', ($days + ($date1904 ? 1462 : 0) - 25569) * 86400);
        }
        return preg_match('/^-?\d+(\.\d{1,2})?\z/', $raw) === 1 ? $raw : number_format((float) $raw, 2, '.', '');
    }

    private function columnIndex(string $reference): ?int
    {
        if (preg_match('/^([A-Z]{1,3})\d+\z/', $reference, $match) !== 1) { return null; }
        $index = 0;
        foreach (str_split($match[1]) as $letter) { $index = $index * 26 + ord($letter) - 64; }
        return $index - 1;
    }

    /** @return array<string,string> Beziehungs-ID => Pfad im Archiv */
    private function relations(ZipArchive $zip): array
    {
        $out = [];
        foreach ($this->xml($zip, 'xl/_rels/workbook.xml.rels')->getElementsByTagNameNS(self::RELS, 'Relationship') as $relation) {
            $target = $relation->getAttribute('Target');
            $out[$relation->getAttribute('Id')] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
        }
        return $out;
    }

    /** @return list<string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) { return []; }
        $out = [];
        foreach ($this->xml($zip, 'xl/sharedStrings.xml')->documentElement->childNodes as $item) {
            if (!$item instanceof DOMElement || $item->localName !== 'si') { continue; }
            $text = '';
            // Lautschrift (rPh) gehoert nicht zum angezeigten Wert.
            foreach ($item->getElementsByTagNameNS(self::MAIN, 't') as $part) {
                if ($part->parentNode->localName !== 'rPh') { $text .= $part->textContent; }
            }
            $out[] = $text;
        }
        return $out;
    }

    /** @return array<int,true> Index des Zellstils => Datumsformat */
    private function dateStyles(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/styles.xml') === false) { return []; }
        $dom = $this->xml($zip, 'xl/styles.xml');
        $custom = [];
        foreach ($dom->getElementsByTagNameNS(self::MAIN, 'numFmt') as $format) {
            // In Anfuehrungszeichen und eckigen Klammern stehen Texte und Farben, keine Datumsteile.
            $code = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $format->getAttribute('formatCode'));
            $custom[(int) $format->getAttribute('numFmtId')] = preg_match('/[dy]|m{1,5}(?![:s])/i', (string) $code) === 1;
        }
        $styles = [];
        $list = $dom->getElementsByTagNameNS(self::MAIN, 'cellXfs')->item(0);
        if ($list === null) { return []; }
        $index = 0;
        foreach ($list->childNodes as $style) {
            if (!$style instanceof DOMElement || $style->localName !== 'xf') { continue; }
            $id = (int) $style->getAttribute('numFmtId');
            if (in_array($id, self::DATE_FORMATS, true) || ($custom[$id] ?? false)) { $styles[$index] = true; }
            $index++;
        }
        return $styles;
    }

    private function xml(ZipArchive $zip, string $name): DOMDocument
    {
        $stat = $zip->statName($name);
        if ($stat === false) { $this->fail('booking_import.file_invalid'); }
        if ($stat['size'] > self::MAX_PART_BYTES) { $this->fail('booking_import.file_limit'); }
        $xml = $zip->getFromName($name);
        if (!is_string($xml) || stripos($xml, '<!DOCTYPE') !== false) { $this->fail('booking_import.file_invalid'); }
        $dom = new DOMDocument();
        if (!@$dom->loadXML($xml, LIBXML_NONET)) { $this->fail('booking_import.file_invalid'); }
        return $dom;
    }

    private function fail(string $key): never
    {
        throw new ValidationError(['receipt' => $key]);
    }
}
