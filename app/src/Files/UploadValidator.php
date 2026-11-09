<?php
declare(strict_types=1);
namespace Vereinskiste\Files;

use DOMDocument;
use DOMElement;
use DOMXPath;
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
        $isSvg = $mime === 'image/svg+xml' || $mime === 'image/svg' || (($mime === 'text/xml' || $mime === 'text/plain') && self::looksLikeSvg($path));
        if ($isSvg) {
            self::validateSvg($path);
            return ['mime' => 'image/svg+xml', 'ext' => 'svg'];
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

    private static function looksLikeSvg(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $chunk = (string) fread($fh, 1024);
        fclose($fh);
        return (bool) preg_match('/<svg[\s>]/i', $chunk);
    }

    public static function validateSvg(string $path): void
    {
        $content = @file_get_contents($path);
        if ($content === false || trim($content) === '') {
            throw new ValidationError(['receipt' => 'validation.receipt.empty']);
        }
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($content, LIBXML_NONET | LIBXML_NOENT | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$loaded || $dom->documentElement === null || strtolower($dom->documentElement->localName) !== 'svg') {
            throw new ValidationError(['receipt' => 'validation.receipt.type']);
        }

        $xpath = new DOMXPath($dom);
        $forbiddenTags = ['script', 'foreignobject', 'iframe', 'embed', 'object', 'audio', 'video', 'meta', 'link'];
        foreach ($xpath->query('//*') as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            if (in_array(strtolower($node->localName), $forbiddenTags, true)) {
                throw new ValidationError(['receipt' => 'validation.receipt.type']);
            }
            if ($node->hasAttributes()) {
                foreach ($node->attributes as $attr) {
                    $name = strtolower($attr->name);
                    $val = trim($attr->value);
                    if (str_starts_with($name, 'on')) {
                        throw new ValidationError(['receipt' => 'validation.receipt.type']);
                    }
                    if (in_array($name, ['href', 'xlink:href', 'src', 'formaction'], true)) {
                        $normalized = strtolower(preg_replace('/[\x00-\x20\s]+/', '', $val));
                        if (str_starts_with($normalized, 'javascript:') || str_starts_with($normalized, 'vbscript:') || str_starts_with($normalized, 'data:text/')) {
                            throw new ValidationError(['receipt' => 'validation.receipt.type']);
                        }
                    }
                }
            }
        }
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
