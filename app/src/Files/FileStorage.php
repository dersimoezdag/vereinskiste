<?php
declare(strict_types=1);
namespace Vereinskiste\Files;

use RuntimeException;
use Throwable;
use Vereinskiste\Ledger\ValidationError;

final class FileStorage
{
    public const NAME_MAX = 200;
    public function __construct(private string $dir, private int $maxBytes = 10485760) {}
    public static function cleanName(string $name, string $ext): string
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        }
        $parts = preg_split('~[/\\\\]~', $name) ?: [''];
        $name = (string) end($parts);
        $name = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $name);
        $name = ltrim(trim($name), '.');
        $name = trim(mb_substr($name, 0, self::NAME_MAX));
        return $name === '' ? 'beleg.' . $ext : $name;
    }

    public function ingest(string $tmpPath, array $allowed = []): array
    {
        $size = @filesize($tmpPath);
        if ($size === false || !is_file($tmpPath)) {
            throw new RuntimeException('Hochgeladene Datei ist nicht lesbar.');
        }
        if ($size === 0) {
            throw new ValidationError(['receipt' => 'validation.receipt.empty']);
        }
        if ($size > $this->maxBytes) {
            throw new ValidationError(['receipt' => 'validation.receipt.too_large']);
        }

        if (!is_dir($this->dir) && !@mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
            throw new RuntimeException('Beleg-Verzeichnis kann nicht angelegt werden.');
        }
        $temp = $this->dir . '/.tmp-' . bin2hex(random_bytes(8));
        if (!@copy($tmpPath, $temp)) {
            @unlink($temp);
            throw new RuntimeException('Beleg konnte nicht gespeichert werden.');
        }

        $final = null;
        try {
            $meta = (new UploadValidator())->inspect($temp, in_array('application/xml', $allowed, true), in_array('text/csv', $allowed, true));
            if ($allowed !== [] && !in_array($meta['mime'], $allowed, true)) { throw new ValidationError(['receipt' => 'validation.receipt.type']); }
            $hash = hash_file('sha256', $temp);
            if ($hash === false) {
                throw new RuntimeException('Pruefsumme des Belegs konnte nicht berechnet werden.');
            }
            $storageName = bin2hex(random_bytes(16)) . '.' . $meta['ext'];
            $final = $this->dir . '/' . $storageName;
            if (!@rename($temp, $final)) {
                throw new RuntimeException('Beleg konnte nicht gespeichert werden.');
            }
            // Nur der Besitzer und die Gruppe duerfen lesen; auf Plattformen ohne Modi schlaegt das still fehl.
            @chmod($final, 0640);
            clearstatcache(true, $final);
            $size = filesize($final);
            if ($size === false) {
                throw new RuntimeException('Beleg konnte nicht gespeichert werden.');
            }
        } catch (Throwable $e) {
            @unlink($temp);
            if ($final !== null) { @unlink($final); }
            throw $e;
        }
        return ['storage_name' => $storageName, 'mime' => $meta['mime'], 'ext' => $meta['ext'], 'size' => $size, 'sha256' => $hash];
    }
}
