<?php
declare(strict_types=1);
namespace Vereinskiste\Install;

/** Fixed HTTPS destination, verified TLS, no redirects and a bounded response. */
final class GitHubTransport
{
    private const API = 'https://api.github.com/repos/dersimoezdag/vereinskiste/releases/latest';
    private const MAX_BYTES = 262144;
    private const HEADERS = ['Accept: application/vnd.github+json', 'User-Agent: Vereinskiste-Updatecheck', 'X-GitHub-Api-Version: 2026-03-10'];

    public function fetch(): array
    {
        if (function_exists('curl_init')) { return $this->curl(); }
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) { throw new UpdateCheckFailure('transport_unavailable'); }
        return $this->stream();
    }
    private function curl(): array
    {
        $handle = curl_init(self::API); $body = ''; $remaining = null; $tooLarge = false;
        if ($handle === false) { throw new UpdateCheckFailure('connection'); }
        try {
            curl_setopt_array($handle, [CURLOPT_HTTPHEADER => self::HEADERS,
                CURLOPT_CONNECTTIMEOUT_MS => 2000, CURLOPT_TIMEOUT_MS => 4000, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => static function ($unused, string $chunk) use (&$body, &$tooLarge): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_BYTES) { $tooLarge = true; return 0; }
                    $body .= $chunk; return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION => static function ($unused, string $header) use (&$remaining): int {
                    if (preg_match('/^x-ratelimit-remaining:\s*(\d+)/i', $header, $match)) { $remaining = (int) $match[1]; }
                    return strlen($header);
                }]);
            if (curl_exec($handle) === false) {
                $errno = curl_errno($handle);
                $reason = $tooLarge ? 'invalid_response' : ($errno === CURLE_OPERATION_TIMEDOUT ? 'timeout'
                    : (in_array($errno, [35,51,58,60,77,83,90], true) ? 'tls' : 'connection'));
                throw new UpdateCheckFailure($reason);
            }
            return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $body, 'rate_remaining' => $remaining];
        } finally { unset($handle); }
    }
    private function stream(): array
    {
        $context = stream_context_create(['http' => ['timeout' => 4, 'follow_location' => 0,
            'ignore_errors' => true, 'header' => implode("\r\n", self::HEADERS) . "\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $stream = @fopen(self::API, 'rb', false, $context);
        if ($stream === false) { throw new UpdateCheckFailure('connection'); }
        try {
            $metadata = stream_get_meta_data($stream); $status = 0; $remaining = null;
            foreach ($metadata['wrapper_data'] ?? [] as $header) {
                if (preg_match('~^HTTP/\S+ ([0-9]{3})~', $header, $match)) { $status = (int) $match[1]; }
                if (preg_match('/^x-ratelimit-remaining:\s*(\d+)/i', $header, $match)) { $remaining = (int) $match[1]; }
            }
            $body = stream_get_contents($stream, self::MAX_BYTES + 1);
            if (stream_get_meta_data($stream)['timed_out']) { throw new UpdateCheckFailure('timeout'); }
            if ($body === false) { throw new UpdateCheckFailure('connection'); }
            if (strlen($body) > self::MAX_BYTES) { throw new UpdateCheckFailure('invalid_response'); }
            return ['status' => $status, 'body' => $body, 'rate_remaining' => $remaining];
        } finally { fclose($stream); }
    }
}
