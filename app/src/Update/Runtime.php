<?php
declare(strict_types=1);
namespace Vereinskiste\Update;

/** Stable protocol-1 dispatcher, loaded from the original installation before Composer. */
final class Runtime
{
    public function __construct(public readonly string $instance) {}
    public function directory(): string { return $this->instance . '/storage/updates'; }
    public function maintenance(): ?array { return Files::read($this->directory() . '/maintenance.json'); }
    public function active(): ?array { return Files::read($this->directory() . '/active.json'); }
    public function application(bool $probe = false): string
    {
        $maintenance = $this->maintenance();
        $pointer = !$probe && $maintenance !== null ? ($maintenance['previous'] ?? null) : $this->active();
        return $pointer === null ? $this->instance : $this->release($pointer['id'] ?? '') . '/app';
    }
    public function release(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{32}\z/', $id)) { throw new Failure('state'); }
        $path = $this->directory() . '/releases/' . $id;
        if (is_link($path) || !is_file($path . '/verified.json')) { throw new Failure('state'); }
        return $path;
    }
    public function asset(string $path): string
    {
        $maintenance = $this->maintenance(); $pointer = $maintenance !== null ? ($maintenance['previous'] ?? null) : $this->active();
        if ($pointer !== null) { return '/_release-assets/' . $pointer['id'] . '/' . $path; }
        // Ohne Release-Zeiger: Aenderungsdatum als Version anhaengen, damit Browser geaenderte Dateien nicht aus dem Cache bedienen.
        foreach ([dirname($this->instance) . '/public/assets/', $this->instance . '/public/assets/'] as $root) {
            $mtime = is_file($root . $path) ? filemtime($root . $path) : false;
            if ($mtime !== false) { return '/assets/' . $path . '?v=' . $mtime; }
        }
        return '/assets/' . $path;
    }
    public function pointer(?array $pointer): void
    {
        if ($pointer === null) { if (is_file($this->directory() . '/active.json')) { unlink($this->directory() . '/active.json'); } }
        else { $this->release($pointer['id']); Files::json($this->directory() . '/active.json', $pointer); }
        if (function_exists('opcache_reset')) { @opcache_reset(); }
    }
    public function serveAsset(string $uri): bool
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, '/_release-assets/')) { return false; }
        if (!preg_match('~^/_release-assets/([a-f0-9]{32})/([A-Za-z0-9_./-]+)\z~', $path, $match)
            || str_contains($match[2], '..') || str_contains($match[2], '//')) { http_response_code(404); return true; }
        $types = ['css'=>'text/css','js'=>'application/javascript','svg'=>'image/svg+xml','png'=>'image/png',
            'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','woff'=>'font/woff','woff2'=>'font/woff2'];
        $mime = $types[pathinfo($match[2], PATHINFO_EXTENSION)] ?? null;
        try { $root = $this->release($match[1]) . '/public/assets'; } catch (\Throwable) { http_response_code(404); return true; }
        $file = $root . '/' . $match[2]; $resolved = realpath($file); $resolvedRoot = realpath($root);
        if ($mime === null || $resolved === false || $resolvedRoot === false
            || !str_starts_with(str_replace('\\','/',$resolved), str_replace('\\','/',$resolvedRoot) . '/')
            || !is_file($file) || is_link($file)) { http_response_code(404); return true; }
        header('Content-Type: ' . $mime); header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=31536000, immutable');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
        readfile($file); return true;
    }
    public function probeAllowed(string $token): bool
    {
        $maintenance = $this->maintenance();
        return $maintenance !== null && isset($maintenance['probe']) && hash_equals($maintenance['probe'], $token);
    }
    public function recordProbe(string $token, string $version): void
    {
        if (!$this->probeAllowed($token)) { throw new Failure('state'); }
        $marker = $this->maintenance();
        Files::json($this->directory() . '/probe.json', ['id'=>$marker['id'],'version'=>$version]);
    }
}
