<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Support;

use PDO;
use Vereinskiste\Members\Declarations;

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

    /** Gueltige Antworten fuer alle aktiven Erklaerungen. @return array<string,mixed> */
    public static function declarations(PDO $pdo, string $consent = 'no'): array
    {
        $input = ['declaration' => [], 'signature' => [], 'signed_place' => []];
        foreach ((new Declarations($pdo))->active() as $d) {
            if ($d['kind'] === 'confirmation' && !$d['signature_required']) {
                $input['declaration'][(string) $d['id']] = '1';
            } elseif ($d['kind'] === 'consent') {
                $input['declaration'][(string) $d['id']] = $consent;
            }
            if ($d['signature_required']) {
                $input['signature'][(string) $d['id']] = self::signature();
                $input['signed_place'][(string) $d['id']] = 'Erlangen';
            }
        }
        return $input;
    }
}
