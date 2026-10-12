<?php
declare(strict_types=1);

namespace Vereinskiste\Auth;

use Vereinskiste\Ledger\TextInput;

/** Regeln fuer neue Passwoerter (Laenge in UTF-8-Zeichen, nicht Bytes). */
final class PasswordPolicy
{
    public const MIN_LENGTH = 10;
    public const MAX_LENGTH = 200;

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
        if ($length > self::MAX_LENGTH) {
            return 'validation.password.too_long';
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
