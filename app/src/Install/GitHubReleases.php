<?php
declare(strict_types=1);

namespace Vereinskiste\Install;

use Closure;
use RuntimeException;
use Throwable;

/** Nur Metadaten aus der festen Updatequelle; kein fremder Code wird ausgefuehrt. */
final class GitHubReleases
{
    public const REPOSITORY = 'https://github.com/dersimoezdag/vereinskiste';
    private const API = 'https://api.github.com/repos/dersimoezdag/vereinskiste/releases/latest';

    /** @param Closure():array{status:int,body:string}|null $transport */
    public function __construct(private ?Closure $transport = null) {}

    /** @return array{status:string,version?:string,url?:string,download?:string} */
    public function check(string $installed): array
    {
        try {
            $response = $this->transport !== null ? ($this->transport)() : $this->fetch();
            if ($response['status'] === 404) { return ['status' => 'none']; }
            if ($response['status'] !== 200 || strlen($response['body']) > 262144) {
                throw new RuntimeException('Updatequelle nicht verfügbar.');
            }
            $release = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
            $tag = $release['tag_name'] ?? null;
            if (!is_string($tag) || preg_match('/^v?(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\z/', $tag) !== 1
                || ($release['draft'] ?? true) !== false || ($release['prerelease'] ?? true) !== false
                || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+\z/', $installed) !== 1) {
                throw new RuntimeException('Releaseinformationen ungültig.');
            }
            $version = ltrim($tag, 'v');
            $result = ['status' => version_compare($version, $installed, '>') ? 'available' : 'current',
                'version' => $version, 'url' => self::REPOSITORY . '/releases/tag/' . $tag];
            $name = 'vereinskiste-' . $version . '.zip';
            $expected = self::REPOSITORY . '/releases/download/' . $tag . '/' . $name;
            if (!is_array($release['assets'] ?? [])) { throw new RuntimeException('Releaseinformationen ungültig.'); }
            foreach ($release['assets'] ?? [] as $asset) {
                if (!is_array($asset)) { continue; }
                if (($asset['name'] ?? '') === $name && ($asset['state'] ?? '') === 'uploaded'
                    && ($asset['size'] ?? 0) > 0 && ($asset['browser_download_url'] ?? '') === $expected) {
                    $result['download'] = $expected;
                    break;
                }
            }
            return $result;
        } catch (Throwable) {
            return ['status' => 'error'];
        }
    }

    private function fetch(): array
    {
        $context = stream_context_create(['http' => ['timeout' => 4, 'follow_location' => 0,
            'ignore_errors' => true, 'header' => "Accept: application/vnd.github+json\r\n"
                . "User-Agent: Vereinskiste-Updatecheck\r\nX-GitHub-Api-Version: 2026-03-10\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $stream = @fopen(self::API, 'rb', false, $context);
        if ($stream === false) { throw new RuntimeException('Updatequelle nicht verfügbar.'); }
        try {
            $metadata = stream_get_meta_data($stream);
            $headers = $metadata['wrapper_data'] ?? [];
            $status = 0;
            foreach ($headers as $header) {
                if (preg_match('~^HTTP/\S+ ([0-9]{3})~', $header, $match)) { $status = (int) $match[1]; }
            }
            $body = stream_get_contents($stream, 262145);
            if ($body === false || stream_get_meta_data($stream)['timed_out']) {
                throw new RuntimeException('Updatequelle nicht verfügbar.');
            }
            return ['status' => $status, 'body' => $body];
        } finally { fclose($stream); }
    }
}
