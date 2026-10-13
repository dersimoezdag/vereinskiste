<?php
declare(strict_types=1);
namespace Vereinskiste\Text;

/** Shared German text source for services and browser-visible messages. */
final class Catalogue
{
    private static ?array $texts=null;
    public static function get(string $key,array $parameters=[]): string
    {
        self::$texts??=require __DIR__.'/de.php';
        return strtr(Message::resolve(self::$texts,$key),$parameters);
    }
}
