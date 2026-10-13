<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Install;

use PHPUnit\Framework\TestCase;
use Vereinskiste\Install\GitHubReleases;

final class GitHubReleasesTest extends TestCase
{
    private function checker(array $release, int $status = 200): GitHubReleases
    {
        return new GitHubReleases(static fn (): array => ['status' => $status,
            'body' => json_encode($release, JSON_THROW_ON_ERROR)]);
    }

    public function test_versions_compare_numerically_and_only_full_package_links_are_accepted(): void
    {
        $url = GitHubReleases::REPOSITORY . '/releases/download/v0.10.0/vereinskiste-0.10.0.zip';
        $release = ['tag_name' => 'v0.10.0', 'draft' => false, 'prerelease' => false,
            'html_url' => 'https://evil.example.org/', 'assets' => [
                ['name' => 'vereinskiste-0.10.0.zip', 'state' => 'uploaded', 'size' => 100,
                    'browser_download_url' => 'https://evil.example.org/package.zip'],
                ['name' => 'vereinskiste-0.10.0.zip', 'state' => 'uploaded', 'size' => 100,
                    'browser_download_url' => $url],
            ]];
        $result = $this->checker($release)->check('0.9.0');
        $this->assertSame('available', $result['status']);
        $this->assertSame($url, $result['download']);
        $this->assertSame(GitHubReleases::REPOSITORY . '/releases/tag/v0.10.0', $result['url']);
        $this->assertSame('current', $this->checker($release)->check('0.10.0')['status']);
        $this->assertSame('current', $this->checker($release)->check('1.0.0')['status']);
        array_pop($release['assets']);
        $this->assertArrayNotHasKey('download', $this->checker($release)->check('0.9.0'));
    }

    public function test_missing_release_network_failures_and_invalid_metadata_have_distinct_states(): void
    {
        $this->assertSame(['status' => 'none'], $this->checker([], 404)->check('0.1.0'));
        foreach ([403, 429, 500, 302] as $status) {
            $result = $this->checker([], $status)->check('0.1.0');
            $this->assertSame('error', $result['status']);
            $this->assertSame($status === 429 ? 'rate_limit' : 'http', $result['reason']);
        }
        $failed = new GitHubReleases(static function (): array { throw new \RuntimeException('network'); });
        $this->assertSame(['status' => 'error'], $failed->check('0.1.0'));
        foreach (['v1.0.0-rc1', '../evil', 'not-a-version'] as $tag) {
            $this->assertSame(['status' => 'error'], $this->checker([
                'tag_name' => $tag, 'draft' => false, 'prerelease' => false,
            ])->check('0.1.0'));
        }
        $this->assertSame(['status' => 'error'], $this->checker([
            'tag_name' => '1.0.0', 'draft' => true, 'prerelease' => false,
        ])->check('0.1.0'));
    }
    public function test_rate_limits_and_transport_failures_have_safe_diagnostics(): void
    {
        $limited = new GitHubReleases(static fn (): array => ['status' => 403,'body' => 'private transport data','rate_remaining' => 0]);
        $this->assertSame(['status' => 'error','reason' => 'rate_limit'], $limited->check('0.1.2'));
        foreach (['transport_unavailable','timeout','tls','connection','invalid_response'] as $reason) {
            $checker = new GitHubReleases(static function () use ($reason): array {
                throw new \Vereinskiste\Install\UpdateCheckFailure($reason);
            });
            $this->assertSame(['status' => 'error','reason' => $reason], $checker->check('0.1.2'));
        }
        $large = new GitHubReleases(static fn (): array => ['status' => 200,'body' => str_repeat('x', 262145)]);
        $this->assertSame(['status' => 'error','reason' => 'invalid_response'], $large->check('0.1.2'));
    }
}
