<?php
declare(strict_types=1);

namespace Vereinskiste\Finance;

use Vereinskiste\Ledger\ValidationError;

/** Format-/Pruefsummenvalidierung; kein Nachweis, dass ein Bankkonto existiert. */
final class BankDetails
{
    public static function read(string $iban, string $bic): array
    {
        $iban = strtoupper(str_replace(' ', '', trim($iban)));
        $bic = strtoupper(trim($bic));
        $errors = [];
        if ($iban !== '' && !self::validIban($iban)) { $errors['iban'] = 'foundation.iban.invalid'; }
        if ($bic !== '' && !preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?\z/', $bic)) {
            $errors['bic'] = 'foundation.invalid';
        }
        if ($errors !== []) { throw new ValidationError($errors); }
        return ['iban' => $iban, 'bic' => $bic];
    }

    public static function validIban(string $iban): bool
    {
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}\z/', $iban)) { return false; }
        $lengths = ['DE' => 22, 'AT' => 20, 'CH' => 21, 'BE' => 16, 'NL' => 18,
            'FR' => 27, 'GB' => 22, 'IE' => 22, 'ES' => 24, 'IT' => 27, 'LU' => 20];
        if (isset($lengths[substr($iban, 0, 2)]) && strlen($iban) !== $lengths[substr($iban, 0, 2)]) { return false; }
        $remainder = 0;
        foreach (str_split(substr($iban, 4) . substr($iban, 0, 4)) as $char) {
            foreach (str_split(ctype_alpha($char) ? (string) (ord($char) - 55) : $char) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }
        return $remainder === 1;
    }
}
