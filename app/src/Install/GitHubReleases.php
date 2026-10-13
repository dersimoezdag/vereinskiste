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

    /** @param Closure():array{status:int,body:string}|null $transport */
    public function __construct(private ?Closure $transport = null) {}

    /** @return array{status:string,version?:string,url?:string,download?:string} */
    public function check(string $installed): array
    {
        try {
            $response = $this->transport !== null ? ($this->transport)() : (new GitHubTransport())->fetch();
            if ($response['status'] === 404) { return ['status' => 'none']; }
            if ($response['status'] === 429 || ($response['status'] === 403 && ($response['rate_remaining'] ?? null) === 0)) {
                throw new UpdateCheckFailure('rate_limit');
            }
            if ($response['status'] !== 200) { return ['status' => 'error', 'reason' => 'http', 'http_status' => (int) $response['status']]; }
            if (strlen($response['body']) > 262144) { throw new UpdateCheckFailure('invalid_response'); }
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
        } catch (UpdateCheckFailure $e) {
            return ['status' => 'error', 'reason' => $e->reason];
        } catch (Throwable) {
            return ['status' => 'error'];
        }
    }
}
