<?php
declare(strict_types=1);

namespace Vereinskiste\Auth;

use Vereinskiste\Ledger\TextInput;

/** Mindestlaenge in UTF-8-Zeichen; bcrypt-Obergrenze in Bytes. */
final class PasswordPolicy
{
    public const MIN_LENGTH = 10;
    public const MAX_BYTES = 72;

    /**
     * Das Passwort wird nie getrimmt. `$email` und `$name` sind die Werte des Benutzers, dem es gehoert.
     *
     * @return string|null Textschluessel des ersten Verstosses oder null, wenn das Passwort zulaessig ist
     */
    public static function check(string $password, string $email, string $name): ?string
    {
        if (!mb_check_encoding($password, 'UTF-8') || str_contains($password, "\0")) {
            return 'validation.password.encoding';
        }
        $length = mb_strlen($password, 'UTF-8');
        if ($length < self::MIN_LENGTH) {
            return 'validation.password.too_short';
        }
        // PASSWORD_DEFAULT ist bcrypt: Bytes nach Position 72 wuerden still abgeschnitten.
        if (strlen($password) > self::MAX_BYTES) {
            return 'validation.password.too_many_bytes';
        }
        if (TextInput::isBlank($password)) {
            return 'validation.password.blank';
        }
        $lower = mb_strtolower(trim($password), 'UTF-8');
        if ($email !== '' && $lower === mb_strtolower(trim($email), 'UTF-8')) {
            return 'validation.password.same_as_email';
        }
        if ($name !== '' && $lower === mb_strtolower(trim($name), 'UTF-8')) {
            return 'validation.password.same_as_name';
        }
        return null;
    }
}
