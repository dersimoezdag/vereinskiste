<?php
declare(strict_types=1);
namespace Vereinskiste\BankImport\Parsers;

use Vereinskiste\Ledger\ValidationError;

final class CsvMapping
{
    public const FIELDS = ['booking_date','value_date','amount','currency','counterparty','iban','purpose','bank_reference','account_iban'];
    public static function read(array $input, int $columns): array
    {
        $mapping = []; $used = [];
        foreach (self::FIELDS as $field) {
            $value = $input[$field] ?? '';
            if ($value === '' && !in_array($field, ['booking_date','amount'], true)) { $mapping[$field] = null; continue; }
            if (!is_string($value) || !ctype_digit($value) || (int) $value >= $columns || isset($used[(int) $value])) {
                throw new ValidationError(['mapping' => 'bank.mapping.invalid']);
            }
            $mapping[$field] = (int) $value; $used[(int) $value] = true;
        }
        $date = $input['date_format'] ?? ''; $decimal = $input['decimal_format'] ?? '';
        if (!in_array($date, ['Y-m-d','d.m.Y','d/m/Y','m/d/Y'], true)
            || !in_array($decimal, ['comma','dot'], true) || ($input['account_checked'] ?? '') !== '1') {
            throw new ValidationError(['mapping' => 'bank.mapping.invalid']);
        }
        return ['mapping' => $mapping, 'date_format' => $date, 'decimal_format' => $decimal,
            'reference_unique' => ($input['reference_unique'] ?? '') === '1'];
    }
}
