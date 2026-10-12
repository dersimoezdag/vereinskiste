<?php
declare(strict_types=1);

namespace Vereinskiste\Ledger;

/** Gemeinsame Pruefungen fuer Freitext-Eingaben. */
final class TextInput
{
    /**
     * Leer im Sinne eines Pflichtfelds: nur Leerraum, Trenn-, Steuer- und Formatzeichen
     * (auch geschuetztes Leerzeichen, U+3000, Null-Breite-Zeichen). PHP-`trim` erkennt nur ASCII.
     * Ungueltiges UTF-8 gilt nicht als leer; das meldet die Kodierungspruefung.
     */
    public static function isBlank(string $value): bool
    {
        return preg_match('/^[\s\p{Z}\p{Cc}\p{Cf}]*\z/u', $value) === 1;
    }
}
