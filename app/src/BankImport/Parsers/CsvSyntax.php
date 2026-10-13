<?php
declare(strict_types=1);
namespace Vereinskiste\BankImport\Parsers;

use Vereinskiste\Ledger\ValidationError;

/** fgetcsv tolerates incomplete quotes; financial input must reject them. */
final class CsvSyntax
{
    public static function validate(string $record, string $delimiter): void
    {
        $record = rtrim(preg_replace('/^\xEF\xBB\xBF/', '', $record), "\r\n");
        $state = 'start';
        for ($i = 0, $length = strlen($record); $i < $length; $i++) {
            $char = $record[$i];
            if ($state === 'quoted') {
                if ($char === '"') {
                    if ($i + 1 < $length && $record[$i + 1] === '"') { $i++; }
                    else { $state = 'closed'; }
                }
                continue;
            }
            if ($char === $delimiter) { $state = 'start'; continue; }
            if ($state === 'closed') {
                if ($char === ' ' || $char === "\t") { continue; }
                self::fail();
            }
            if ($state === 'start' && ($char === ' ' || $char === "\t")) { continue; }
            if ($state === 'start' && $char === '"') { $state = 'quoted'; continue; }
            if ($char === '"' || $char === "\n" || $char === "\r") { self::fail(); }
            $state = 'unquoted';
        }
        if ($state === 'quoted') { self::fail(); }
    }
    private static function fail(): never { throw new ValidationError(['receipt' => 'bank.file.syntax']); }
}
