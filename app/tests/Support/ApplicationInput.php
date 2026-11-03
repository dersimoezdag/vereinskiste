<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Support;

final class ApplicationInput
{
    /** PNG-Data-URL wie vom Unterschriftsfeld: transparenter Grund, dunkler Strich. */
    public static function signature(int $width = 600, int $height = 160, bool $ink = true): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        if ($ink) {
            imagesetthickness($image, 4);
            $pen = imagecolorallocate($image, 17, 24, 39);
            imageline($image, 20, (int) ($height * 0.7), $width - 20, (int) ($height * 0.3), $pen);
            imageline($image, 40, (int) ($height * 0.3), (int) ($width / 2), (int) ($height * 0.8), $pen);
        }
        ob_start();
        imagepng($image);
        return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    }
}
