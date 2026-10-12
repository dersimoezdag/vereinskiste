<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Vereinskiste\Http\Middleware\Session;
use Vereinskiste\Http\Session\NativeSessionStore;
use Vereinskiste\Http\Session\SessionStore;

final class NativeSessionStoreTest extends TestCase
{
    /** @var resource|null */
    private $server = null;
    private string $dir = '';
    private int $port = 0;

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $this->server = null;
        if ($this->dir !== '') {
            foreach (glob($this->dir . '/*') ?: [] as $f) {
                unlink($f);
            }
            rmdir($this->dir);
        }
    }

    public function test_cookie_params_are_hardened_and_secure_follows_flag(): void
    {
        $https = NativeSessionStore::cookieParams(true);
        $http = NativeSessionStore::cookieParams(false);

        $this->assertTrue($https['secure']);
        $this->assertFalse($http['secure']);
        foreach ([$https, $http] as $p) {
            $this->assertTrue($p['httponly']);
            $this->assertSame('Strict', $p['samesite']);
            $this->assertSame('/', $p['path']);
            $this->assertSame(0, $p['lifetime']);
        }
    }

    /** @return array<string,array{0:string,1:array<string,string>,2:bool}> */
    public static function httpsCases(): array
    {
        return [
            'https scheme' => ['https://example.org/', [], true],
            'plain http' => ['http://example.org/', [], false],
            'HTTPS server param on' => ['http://example.org/', ['HTTPS' => 'on'], true],
            'HTTPS server param off' => ['http://example.org/', ['HTTPS' => 'off'], false],
        ];
    }

    /** @param array<string,string> $server */
    #[DataProvider('httpsCases')]
    public function test_is_https_detection(string $uri, array $server, bool $expected): void
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', $uri, $server);

        $this->assertSame($expected, Session::isHttps($req));
    }

    public function test_is_https_ignores_untrusted_forwarded_proto_header(): void
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', 'http://example.org/')
            ->withHeader('X-Forwarded-Proto', 'https');

        $this->assertFalse(Session::isHttps($req));
    }

    public function test_session_middleware_configures_store_and_commits(): void
    {
        $store = new class implements SessionStore {
            /** @var list<string> */
            public array $calls = [];

            public function configure(bool $secure): void
            {
                $this->calls[] = 'configure:' . ($secure ? '1' : '0');
            }

            public function get(string $key): mixed
            {
                return null;
            }

            public function set(string $key, mixed $value): void
            {
            }

            public function remove(string $key): void
            {
            }

            public function regenerate(): void
            {
            }

            public function destroy(): void
            {
            }

            public function commit(): void
            {
                $this->calls[] = 'commit';
            }
        };
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse();
            }
        };

        (new Session($store))->process(
            (new ServerRequestFactory())->createServerRequest('GET', 'https://example.org/'),
            $handler
        );

        $this->assertSame(['configure:1', 'commit'], $store->calls);
    }

    private function startServer(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $this->dir = sys_get_temp_dir() . '/vk-session-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $router = dirname(__DIR__) . '/Support/session_router.php';
        $cmd = [PHP_BINARY, '-d', 'session.save_path=' . $this->dir, '-S', '127.0.0.1:' . $this->port, $router];
        $this->server = proc_open(
            $cmd,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        $this->assertIsResource($this->server);
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);
            if ($c !== false) {
                fclose($c);
                return;
            }
            usleep(50000);
        }
        $this->fail('PHP dev server did not start');
    }

    /**
     * @return array{body:array<string,mixed>,cookies:list<string>}
     */
    private function request(string $action, ?string $sessionId, bool $https): array
    {
        $headers = [];
        if ($sessionId !== null) {
            $headers[] = 'Cookie: vk_session=' . $sessionId;
        }
        if ($https) {
            $headers[] = 'X-Forwarded-Proto: https';
        }
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'ignore_errors' => true]]);
        $body = file_get_contents('http://127.0.0.1:' . $this->port . '/?a=' . $action, false, $ctx);
        $this->assertNotFalse($body);
        $cookies = [];
        foreach ($http_response_header ?? [] as $h) {
            if (stripos($h, 'Set-Cookie:') === 0) {
                $cookies[] = trim(substr($h, 11));
            }
        }
        return ['body' => json_decode($body, true) ?? [], 'cookies' => $cookies];
    }

    private function idFrom(string $cookie): string
    {
        $this->assertSame(1, preg_match('/^vk_session=([A-Za-z0-9,-]+);/', $cookie, $m), $cookie);
        return $m[1];
    }

    public function test_real_session_cookie_has_hardened_flags_and_secure_only_over_https(): void
    {
        $this->startServer();

        $https = $this->request('start', null, true)['cookies'];
        $http = $this->request('start', null, false)['cookies'];

        $this->assertCount(1, $https);
        $this->assertStringContainsString('HttpOnly', $https[0]);
        $this->assertStringContainsString('SameSite=Strict', $https[0]);
        $this->assertMatchesRegularExpression('/;\s*secure(;|$)/i', $https[0]);
        $this->assertCount(1, $http);
        $this->assertStringContainsString('HttpOnly', $http[0]);
        $this->assertStringContainsString('SameSite=Strict', $http[0]);
        $this->assertDoesNotMatchRegularExpression('/;\s*secure(;|$)/i', $http[0]);
    }

    public function test_login_rotates_session_id_and_logout_invalidates_it(): void
    {
        $this->startServer();

        $first = $this->idFrom($this->request('start', null, true)['cookies'][0]);

        $login = $this->request('login', $first, true);
        $second = $this->idFrom($login['cookies'][0]);
        $this->assertNotSame($first, $second);
        $this->assertSame(7, $login['body']['user_id']);

        // Neue ID traegt die Sitzung, die alte ist ungueltig.
        $this->assertSame(7, $this->request('whoami', $second, true)['body']['user_id']);
        $this->assertNull($this->request('whoami', $first, true)['body']['user_id']);

        $logout = $this->request('logout', $second, true);
        $this->assertNotEmpty($logout['cookies']);
        $expiring = implode("\n", $logout['cookies']);
        $this->assertStringContainsString('Max-Age=0', $expiring);
        $this->assertStringContainsString('HttpOnly', $expiring);
        $this->assertStringContainsString('SameSite=Strict', $expiring);

        $this->assertNull($this->request('whoami', $second, true)['body']['user_id']);
    }
}
