<?php
declare(strict_types=1);

namespace Vereinskiste\Members;

use GdImage;
use Vereinskiste\Ledger\ValidationError;

/** Prueft eine gezeichnete Unterschrift (PNG-Data-URL) und kodiert sie ohne Metadaten neu. */
final class SignatureImage
{
    public const MAX_BYTES = 200000;
    public const MAX_WIDTH = 1200;
    public const MAX_HEIGHT = 400;
    private const PREFIX = 'data:image/png;base64,';
    private const MIN_INK_PIXELS = 30;

    public static function fromDataUrl(string $value): string
    {
        // Base64 ist 4/3 so lang wie die Rohdaten; laengere Eingaben gar nicht erst dekodieren.
        if (!str_starts_with($value, self::PREFIX) || strlen($value) > strlen(self::PREFIX) + intdiv(self::MAX_BYTES + 2, 3) * 4) {
            throw self::invalid();
        }
        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || $raw === '' || strlen($raw) > self::MAX_BYTES) {
            throw self::invalid();
        }
        $info = @getimagesizefromstring($raw);
        if ($info === false || $info[2] !== IMAGETYPE_PNG || $info[0] > self::MAX_WIDTH || $info[1] > self::MAX_HEIGHT) {
            throw self::invalid();
        }
        $image = @imagecreatefromstring($raw);
        if (!$image instanceof GdImage || !self::hasInk($image)) {
            throw self::invalid();
        }
        imagesavealpha($image, true);
        ob_start();
        imagepng($image, null, 9);
        return (string) ob_get_clean();
    }

    /** Mindestens einige deckende, dunkle Pixel; jede zweite Zeile/Spalte genuegt. */
    private static function hasInk(GdImage $image): bool
    {
        $count = 0;
        for ($y = 0, $h = imagesy($image); $y < $h; $y += 2) {
            for ($x = 0, $w = imagesx($image); $x < $w; $x += 2) {
                $c = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                if ($c['alpha'] < 64 && ($c['red'] + $c['green'] + $c['blue']) < 480 && ++$count >= self::MIN_INK_PIXELS) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function invalid(): ValidationError
    {
        return new ValidationError(['signature' => 'declarations.error.signature_invalid']);
    }
}
