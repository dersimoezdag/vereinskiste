<?php
declare(strict_types=1);

namespace Vereinskiste\Text;

/**
 * Loest Textschluessel auf. Ein Schluessel darf einen Parameter tragen (`schluessel|wert`);
 * der Text enthaelt dann den Platzhalter `{0}`. ISO-Daten (JJJJ-MM-TT) erscheinen als TT.MM.JJJJ.
 */
final class Message
{
    /** @param array<string,string> $texts */
    public static function resolve(array $texts, string $key): string
    {
        $param = null;
        if (str_contains($key, '|')) {
            [$key, $param] = explode('|', $key, 2);
        }
        $text = $texts[$key] ?? $key;
        if ($param === null) {
            return $text;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $param, $m) === 1) {
            $param = "$m[3].$m[2].$m[1]";
        }
        return str_replace('{0}', $param, $text);
    }

    /** Fehlerwert "Textschluessel mit Parameter" fuer ValidationError. */
    public static function withParam(string $key, string $param): string
    {
        return $key . '|' . $param;
    }
}
