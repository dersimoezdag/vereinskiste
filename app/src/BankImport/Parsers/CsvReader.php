<?php
declare(strict_types=1);
namespace Vereinskiste\BankImport\Parsers;

use Generator;
use Vereinskiste\Ledger\ValidationError;

/** Bounded input, strict rectangular CSV; quoted multiline fields are supported. */
final class CsvReader
{
    public const MAX_BYTES = 10485760;
    public const MAX_ROWS = 10000;

    public function inspect(string $path, string $delimiter = '', string $encoding = ''): array
    {
        $size = filesize($path);
        if (!$size || $size > self::MAX_BYTES) { $this->fail('bank.file.size'); }
        // At most 10 MB, released before parsing; avoids guessing encoding per row.
        $bytes = file_get_contents($path);
        if ($bytes === false || str_contains($bytes, "\0")) { $this->fail('bank.file.encoding'); }
        $encoding = $encoding ?: (mb_check_encoding($bytes, 'UTF-8') ? 'UTF-8' : 'Windows-1252');
        unset($bytes);
        $fh = fopen($path, 'rb'); $first = fgets($fh, 65538); fclose($fh);
        if ($first === false) { $this->fail('bank.file.empty'); }
        if ($delimiter === '') {
            $counts = [];
            foreach ([';', ',', "\t"] as $candidate) { $counts[$candidate] = count(str_getcsv($first, $candidate, '"', '')); }
            arsort($counts); $delimiter = (string) array_key_first($counts);
        }
        if (!in_array($delimiter, [';', ',', "\t"], true) || !in_array($encoding, ['UTF-8', 'Windows-1252'], true)) {
            $this->fail('bank.mapping.invalid');
        }
        $settings = ['delimiter' => $delimiter, 'encoding' => $encoding];
        $header = []; $preview = []; $total = 0;
        foreach ($this->rows($path, $settings) as $number => $row) {
            if ($number === 0) { $header = $row; continue; }
            $total++;
            if (count($preview) < 10) { $preview[] = $row; }
        }
        if ($total === 0) { $this->fail('bank.file.empty'); }
        return $settings + ['headers' => $header, 'preview' => $preview, 'total_rows' => $total];
    }

    public function rows(string $path, array $settings): Generator
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) { $this->fail('bank.file.missing'); }
        $columns = null; $number = 0;
        try {
            while (!feof($fh)) {
                $start = ftell($fh);
                $row = fgetcsv($fh, 65537, $settings['delimiter'], '"', '');
                if ($row === false) { break; }
                if ($row === [null]) { continue; }
                $end = ftell($fh);
                if ($end - $start > 65536 || count($row) > 64 || $number > self::MAX_ROWS) { $this->fail('bank.file.limit'); }
                fseek($fh, $start); $record = fread($fh, $end - $start); fseek($fh, $end);
                CsvSyntax::validate($record, $settings['delimiter']);
                foreach ($row as &$value) {
                    if (strlen($value) > 8192) { $this->fail('bank.file.limit'); }
                    if ($settings['encoding'] === 'Windows-1252') { $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252'); }
                    if (!mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) { $this->fail('bank.file.encoding'); }
                    $value = trim($value);
                }
                unset($value);
                if ($number === 0) { $row[0] = preg_replace('/^\x{FEFF}/u', '', $row[0]); $columns = count($row); }
                if (count($row) !== $columns || $columns < 2) { $this->fail('bank.file.columns'); }
                yield $number++ => $row;
            }
        } finally { fclose($fh); }
    }

    private function fail(string $key): never { throw new ValidationError(['receipt' => $key]); }
}
