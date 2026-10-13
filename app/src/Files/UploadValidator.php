<?php
declare(strict_types=1);
namespace Vereinskiste\Files;

use Vereinskiste\Ledger\ValidationError;

final class UploadValidator
{
    public const MAX_PIXELS = 40000000;
    private const TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    private const HEIC_MIMES = ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'];
    private const HEIC_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs', 'mif1', 'msf1'];

    public function inspect(string $path, bool $allowXml = false, bool $allowCsv = false): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($mime === false) {
            throw new ValidationError(['receipt' => 'validation.receipt.type']);
        }
        // Text containing HTML/XML cells can be detected as HTML/XML by finfo.
        // The bank reader validates CSV structure separately; downloads are attachments.
        if ($allowCsv && (str_starts_with($mime, 'text/') || in_array($mime, ['application/csv','application/vnd.ms-excel'], true))) {
            return ['mime' => 'text/csv', 'ext' => 'csv'];
        }
        if ($allowXml && in_array($mime, ['application/xml', 'text/xml'], true)) {
            (new InvoiceXmlInput())->validate($path);
            return ['mime' => 'application/xml', 'ext' => 'xml'];
        }
        if (in_array($mime, self::HEIC_MIMES, true) || $this->hasHeicBrand($path)) {
            throw new ValidationError(['receipt' => 'validation.receipt.heic']);
        }
        if (!isset(self::TYPES[$mime])) {
            throw new ValidationError(['receipt' => 'validation.receipt.type']);
        }
        if ($mime !== 'application/pdf') {
            // getimagesize liest nur den Kopf der Datei, es dekodiert keine Bilddaten.
            $info = @getimagesize($path);
            $expected = $mime === 'image/png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
            if ($info === false || ($info[2] ?? null) !== $expected || $info[0] < 1 || $info[1] < 1) {
                throw new ValidationError(['receipt' => 'validation.receipt.type']);
            }
            if ($info[0] * $info[1] > self::MAX_PIXELS) {
                throw new ValidationError(['receipt' => 'validation.receipt.megapixels']);
            }
        }
        return ['mime' => $mime, 'ext' => self::TYPES[$mime]];
    }

    private function hasHeicBrand(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $head = (string) fread($fh, 12);
        fclose($fh);
        return strlen($head) === 12 && substr($head, 4, 4) === 'ftyp'
            && in_array(substr($head, 8, 4), self::HEIC_BRANDS, true);
    }

}
