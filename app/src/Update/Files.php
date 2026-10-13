<?php
declare(strict_types=1);
namespace Vereinskiste\Update;

final class Files
{
    public static function json(string $path, array $data): void
    {
        $temp = $path . '.tmp-' . bin2hex(random_bytes(8));
        $bytes = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($temp, $bytes, LOCK_EX) !== strlen($bytes)) { @unlink($temp); throw new Failure('storage'); }
        chmod($temp, 0600);
        if (!rename($temp, $path)) { @unlink($temp); throw new Failure('storage'); }
    }
    public static function read(string $path): ?array
    {
        if (!is_file($path)) { return null; }
        if (is_link($path) || filesize($path) > 1048576) { throw new Failure('state'); }
        $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) { throw new Failure('state'); }
        return $data;
    }
    public static function mkdir(string $path): void
    {
        if (is_link($path)) { throw new Failure('storage'); }
        if (!is_dir($path) && !mkdir($path, 0700, true)) { throw new Failure('storage'); }
    }
}
