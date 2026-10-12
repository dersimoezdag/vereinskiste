<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Tests\TestCase;

final class UsersPagesTest extends TestCase
{
    use WebHarness;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->bootApp();
    }

    /** @return array<string,mixed>|null */
    private function row(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE lower(email) = lower(?)');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return string[] */
    private function rolesOf(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT role FROM user_roles WHERE user_id = ? ORDER BY role');
        $stmt->execute([$id]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function userCount(): int
    {
        return (int) $this->pdo->query('SELECT count(*) FROM users')->fetchColumn();
    }

    /** @return list<array{user_id:?int,detail:array<string,mixed>,raw:string}> */
    private function audit(string $action): array
    {
        $rows = $this->pdo->query(
            "SELECT user_id, detail::text AS detail FROM audit_log WHERE action = '" . $action . "' ORDER BY id"
        )->fetchAll();
        return array_map(static fn (array $r): array => [
            'user_id' => $r['user_id'] === null ? null : (int) $r['user_id'],
            'detail' => json_decode($r['detail'], true),
            'raw' => $r['detail'],
        ], $rows);
    }

    private function body(ResponseInterface $res): string
    {
        return (string) $res->getBody();
    }

    /** @return array<string,mixed> */
    private function newUser(array $override = []): array
    {
        return $override + [
            'name' => 'Erika Muster',
            'email' => 'erika@example.org',
            'password' => 'ein-langes-passwort',
            'roles' => ['kassenwart'],
        ];
    }

    /** Zweiter "Browser" mit eigener Sitzung gegen dieselbe Datenbank. */
    private function secondBrowser(string $email, string $password): \Slim\App
    {
        $app = Kernel::create(['pdo' => $this->pdo, 'session_store' => new ArraySessionStore()]);
        $html = (string) $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/login'))->getBody();
        preg_match('/name="_csrf" value="([^"]+)"/', $html, $m);
        $res = $app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', '/login')
                ->withParsedBody(['_csrf' => $m[1], 'email' => $email, 'password' => $password])
        );
        $this->assertSame(302, $res->getStatusCode(), 'zweiter Browser konnte sich nicht anmelden');
        return $app;
    }

    // --- Zugriff ---------------------------------------------------------------------------------

    public function test_user_pages_require_users_manage(): void
    {
        $other = $this->webUser('ziel@example.org', ['kassenwart']);
        $this->loginAs(['kassenwart']);

        foreach (['/users', '/users/new', "/users/$other/edit"] as $path) {
            $this->assertSame(403, $this->get($path)->getStatusCode(), $path);
        }
        foreach (['/users/new', "/users/$other/edit", "/users/$other/password"] as $path) {
            $this->assertSame(403, $this->postForm($path, ['name' => 'x'])->getStatusCode(), $path);
        }
    }

    public function test_user_pages_redirect_anonymous_to_login(): void
    {
        $res = $this->get('/users');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/login', $res->getHeaderLine('Location'));

        $res = $this->get('/account/password');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/login', $res->getHeaderLine('Location'));
    }

    public function test_post_without_csrf_token_is_rejected(): void
    {
        $this->loginAs(['vorstand']);

        $res = $this->app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', '/users/new')->withParsedBody($this->newUser())
        );

        $this->assertSame(403, $res->getStatusCode());
        $this->assertNull($this->row('erika@example.org'));
    }

    // --- Liste -----------------------------------------------------------------------------------

    public function test_list_shows_users_with_roles_and_active_flag(): void
    {
        $this->loginAs(['vorstand'], 'chef@example.org', 'Clara Chef');
        $this->webUser('kasse@example.org', ['kassenwart', 'schriftfuehrer'], 'Karl Kasse');
        $id = $this->webUser('weg@example.org', ['kassenpruefer'], 'Willi Weg');
        $this->pdo->exec("UPDATE users SET active = false WHERE id = $id");

        $res = $this->get('/users');

        $this->assertSame(200, $res->getStatusCode());
        $html = $this->body($res);
        foreach (['Clara Chef', 'chef@example.org', 'Karl Kasse', 'kasse@example.org', 'Willi Weg'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringContainsString('Kassenwart', $html);
        $this->assertStringContainsString('Schriftführer', $html);
        $this->assertStringContainsString('inaktiv', $html);
        $this->assertStringNotContainsString('password_hash', $html);
        $this->assertStringNotContainsString('$2y$', $html);
        $this->assertStringContainsString('href="/users/new"', $html);
    }

    // --- Anlegen ---------------------------------------------------------------------------------

    public function test_new_form_renders_with_csrf_and_role_checkboxes(): void
    {
        $this->loginAs(['vorstand']);

        $html = $this->body($this->get('/users/new'));

        $this->assertStringContainsString('name="_csrf"', $html);
        foreach (['vorstand', 'kassenwart', 'schriftfuehrer', 'kassenpruefer'] as $role) {
            $this->assertStringContainsString('value="' . $role . '"', $html);
        }
        $this->assertStringContainsString('name="password"', $html);
        $this->assertStringContainsString('type="password"', $html);
    }

    public function test_vorstand_creates_user_with_normalized_email_hashed_password_and_audit(): void
    {
        $actor = $this->loginAs(['vorstand']);

        $res = $this->postForm('/users/new', $this->newUser([
            'email' => '  Erika@Example.ORG ', 'roles' => ['kassenwart', 'kassenpruefer'],
        ]));

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/users', $res->getHeaderLine('Location'));
        $row = $this->row('erika@example.org');
        $this->assertNotNull($row);
        $this->assertSame('erika@example.org', $row['email']);
        $this->assertSame('Erika Muster', $row['name']);
        $this->assertTrue((bool) $row['active']);
        $this->assertTrue(password_verify('ein-langes-passwort', $row['password_hash']));
        $this->assertSame(['kassenpruefer', 'kassenwart'], $this->rolesOf((int) $row['id']));

        $audit = $this->audit('user_created');
        $this->assertCount(1, $audit);
        $this->assertSame($actor, $audit[0]['user_id']);
        $this->assertSame((int) $row['id'], $audit[0]['detail']['user_id']);
        $this->assertSame(['kassenpruefer', 'kassenwart'], $audit[0]['detail']['roles']);
        $this->assertStringNotContainsString('ein-langes-passwort', $audit[0]['raw']);
        $this->assertStringNotContainsString($row['password_hash'], $audit[0]['raw']);
        $this->assertStringNotContainsString('example.org', $audit[0]['raw']);

        // Der neue Benutzer kann sich mit dem vergebenen Passwort anmelden.
        $this->secondBrowser('erika@example.org', 'ein-langes-passwort');
    }

    public function test_password_shorter_than_10_is_rejected(): void
    {
        $this->loginAs(['vorstand']);

        $res = $this->postForm('/users/new', $this->newUser(['password' => '123456789']));

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('mindestens 10 Zeichen', $this->body($res));
        $this->assertNull($this->row('erika@example.org'));
        $this->assertSame([], $this->audit('user_created'));
    }

    public function test_password_length_is_counted_in_characters_not_bytes(): void
    {
        $this->loginAs(['vorstand']);

        // 9 Zeichen, 18 Byte: zu kurz.
        $res = $this->postForm('/users/new', $this->newUser(['password' => str_repeat('ü', 9)]));
        $this->assertSame(422, $res->getStatusCode());
        $this->assertNull($this->row('erika@example.org'));

        // 10 Zeichen: genau am Minimum.
        $res = $this->postForm('/users/new', $this->newUser(['password' => str_repeat('ü', 10)]));
        $this->assertSame(302, $res->getStatusCode());
        $this->assertNotNull($this->row('erika@example.org'));
    }

    public function test_password_above_bcrypt_byte_limit_is_rejected(): void
    {
        $this->loginAs(['vorstand']);

        $res = $this->postForm('/users/new', $this->newUser(['password' => str_repeat('a', 201)]));
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('höchstens 72 Bytes', $this->body($res));
        $this->assertNull($this->row('erika@example.org'));

        $res = $this->postForm('/users/new', $this->newUser(['password' => str_repeat('a', 72)]));
        $this->assertSame(302, $res->getStatusCode());
    }

    public function test_password_equal_to_email_or_name_is_rejected(): void
    {
        $this->loginAs(['vorstand']);

        $res = $this->postForm('/users/new', $this->newUser(['password' => 'ERIKA@example.org']));
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('nicht mit der E-Mail-Adresse', $this->body($res));

        $res = $this->postForm('/users/new', $this->newUser(['name' => 'Erika Muster', 'password' => 'erika muster']));
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('nicht mit dem Namen', $this->body($res));

        $this->assertNull($this->row('erika@example.org'));
    }

    public function test_password_is_not_echoed_back_on_validation_error(): void
    {
        $this->loginAs(['vorstand']);

        $html = $this->body($this->postForm('/users/new', $this->newUser(['password' => 'kurz', 'name' => ''])));

        $this->assertStringNotContainsString('value="kurz"', $html);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $this->loginAs(['vorstand']);
        $this->webUser('Erika@Example.org', ['kassenwart']);
        $before = $this->userCount();

        $res = $this->postForm('/users/new', $this->newUser(['email' => 'erika@example.ORG']));

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('bereits vergeben', $this->body($res));
        $this->assertSame($before, $this->userCount());
    }

    public function test_invalid_email_format_is_rejected(): void
    {
        $this->loginAs(['vorstand']);

        foreach (['kein-at-zeichen', 'a@', '@example.org', 'a b@example.org', 'a@example', ''] as $email) {
            $res = $this->postForm('/users/new', $this->newUser(['email' => $email]));
            $this->assertSame(422, $res->getStatusCode(), $email);
            $this->assertStringContainsString('gültige E-Mail-Adresse', $this->body($res), $email);
        }
        $this->assertSame(1, $this->userCount());
    }

    public function test_invalid_utf8_is_a_validation_error_not_a_server_error(): void
    {
        $this->loginAs(['vorstand']);
        $before = $this->userCount();

        foreach ([['name' => "Erika \xC3\x28"], ['email' => "e\xC3\x28@example.org"], ['name' => "Nul\0l"]] as $bad) {
            $res = $this->postForm('/users/new', $this->newUser($bad));
            $this->assertSame(422, $res->getStatusCode(), json_encode(array_map('bin2hex', $bad)));
        }
        $this->assertSame($before, $this->userCount());
    }

    public function test_name_is_required_and_limited(): void
    {
        $this->loginAs(['vorstand']);

        foreach (['', "   \u{00A0}\u{200B}", str_repeat('n', 101)] as $name) {
            $res = $this->postForm('/users/new', $this->newUser(['name' => $name]));
            $this->assertSame(422, $res->getStatusCode());
        }
        $this->assertNull($this->row('erika@example.org'));
    }

    public function test_at_least_one_known_role_is_required(): void
    {
        $this->loginAs(['vorstand']);

        foreach ([[], ['hacker'], ['kassenwart', 'admin'], 'vorstand'] as $roles) {
            $res = $this->postForm('/users/new', $this->newUser(['roles' => $roles]));
            $this->assertSame(422, $res->getStatusCode(), json_encode($roles));
        }
        $this->assertNull($this->row('erika@example.org'));
    }

    public function test_validation_error_keeps_the_other_input(): void
    {
        $this->loginAs(['vorstand']);

        $html = $this->body($this->postForm('/users/new', $this->newUser(['password' => 'kurz', 'roles' => ['kassenpruefer']])));

        $this->assertStringContainsString('value="Erika Muster"', $html);
        $this->assertStringContainsString('value="erika@example.org"', $html);
        $this->assertMatchesRegularExpression('/value="kassenpruefer"[^>]*checked/', $html);
    }

    // --- Bearbeiten ------------------------------------------------------------------------------

    public function test_edit_form_shows_current_values(): void
    {
        $this->loginAs(['vorstand']);
        $id = $this->webUser('karl@example.org', ['kassenwart'], 'Karl Kasse');

        $res = $this->get("/users/$id/edit");

        $this->assertSame(200, $res->getStatusCode());
        $html = $this->body($res);
        $this->assertStringContainsString('value="Karl Kasse"', $html);
        $this->assertStringContainsString('value="karl@example.org"', $html);
        $this->assertMatchesRegularExpression('/value="kassenwart"[^>]*checked/', $html);
        $this->assertStringContainsString('action="/users/' . $id . '/password"', $html);
    }

    public function test_edit_unknown_user_is_404(): void
    {
        $this->loginAs(['vorstand']);

        $this->assertSame(404, $this->get('/users/99999/edit')->getStatusCode());
        $this->assertSame(404, $this->postForm('/users/99999/edit', ['name' => 'x'])->getStatusCode());
        $this->assertSame(404, $this->postForm('/users/99999/password', ['password' => 'ein-langes-passwort'])->getStatusCode());
    }

    public function test_role_change_is_written_to_audit_log(): void
    {
        $actor = $this->loginAs(['vorstand']);
        $id = $this->webUser('karl@example.org', ['kassenwart', 'schriftfuehrer'], 'Karl Kasse');

        $res = $this->postForm("/users/$id/edit", [
            'name' => 'Karl Kasse', 'email' => 'karl@example.org', 'active' => '1',
            'roles' => ['kassenwart', 'kassenpruefer'],
        ]);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame(['kassenpruefer', 'kassenwart'], $this->rolesOf($id));
        $audit = $this->audit('user_updated');
        $this->assertCount(1, $audit);
        $this->assertSame($actor, $audit[0]['user_id']);
        $detail = $audit[0]['detail'];
        $this->assertSame($id, $detail['user_id']);
        $this->assertSame(['roles'], $detail['fields']);
        $this->assertSame(['kassenpruefer'], $detail['roles_added']);
        $this->assertSame(['schriftfuehrer'], $detail['roles_removed']);
        $this->assertStringNotContainsString('example.org', $audit[0]['raw']);
        $this->assertSame([], $this->audit('user_deactivated'));
    }

    public function test_name_and_email_change_lists_field_names_without_values(): void
    {
        $this->loginAs(['vorstand']);
        $id = $this->webUser('karl@example.org', ['kassenwart'], 'Karl Kasse');

        $res = $this->postForm("/users/$id/edit", [
            'name' => 'Karl Kassenwart', 'email' => 'Karl.K@Example.org', 'active' => '1', 'roles' => ['kassenwart'],
        ]);

        $this->assertSame(302, $res->getStatusCode());
        $row = $this->row('karl.k@example.org');
        $this->assertNotNull($row);
        $this->assertSame('karl.k@example.org', $row['email']);
        $this->assertSame('Karl Kassenwart', $row['name']);
        $audit = $this->audit('user_updated');
        $this->assertSame(['name', 'email'], $audit[0]['detail']['fields']);
        $this->assertSame([], $audit[0]['detail']['roles_added']);
        $this->assertStringNotContainsString('Karl', $audit[0]['raw']);
        $this->assertStringNotContainsString('example.org', $audit[0]['raw']);
    }

    public function test_unchanged_edit_writes_no_audit_entry(): void
    {
        $this->loginAs(['vorstand']);
        $id = $this->webUser('karl@example.org', ['kassenwart'], 'Karl Kasse');

        $res = $this->postForm("/users/$id/edit", [
            'name' => 'Karl Kasse', 'email' => 'karl@example.org', 'active' => '1', 'roles' => ['kassenwart'],
        ]);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame([], $this->audit('user_updated'));
    }

    public function test_deactivation_and_activation_are_audited_and_take_effect_immediately(): void
    {
        $this->loginAs(['vorstand'], 'chef@example.org', 'Clara Chef');
        $id = $this->webUser('karl@example.org', ['kassenwart'], 'Karl Kasse');
        $karl = $this->secondBrowser('karl@example.org', 'richtig-richtig');
        $this->assertSame(
            200,
            $karl->handle((new ServerRequestFactory())->createServerRequest('GET', '/bookings'))->getStatusCode()
        );

        $res = $this->postForm("/users/$id/edit", [
            'name' => 'Karl Kasse', 'email' => 'karl@example.org', 'roles' => ['kassenwart'],
        ]);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertFalse((bool) $this->row('karl@example.org')['active']);
        $audit = $this->audit('user_deactivated');
        $this->assertCount(1, $audit);
        $this->assertSame($id, $audit[0]['detail']['user_id']);
        $after = $karl->handle((new ServerRequestFactory())->createServerRequest('GET', '/bookings'));
        $this->assertSame(302, $after->getStatusCode());
        $this->assertSame('/login', $after->getHeaderLine('Location'));

        $res = $this->postForm("/users/$id/edit", [
            'name' => 'Karl Kasse', 'email' => 'karl@example.org', 'active' => '1', 'roles' => ['kassenwart'],
        ]);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertTrue((bool) $this->row('karl@example.org')['active']);
        $this->assertCount(1, $this->audit('user_activated'));
    }

    public function test_duplicate_email_on_edit_is_rejected_but_own_email_is_fine(): void
    {
        $this->loginAs(['vorstand']);
        $this->webUser('anna@example.org', ['kassenwart'], 'Anna');
        $id = $this->webUser('karl@example.org', ['kassenwart'], 'Karl Kasse');

        $res = $this->postForm("/users/$id/edit", [
            'name' => 'Karl Kasse', 'email' => 'ANNA@example.org', 'active' => '1', 'roles' => ['kassenwart'],
        ]);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('bereits vergeben', $this->body($res));
        $this->assertNotNull($this->row('karl@example.org'));

        $res = $this->postForm("/users/$id/edit", [
            'name' => 'Karl K.', 'email' => 'Karl@Example.org', 'active' => '1', 'roles' => ['kassenwart'],
        ]);
        $this->assertSame(302, $res->getStatusCode());
    }

    public function test_edit_rejects_unknown_role_and_empty_roles(): void
    {
        $this->loginAs(['vorstand']);
        $id = $this->webUser('karl@example.org', ['kassenwart'], 'Karl Kasse');

        foreach ([[], ['root']] as $roles) {
            $res = $this->postForm("/users/$id/edit", [
                'name' => 'Karl Kasse', 'email' => 'karl@example.org', 'active' => '1', 'roles' => $roles,
            ]);
            $this->assertSame(422, $res->getStatusCode());
        }
        $this->assertSame(['kassenwart'], $this->rolesOf($id));
    }

    public function test_last_vorstand_cannot_be_deactivated_or_demoted(): void
    {
        $me = $this->loginAs(['vorstand'], 'chef@example.org', 'Clara Chef');

        // Entziehen der Rolle (auch bei sich selbst)
        $res = $this->postForm("/users/$me/edit", [
            'name' => 'Clara Chef', 'email' => 'chef@example.org', 'active' => '1', 'roles' => ['kassenwart'],
        ]);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Vorstand', $this->body($res));
        $this->assertSame(['vorstand'], $this->rolesOf($me));

        // Deaktivieren
        $res = $this->postForm("/users/$me/edit", [
            'name' => 'Clara Chef', 'email' => 'chef@example.org', 'roles' => ['vorstand'],
        ]);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertTrue((bool) $this->row('chef@example.org')['active']);
        $this->assertSame([], $this->audit('user_updated'));
        $this->assertSame([], $this->audit('user_deactivated'));
    }

    public function test_vorstand_role_can_be_given_up_when_another_active_vorstand_exists(): void
    {
        $me = $this->loginAs(['vorstand'], 'chef@example.org', 'Clara Chef');
        $this->webUser('vize@example.org', ['vorstand'], 'Viktor Vize');

        $res = $this->postForm("/users/$me/edit", [
            'name' => 'Clara Chef', 'email' => 'chef@example.org', 'active' => '1', 'roles' => ['kassenwart'],
        ]);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame(['kassenwart'], $this->rolesOf($me));
    }

    public function test_an_inactive_vorstand_does_not_count_as_last_vorstand_cover(): void
    {
        $me = $this->loginAs(['vorstand'], 'chef@example.org', 'Clara Chef');
        $other = $this->webUser('alt@example.org', ['vorstand'], 'Alt Vorstand');
        $this->pdo->exec("UPDATE users SET active = false WHERE id = $other");

        $res = $this->postForm("/users/$me/edit", [
            'name' => 'Clara Chef', 'email' => 'chef@example.org', 'active' => '1', 'roles' => ['kassenwart'],
        ]);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame(['vorstand'], $this->rolesOf($me));
    }

    public function test_deactivating_another_vorstand_is_allowed_but_then_the_remaining_one_is_protected(): void
    {
        $me = $this->loginAs(['vorstand'], 'chef@example.org', 'Clara Chef');
        $other = $this->webUser('vize@example.org', ['vorstand'], 'Viktor Vize');

        $res = $this->postForm("/users/$other/edit", [
            'name' => 'Viktor Vize', 'email' => 'vize@example.org', 'roles' => ['vorstand'],
        ]);
        $this->assertSame(302, $res->getStatusCode());

        $res = $this->postForm("/users/$me/edit", [
            'name' => 'Clara Chef', 'email' => 'chef@example.org', 'active' => '1', 'roles' => ['kassenwart'],
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function test_user_cannot_deactivate_themself(): void
    {
        $me = $this->loginAs(['vorstand'], 'chef@example.org', 'Clara Chef');
        $this->webUser('vize@example.org', ['vorstand'], 'Viktor Vize');

        $res = $this->postForm("/users/$me/edit", [
            'name' => 'Clara Chef', 'email' => 'chef@example.org', 'roles' => ['vorstand'],
        ]);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('nicht selbst deaktivieren', $this->body($res));
        $this->assertTrue((bool) $this->row('chef@example.org')['active']);
    }

    // --- Passwort (Vorstand setzt fremdes Passwort) ----------------------------------------------

    public function test_vorstand_sets_a_new_password_for_another_user(): void
    {
        $actor = $this->loginAs(['vorstand']);
        $id = $this->webUser('karl@example.org', ['kassenwart'], 'Karl Kasse');
        $oldHash = $this->row('karl@example.org')['password_hash'];

        $res = $this->postForm("/users/$id/password", ['password' => 'ganz-neues-passwort']);

        $this->assertSame(302, $res->getStatusCode());
        $hash = $this->row('karl@example.org')['password_hash'];
        $this->assertNotSame($oldHash, $hash);
        $this->assertTrue(password_verify('ganz-neues-passwort', $hash));
        $audit = $this->audit('user_password_reset');
        $this->assertCount(1, $audit);
        $this->assertSame($actor, $audit[0]['user_id']);
        $this->assertSame($id, $audit[0]['detail']['user_id']);
        $this->assertStringNotContainsString('ganz-neues-passwort', $audit[0]['raw']);
        $this->assertStringNotContainsString($hash, $audit[0]['raw']);
        $this->secondBrowser('karl@example.org', 'ganz-neues-passwort');
    }

    public function test_admin_route_cannot_be_used_to_reset_the_own_password(): void
    {
        $me = $this->loginAs(['vorstand'], 'chef@example.org', 'Clara Chef');
        $oldHash = $this->row('chef@example.org')['password_hash'];

        $res = $this->postForm("/users/$me/password", ['password' => 'ganz-neues-passwort']);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('/account/password', $this->body($res));
        $this->assertSame($oldHash, $this->row('chef@example.org')['password_hash']);
        $this->assertSame([], $this->audit('user_password_reset'));

        // Die Bearbeitungsseite bietet fuer das eigene Konto keinen Reset an, sondern verweist auf den eigenen Weg.
        $html = $this->body($this->get("/users/$me/edit"));
        $this->assertStringNotContainsString('action="/users/' . $me . '/password"', $html);
        $this->assertStringContainsString('href="/account/password"', $html);
    }

    public function test_audit_log_shows_german_labels_for_user_changes(): void
    {
        $this->loginAs(['vorstand']);
        $id = $this->webUser('karl@example.org', ['kassenwart'], 'Karl Kasse');
        $this->postForm("/users/$id/edit", [
            'name' => 'Karl Kasse', 'email' => 'karl@example.org', 'active' => '1', 'roles' => ['kassenwart', 'kassenpruefer'],
        ]);

        $html = $this->body($this->get('/audit-log'));

        $this->assertStringContainsString('Benutzer geändert', $html);
        $this->assertStringContainsString('Kassenprüfer', $html);
        $this->assertStringContainsString('Rollen', $html);
        $this->assertStringNotContainsString('kassenpruefer', $html);
    }

    public function test_admin_password_reset_enforces_the_password_rules(): void
    {
        $this->loginAs(['vorstand']);
        $id = $this->webUser('karl@example.org', ['kassenwart'], 'Karl Kasse');
        $oldHash = $this->row('karl@example.org')['password_hash'];

        foreach (['kurz', 'karl@example.org', 'Karl Kasse', str_repeat('x', 201)] as $password) {
            $res = $this->postForm("/users/$id/password", ['password' => $password]);
            $this->assertSame(422, $res->getStatusCode(), $password);
        }
        $res = $this->postForm("/users/$id/password", ['password' => "ung\xC3\x28ueltig-lang"]);
        $this->assertSame(422, $res->getStatusCode());

        $this->assertSame($oldHash, $this->row('karl@example.org')['password_hash']);
        $this->assertSame([], $this->audit('user_password_reset'));
    }

    // --- Eigenes Passwort ------------------------------------------------------------------------

    public function test_any_logged_in_user_can_open_the_own_password_form(): void
    {
        $this->loginAs(['kassenpruefer']);

        $res = $this->get('/account/password');

        $this->assertSame(200, $res->getStatusCode());
        $html = $this->body($res);
        $this->assertStringContainsString('name="_csrf"', $html);
        foreach (['current_password', 'new_password', 'confirm_password'] as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $html);
        }
    }

    public function test_own_password_change_succeeds_regenerates_session_and_audits(): void
    {
        $id = $this->loginAs(['kassenpruefer'], 'pruefer@example.org', 'Paul Prüfer');
        $token = $this->csrf();
        $before = $this->store->id();

        $res = $this->app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', '/account/password')->withParsedBody([
                '_csrf' => $token,
                'current_password' => 'richtig-richtig',
                'new_password' => 'brandneues-passwort',
                'confirm_password' => 'brandneues-passwort',
            ])
        );

        $this->assertSame(302, $res->getStatusCode());
        $this->assertNotSame($before, $this->store->id());
        $hash = $this->row('pruefer@example.org')['password_hash'];
        $this->assertTrue(password_verify('brandneues-passwort', $hash));
        $audit = $this->audit('password_changed');
        $this->assertCount(1, $audit);
        $this->assertSame($id, $audit[0]['user_id']);
        $this->assertStringNotContainsString('brandneues-passwort', $audit[0]['raw']);
        $this->assertStringNotContainsString($hash, $audit[0]['raw']);
        $this->assertStringNotContainsString('richtig-richtig', $audit[0]['raw']);
        // weiterhin angemeldet
        $this->assertSame(200, $this->get('/account/password')->getStatusCode());
        // neues Passwort gilt, altes nicht
        $this->secondBrowser('pruefer@example.org', 'brandneues-passwort');
    }

    public function test_own_password_change_with_wrong_current_password_is_rejected(): void
    {
        $this->loginAs(['kassenpruefer'], 'pruefer@example.org');
        $oldHash = $this->row('pruefer@example.org')['password_hash'];

        $res = $this->postForm('/account/password', [
            'current_password' => 'falsch-falsch-falsch',
            'new_password' => 'brandneues-passwort',
            'confirm_password' => 'brandneues-passwort',
        ]);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('aktuelle Passwort ist falsch', $this->body($res));
        $this->assertSame($oldHash, $this->row('pruefer@example.org')['password_hash']);
        $this->assertSame([], $this->audit('password_changed'));
    }

    public function test_own_password_change_checks_confirmation_and_rules(): void
    {
        $this->loginAs(['kassenpruefer'], 'pruefer@example.org', 'Paul Prüfer');
        $oldHash = $this->row('pruefer@example.org')['password_hash'];

        $cases = [
            'mismatch' => ['brandneues-passwort', 'anderes-passwort-xx', 'nicht überein'],
            'short' => ['kurz', 'kurz', 'mindestens 10 Zeichen'],
            'same as current' => ['richtig-richtig', 'richtig-richtig', 'muss sich vom aktuellen'],
            'same as email' => ['pruefer@example.org', 'pruefer@example.org', 'nicht mit der E-Mail-Adresse'],
        ];
        foreach ($cases as $label => [$new, $confirm, $message]) {
            $res = $this->postForm('/account/password', [
                'current_password' => 'richtig-richtig', 'new_password' => $new, 'confirm_password' => $confirm,
            ]);
            $this->assertSame(422, $res->getStatusCode(), $label);
            $this->assertStringContainsString($message, $this->body($res), $label);
        }
        $this->assertSame($oldHash, $this->row('pruefer@example.org')['password_hash']);
    }

    public function test_own_password_form_is_not_prefilled_with_passwords(): void
    {
        $this->loginAs(['kassenpruefer']);

        $html = $this->body($this->postForm('/account/password', [
            'current_password' => 'geheim-geheim-1', 'new_password' => 'x', 'confirm_password' => 'y',
        ]));

        $this->assertStringNotContainsString('geheim-geheim-1', $html);
    }

    // --- Anmeldung -------------------------------------------------------------------------------

    public function test_login_with_invalid_utf8_email_is_a_normal_failed_login(): void
    {
        $res = $this->app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', '/login')->withParsedBody([
                '_csrf' => $this->csrf(), 'email' => "a\xC3\x28@example.org", 'password' => 'egal-egal-egal',
            ])
        );

        $this->assertSame(401, $res->getStatusCode());
        $this->assertStringContainsString('E-Mail-Adresse oder Passwort ist falsch', $this->body($res));
    }

    public function test_login_with_nul_byte_in_email_is_a_normal_failed_login(): void
    {
        $res = $this->app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', '/login')->withParsedBody([
                '_csrf' => $this->csrf(), 'email' => "a\0@example.org", 'password' => 'egal-egal-egal',
            ])
        );

        $this->assertSame(401, $res->getStatusCode());
    }

    // --- Navigation ------------------------------------------------------------------------------

    public function test_nav_links_to_own_password_for_every_user(): void
    {
        $this->loginAs(['kassenpruefer']);

        $this->assertStringContainsString('href="/account/password"', $this->body($this->get('/')));
    }
}
