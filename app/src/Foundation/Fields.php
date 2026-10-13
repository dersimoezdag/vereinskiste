<?php
declare(strict_types=1);

namespace Vereinskiste\Foundation;

use Vereinskiste\Auth\UserRepository;
use Vereinskiste\Ledger\TextInput;
use Vereinskiste\Ledger\ValidationError;

/** Kleine gemeinsame Feldpruefung fuer Profil und Kontakte. */
final class Fields
{
    public static function read(array $input, array $limits, array $required = []): array
    {
        $values = $errors = [];
        foreach ($limits as $key => $max) {
            $value = $input[$key] ?? '';
            if (!is_string($value) || !UserRepository::isStorableText($value)) {
                $errors[$key] = 'validation.text.encoding';
                $value = '';
            }
            $value = trim($value);
            if (mb_strlen($value) > $max) { $errors[$key] = 'foundation.invalid'; }
            if (in_array($key, $required, true) && TextInput::isBlank($value)) {
                $errors[$key] = 'foundation.required';
            }
            if ($value !== '' && ($key === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false
                || str_ends_with($key, '_url') && !self::https($value))) {
                $errors[$key] = 'foundation.invalid';
            }
            $values[$key] = $value;
        }
        if ($errors !== []) { throw new ValidationError($errors); }
        return $values;
    }

    public static function https(string $value): bool
    {
        $url = parse_url($value);
        return filter_var($value, FILTER_VALIDATE_URL) !== false && is_array($url)
            && ($url['scheme'] ?? '') === 'https' && !empty($url['host'])
            && !isset($url['user']) && !isset($url['pass']);
    }

    public static function version(mixed $value): int
    {
        return (is_string($value) || is_int($value)) && preg_match('/^[1-9][0-9]{0,8}\z/', (string) $value)
            ? (int) $value : 0;
    }
}
