<?php
declare(strict_types=1);

namespace Vereinskiste\Install;

/** Besitznachweis wird nur ueber das private Hosting-Dateisystem bereitgestellt. */
final class SetupKey
{
    public function __construct(private string $appDir)
    {
    }

    public function authorize(string $key): ?string
    {
        $proof = hash('sha256', $key);
        return $this->accepts($proof) ? $proof : null;
    }

    public function accepts(string $proof): bool
    {
        $path = $this->appDir . '/config/setup.key';
        if (!is_file($path) || filesize($path) > 256) { return false; }
        $key = trim((string) file_get_contents($path));
        return preg_match('/^[A-Za-z0-9_-]{32,128}\z/', $key) === 1
            && hash_equals(hash('sha256', $key), $proof);
    }

    public function consume(): void
    {
        $path = $this->appDir . '/config/setup.key';
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException('Einrichtungsschlüssel konnte nicht entfernt werden.');
        }
    }
}
