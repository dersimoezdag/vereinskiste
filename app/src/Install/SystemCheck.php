<?php
declare(strict_types=1);

namespace Vereinskiste\Install;

final class SystemCheck
{
    public function __construct(private string $appDir, private ?string $webRoot = null)
    {
    }

    /** @return list<array{name:string,ok:bool,message:string}> */
    public function run(): array
    {
        $checks = [['name' => 'PHP', 'ok' => version_compare(PHP_VERSION, '8.4.0', '>='), 'message' => PHP_VERSION]];
        foreach (['pdo_pgsql', 'mbstring', 'fileinfo', 'dom', 'gd', 'openssl'] as $extension) {
            $checks[] = ['name' => $extension, 'ok' => extension_loaded($extension),
                'message' => extension_loaded($extension) ? 'Verfügbar' : 'PHP-Erweiterung fehlt'];
        }
        foreach (['config', 'storage'] as $dir) {
            $path = $this->appDir . '/' . $dir;
            $checks[] = ['name' => $dir, 'ok' => is_dir($path) && is_writable($path),
                'message' => is_dir($path) && is_writable($path) ? 'Beschreibbar' : 'Verzeichnis fehlt oder ist nicht beschreibbar'];
        }
        $root = $this->webRoot ?? ($_SERVER['DOCUMENT_ROOT'] ?? '');
        if (is_string($root) && $root !== '') {
            $public = realpath($root);
            $private = realpath($this->appDir);
            $normalize = static fn (string $path): string => PHP_OS_FAMILY === 'Windows'
                ? strtolower(str_replace('\\', '/', $path)) : $path;
            $safe = $public !== false && $private !== false
                && !str_starts_with($normalize($private) . '/', rtrim($normalize($public), '/') . '/');
            $checks[] = ['name' => 'Webroot', 'ok' => $safe, 'message' => $safe
                ? 'Private Anwendung außerhalb des Webroots' : 'Nur public/ darf als Webroot verwendet werden'];
        }
        $limit = trim((string) ini_get('memory_limit'));
        $bytes = (int) $limit * match (strtolower(substr($limit, -1))) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
        };
        $checks[] = ['name' => 'memory_limit', 'ok' => $limit === '-1' || $bytes >= 64 * 1024 ** 2,
            'message' => $limit . ' (mindestens 64 MB)'];
        return $checks;
    }
}
