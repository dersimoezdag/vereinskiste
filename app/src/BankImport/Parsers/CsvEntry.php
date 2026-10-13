<?php
declare(strict_types=1);
namespace Vereinskiste\BankImport\Parsers;

use DateTimeImmutable;
use Vereinskiste\Finance\BankDetails;
use Vereinskiste\Ledger\DateInput;
use Vereinskiste\Ledger\Money;
use Vereinskiste\Ledger\InvalidAmount;

final class CsvEntry
{
    public function read(array $raw, array $settings, array $account): array
    {
        $data = []; $issues = [];
        foreach ($settings['mapping'] as $field => $column) { $data[$field] = $column === null ? '' : $raw[$column]; }
        foreach (['booking_date','value_date'] as $field) {
            $value = $data[$field];
            if ($value === '' && $field === 'value_date') { $data[$field] = null; continue; }
            $date = DateTimeImmutable::createFromFormat('!' . $settings['date_format'], $value);
            if (!$date || $date->format($settings['date_format']) !== $value || !DateInput::valid($date->format('Y-m-d'))) {
                $issues[] = 'bank.row.date'; $data[$field] = null;
            } else { $data[$field] = $date->format('Y-m-d'); }
        }
        if ($data['booking_date'] !== null && $data['booking_date'] < $account['opening_date']) { $issues[] = 'bank.row.opening'; }
        $amount = trim($data['amount']);
        if (str_starts_with($amount, '+')) { $amount = substr($amount, 1); }
        try {
            $regex = $settings['decimal_format'] === 'comma'
                ? '/^-?(?:\d+|\d{1,3}(?:\.\d{3})+)(?:,\d{1,2})?\z/'
                : '/^-?(?:\d+|\d{1,3}(?:,\d{3})+)(?:\.\d{1,2})?\z/';
            if (!preg_match($regex, $amount)) { throw new InvalidAmount('Format'); }
            $amount = $settings['decimal_format'] === 'comma' ? str_replace('.', '', $amount) : str_replace(',', '', $amount);
            $data['amount'] = Money::parseSigned($amount);
            if ($data['amount'] === '0.00') { $issues[] = 'bank.row.amount'; }
        } catch (InvalidAmount) { $issues[] = 'bank.row.amount'; $data['amount'] = null; }
        $data['currency'] = strtoupper($settings['mapping']['currency'] === null ? 'EUR' : $data['currency']);
        if ($data['currency'] !== 'EUR') { $issues[] = 'bank.row.currency'; }
        foreach (['iban','account_iban'] as $field) {
            $data[$field] = strtoupper(str_replace(' ', '', $data[$field]));
            if ($data[$field] !== '' && !BankDetails::validIban($data[$field])) { $issues[] = 'bank.row.iban'; }
        }
        if ($data['account_iban'] !== '' && ($account['iban'] === '' || $account['iban'] !== $data['account_iban'])) { $issues[] = 'bank.row.account'; }
        foreach (['counterparty' => 200,'purpose' => 2000,'bank_reference' => 200] as $field => $max) {
            if (mb_strlen($data[$field]) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $data[$field])) { $issues[] = 'bank.row.text'; }
        }
        $data['raw_reference'] = $data['bank_reference'];
        if (!$settings['reference_unique'] || preg_match('/^(NOT\s*PROVIDED|NONREF|N\/A|NONE|0|-)$/i', $data['bank_reference'])) { $data['bank_reference'] = ''; }
        $data['fingerprint'] = hash('sha256', json_encode(array_intersect_key($data,
            array_flip(['booking_date','amount','currency','purpose'])), JSON_THROW_ON_ERROR));
        return ['proposal' => $data, 'issues' => array_values(array_unique($issues))];
    }
}
