<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Receipts;

/** Erzeugt kleine Testdateien (ohne GD) in einem temporaeren Verzeichnis. */
trait ReceiptFiles
{
    private string $scratch = '';

    private function scratchDir(): string
    {
        if ($this->scratch === '') {
            $this->scratch = sys_get_temp_dir() . '/vk-receipts-' . bin2hex(random_bytes(6));
            mkdir($this->scratch, 0700, true);
        }
        return $this->scratch;
    }

    private function cleanScratch(): void
    {
        if ($this->scratch === '' || !is_dir($this->scratch)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->scratch, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->scratch);
        $this->scratch = '';
    }

    private function tmpFile(string $content, string $name = 'upload.bin'): string
    {
        $path = $this->scratchDir() . '/' . bin2hex(random_bytes(4)) . '-' . basename(str_replace('\\', '/', $name));
        file_put_contents($path, $content);
        return $path;
    }

    private function pdfBytes(string $extra = ''): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n" . $extra;
    }

    /** JPEG mit gueltigem Kopf (SOI, JFIF, SOF0), aber ohne Bilddaten: getimagesize liest nur die Abmessungen. */
    private function jpegBytes(int $w = 1, int $h = 1): string
    {
        return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
            . "\xFF\xC0\x00\x11\x08" . pack('nn', $h, $w) . "\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01"
            . "\xFF\xD9";
    }

    /** PNG mit IHDR und IEND, ohne IDAT. */
    private function pngBytes(int $w = 1, int $h = 1): string
    {
        $chunk = static fn (string $type, string $data): string
            => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)) . $chunk('IEND', '');
    }

    private function heicBytes(): string
    {
        return "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic" . str_repeat("\x00", 64);
    }
}
