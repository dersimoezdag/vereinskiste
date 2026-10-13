<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Vereinskiste\Auth\AuthService;
use Vereinskiste\Auth\UserRepository;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Middleware\Auth;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Tests\TestCase;

/**
 * Die App wird direkt mit PSR-7-Requests aufgerufen. Sitzungen laufen ueber
 * einen ArraySessionStore (eine Instanz = ein "Browser"), damit die Tests
 * ohne Cookies/PHP-Session deterministisch sind.
 */
final class AuthPagesTest extends TestCase
{
    private PDO $pdo;
    private App $app;
    private ArraySessionStore $store;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->store = new ArraySessionStore();
        $this->app = Kernel::create(['require_https' => false, 'security_dir' => $this->securityDir(), 'pdo' => $this->pdo, 'session_store' => $this->store]);
        $this->app->get('/_test/users', function ($req, ResponseInterface $res) {
            $res->getBody()->write('users-page');
            return $res;
        })->add(Auth::requires('users.manage'));
        $this->app->get('/_test/bookings', function ($req, ResponseInterface $res) {
            $res->getBody()->write('bookings-page');
            return $res;
        })->add(Auth::requires('bookings.read'));
        $this->app->post('/_test/post', function ($req, ResponseInterface $res) {
            $res->getBody()->write('posted');
            return $res;
        });
    }

    /** @param string[] $roles */
    private function user(string $email, string $password, array $roles, bool $active = true): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, name, password_hash, active) VALUES (?, ?, ?, ?::boolean) RETURNING id'
        );
        $stmt->execute([$email, 'Test Person', password_hash($password, PASSWORD_DEFAULT), $active ? 'true' : 'false']);
        $id = (int) $stmt->fetchColumn();
        foreach ($roles as $role) {
            $this->pdo->prepare('INSERT INTO user_roles (user_id, role) VALUES (?, ?)')->execute([$id, $role]);
        }
        return $id;
    }

    private function get(string $path): ResponseInterface
    {
        return $this->app->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
    }

    /** @param array<string,string> $body */
    private function post(string $path, array $body): ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', $path)->withParsedBody($body);
        return $this->app->handle($req);
    }

    private function csrfToken(): string
    {
        // Angemeldet leitet /login auf / um; dort liegt das Abmelde-Formular mit Token.
        $html = (string) $this->get('/login')->getBody();
        if ($html === '') {
            $html = (string) $this->get('/')->getBody();
        }
        $this->assertSame(1, preg_match('/name="_csrf" value="([^"]+)"/', $html, $m), 'csrf field missing');
        return $m[1];
    }

    private function login(string $email, string $password): ResponseInterface
    {
        return $this->post('/login', ['_csrf' => $this->csrfToken(), 'email' => $email, 'password' => $password]);
    }

    public function test_login_form_renders_with_csrf_field_and_german_texts(): void
    {
        $res = $this->get('/login');

        $this->assertSame(200, $res->getStatusCode());
        $html = (string) $res->getBody();
        $this->assertStringContainsString('name="_csrf"', $html);
        $this->assertStringContainsString('Anmelden', $html);
        $this->assertStringContainsString('name="email"', $html);
    }

    public function test_new_browser_and_spoofed_proxy_headers_do_not_reset_login_budget(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(401, $this->login('nobody@example.org', 'wrong')->getStatusCode());
        }
        $this->store = new ArraySessionStore();
        $this->app = Kernel::create(['require_https' => false, 'security_dir' => $this->securityDir(),
            'pdo' => $this->pdo, 'session_store' => $this->store]);
        $response = $this->app->handle((new ServerRequestFactory())->createServerRequest('POST', '/login')
            ->withHeader('X-Forwarded-For', '203.0.113.8')->withParsedBody([
                '_csrf' => $this->csrfToken(), 'email' => ' NOBODY@example.org ', 'password' => 'wrong',
            ]));
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('60', $response->getHeaderLine('Retry-After'));
        $this->assertSame(5, (int) $this->pdo->query("SELECT count(*) FROM audit_log WHERE action = 'login_failed'")->fetchColumn());
    }

    public function test_login_with_wrong_password_shows_error_and_401(): void
    {
        $this->user('kasse@example.org', 'richtig-richtig', ['kassenwart']);

        $res = $this->login('kasse@example.org', 'falsch');

        $this->assertSame(401, $res->getStatusCode());
        $this->assertStringContainsString('E-Mail-Adresse oder Passwort ist falsch', (string) $res->getBody());
    }

    public function test_unknown_user_gets_same_generic_error_as_wrong_password(): void
    {
        $res = $this->login('niemand@example.org', 'egal');

        $this->assertSame(401, $res->getStatusCode());
        $this->assertStringContainsString('E-Mail-Adresse oder Passwort ist falsch', (string) $res->getBody());
    }

    public function test_failed_login_is_audited_without_email(): void
    {
        $this->user('kasse@example.org', 'richtig-richtig', ['kassenwart']);

        $this->login('kasse@example.org', 'falsch');

        $rows = $this->pdo->query(
            "SELECT user_id, detail::text AS detail FROM audit_log WHERE action = 'login_failed'"
        )->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['user_id']);
        $this->assertStringNotContainsString('example.org', $rows[0]['detail']);
    }

    public function test_successful_login_redirects_audits_and_grants_access(): void
    {
        $id = $this->user('kasse@example.org', 'richtig-richtig', ['kassenwart']);

        $res = $this->login('kasse@example.org', 'richtig-richtig');

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/', $res->getHeaderLine('Location'));
        $audit = $this->pdo->query("SELECT user_id FROM audit_log WHERE action = 'login'")->fetchAll();
        $this->assertSame([['user_id' => $id]], $audit);
        $this->assertSame(200, $this->get('/_test/bookings')->getStatusCode());
    }

    public function test_login_ignores_case_and_surrounding_spaces_in_email(): void
    {
        $this->user('kasse@example.org', 'richtig-richtig', ['kassenwart']);

        $res = $this->login('  Kasse@Example.org ', 'richtig-richtig');

        $this->assertSame(302, $res->getStatusCode());
    }

    public function test_session_id_is_regenerated_on_login(): void
    {
        $this->user('kasse@example.org', 'richtig-richtig', ['kassenwart']);
        $token = $this->csrfToken();
        $before = $this->store->id();

        $this->post('/login', ['_csrf' => $token, 'email' => 'kasse@example.org', 'password' => 'richtig-richtig']);

        $this->assertNotSame($before, $this->store->id());
    }

    public function test_unknown_user_is_verified_exactly_once_against_the_constant_dummy_hash(): void
    {
        $this->assertSame('bcrypt', password_get_info(AuthService::DUMMY_HASH)['algoName']);
        $this->assertFalse(password_needs_rehash(AuthService::DUMMY_HASH, PASSWORD_DEFAULT));

        $calls = [];
        $verifier = function (string $pw, string $hash) use (&$calls): bool {
            $calls[] = $hash;
            return password_verify($pw, $hash);
        };
        $auth = new AuthService(new UserRepository($this->pdo), $this->store, $verifier,
            new \Vereinskiste\Auth\RateLimiter($this->securityDir()));

        $this->assertNull($auth->login('niemand@example.org', 'egal'));
        $this->assertSame([AuthService::DUMMY_HASH], $calls);

        $this->user('kasse@example.org', 'richtig-richtig', ['kassenwart']);
        $calls = [];
        $this->assertNull($auth->login('kasse@example.org', 'falsch'));
        $this->assertCount(1, $calls);
        $this->assertNotSame(AuthService::DUMMY_HASH, $calls[0]);
    }

    public function test_pre_upgrade_hashes_have_comparable_failed_login_time_and_still_rehash_on_success(): void
    {
        $id = $this->user('old@example.org', 'richtig-richtig', ['vorstand']);
        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash('richtig-richtig', PASSWORD_BCRYPT, ['cost' => 10]), $id]);
        $old = $unknown = [];
        for ($i = 0; $i < 3; $i++) {
            $start = microtime(true);
            $this->assertSame(401, $this->login('old@example.org', 'wrong')->getStatusCode());
            $old[] = microtime(true) - $start;
            $start = microtime(true);
            $this->assertSame(401, $this->login('unknown@example.org', 'wrong')->getStatusCode());
            $unknown[] = microtime(true) - $start;
        }
        sort($old); sort($unknown);
        $ratio = $old[1] / $unknown[1];
        $this->assertGreaterThan(0.4, $ratio, 'Alte Konten duerfen nicht viermal schneller erkennbar sein');
        $this->assertLessThan(2.5, $ratio);
        $this->assertSame(302, $this->login('old@example.org', 'richtig-richtig')->getStatusCode());
        $hash = $this->pdo->query('SELECT password_hash FROM users')->fetchColumn();
        $this->assertFalse(password_needs_rehash($hash, PASSWORD_DEFAULT));
        $this->assertTrue(password_verify('richtig-richtig', $hash));
    }

    public function test_unauthenticated_request_is_redirected_to_login(): void
    {
        $res = $this->get('/_test/bookings');

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/login', $res->getHeaderLine('Location'));
    }

    public function test_missing_permission_gives_403_page(): void
    {
        $this->user('pruefer@example.org', 'richtig-richtig', ['kassenpruefer']);
        $this->login('pruefer@example.org', 'richtig-richtig');

        $res = $this->get('/_test/users');

        $this->assertSame(403, $res->getStatusCode());
        $this->assertStringContainsString('Keine Berechtigung', (string) $res->getBody());
    }

    public function test_deactivated_user_session_is_rejected(): void
    {
        $id = $this->user('kasse@example.org', 'richtig-richtig', ['kassenwart']);
        $this->login('kasse@example.org', 'richtig-richtig');
        $this->assertSame(200, $this->get('/_test/bookings')->getStatusCode());

        $this->pdo->prepare('UPDATE users SET active = false WHERE id = ?')->execute([$id]);

        $res = $this->get('/_test/bookings');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/login', $res->getHeaderLine('Location'));
    }

    public function test_role_change_takes_effect_on_next_request(): void
    {
        $id = $this->user('kasse@example.org', 'richtig-richtig', ['kassenwart']);
        $this->login('kasse@example.org', 'richtig-richtig');
        $this->pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$id]);

        $this->assertSame(403, $this->get('/_test/bookings')->getStatusCode());
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        $this->user('weg@example.org', 'richtig-richtig', ['vorstand'], false);

        $res = $this->login('weg@example.org', 'richtig-richtig');

        $this->assertSame(401, $res->getStatusCode());
    }

    public function test_post_without_csrf_token_is_403(): void
    {
        $res = $this->post('/_test/post', ['x' => 'y']);

        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_post_with_wrong_csrf_token_is_403_and_with_valid_token_passes(): void
    {
        $this->assertSame(403, $this->post('/_test/post', ['_csrf' => 'falsch'])->getStatusCode());

        $this->assertSame(200, $this->post('/_test/post', ['_csrf' => $this->csrfToken()])->getStatusCode());
    }

    public function test_logout_destroys_session(): void
    {
        $this->user('kasse@example.org', 'richtig-richtig', ['kassenwart']);
        $this->login('kasse@example.org', 'richtig-richtig');

        $res = $this->post('/logout', ['_csrf' => $this->csrfToken()]);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/login', $res->getHeaderLine('Location'));
        $this->assertSame(302, $this->get('/_test/bookings')->getStatusCode());
    }

    public function test_home_page_uses_can_to_hide_content_without_permission(): void
    {
        $this->user('pruefer@example.org', 'richtig-richtig', ['kassenpruefer']);
        $this->login('pruefer@example.org', 'richtig-richtig');

        $html = (string) $this->get('/')->getBody();

        $this->assertStringContainsString('Test Person', $html);
        $this->assertStringContainsString('Abmelden', $html);
        $this->assertStringNotContainsString('Benutzer verwalten', $html);
    }

    public function test_home_page_shows_user_admin_link_for_vorstand(): void
    {
        $this->user('chef@example.org', 'richtig-richtig', ['vorstand']);
        $this->login('chef@example.org', 'richtig-richtig');

        $this->assertStringContainsString('href="/settings"', (string) $this->get('/')->getBody());
        $this->assertStringContainsString('href="/users"', (string) $this->get('/settings')->getBody());
    }
}
