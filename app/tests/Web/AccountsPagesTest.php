<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Ledger\AccountRepository;
use Vereinskiste\Tests\TestCase;

final class AccountsPagesTest extends TestCase
{
    private PDO $pdo;
    private App $app;
    private ArraySessionStore $store;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->store = new ArraySessionStore();
        $this->app = Kernel::create(['pdo' => $this->pdo, 'session_store' => $this->store]);
    }

    /** @param string[] $roles */
    private function user(string $email, array $roles): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, name, password_hash) VALUES (?, ?, ?) RETURNING id'
        );
        $stmt->execute([$email, 'Test Person', password_hash('richtig-richtig', PASSWORD_DEFAULT)]);
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

    private function csrf(): string
    {
        $html = (string) $this->get('/login')->getBody();
        if ($html === '') {
            $html = (string) $this->get('/')->getBody();
        }
        $this->assertSame(1, preg_match('/name="_csrf" value="([^"]+)"/', $html, $m), 'csrf field missing');
        return $m[1];
    }

    /** @param string[] $roles */
    private function loginAs(array $roles): int
    {
        $id = $this->user('wer@example.org', $roles);
        $res = $this->post('/login', [
            '_csrf' => $this->csrf(), 'email' => 'wer@example.org', 'password' => 'richtig-richtig',
        ]);
        $this->assertSame(302, $res->getStatusCode());
        return $id;
    }

    /** @param array<string,string> $body */
    private function postForm(string $path, array $body): ResponseInterface
    {
        return $this->post($path, ['_csrf' => $this->csrf()] + $body);
    }

    private function account(string $name, string $opening, string $openingDate = '2026-01-01'): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO accounts (name, type, opening_balance, opening_date) VALUES (?, 'cash', ?, ?) RETURNING id"
        );
        $stmt->execute([$name, $opening, $openingDate]);
        return (int) $stmt->fetchColumn();
    }

    private function booking(
        int $accountId,
        string $date,
        string $direction,
        string $amount,
        int $userId,
        string $status = 'active',
        string $kind = 'regular'
    ): void {
        $this->pdo->prepare(
            'INSERT INTO bookings (account_id, booking_date, amount, direction, description, kind, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$accountId, $date, $amount, $direction, 'Test', $kind, $status, $userId]);
    }

    public function test_balance_includes_opening_balance_and_ignores_cancelled(): void
    {
        $uid = $this->user('a@example.org', ['kassenwart']);
        $account = $this->account('Barkasse', '100.00');
        $this->booking($account, '2026-02-01', 'in', '50.25', $uid);
        $this->booking($account, '2026-02-02', 'out', '20.10', $uid);
        $this->booking($account, '2026-02-03', 'in', '999.00', $uid, 'cancelled');
        $this->booking($account, '2026-02-04', 'out', '5.00', $uid, 'active', 'transfer');

        $repo = new AccountRepository($this->pdo);

        $this->assertSame('125.15', $repo->balance($account));
    }

    public function test_balance_of_account_without_bookings_is_opening_balance(): void
    {
        $account = $this->account('Girokonto', '1234.50');

        $this->assertSame('1234.50', (new AccountRepository($this->pdo))->balance($account));
    }

    public function test_balance_as_of_is_inclusive_and_ignores_bookings_before_opening_date(): void
    {
        $uid = $this->user('a@example.org', ['kassenwart']);
        $account = $this->account('Barkasse', '100.00', '2026-03-01');
        $this->booking($account, '2026-02-28', 'in', '1000.00', $uid); // vor Eroeffnung: zaehlt nicht
        $this->booking($account, '2026-03-01', 'in', '10.00', $uid);
        $this->booking($account, '2026-03-15', 'out', '4.00', $uid);
        $this->booking($account, '2026-04-01', 'in', '1.00', $uid);

        $repo = new AccountRepository($this->pdo);

        $this->assertSame('106.00', $repo->balance($account, '2026-03-15'));
        $this->assertSame('110.00', $repo->balance($account, '2026-03-14'));
        $this->assertSame('107.00', $repo->balance($account));
        $this->assertSame('100.00', $repo->balance($account, '2026-02-01'));
    }

    public function test_accounts_page_lists_balance_formatted_in_german(): void
    {
        $this->loginAs(['kassenpruefer']);
        $this->account('Barkasse', '1234.50');

        $res = $this->get('/accounts');

        $this->assertSame(200, $res->getStatusCode());
        $html = (string) $res->getBody();
        $this->assertStringContainsString('Barkasse', $html);
        $this->assertStringContainsString('1.234,50', $html);
        // Lesender Zugriff: keine Formulare zum Anlegen.
        $this->assertStringNotContainsString('action="/accounts"', $html);
    }

    public function test_pages_require_login_and_read_permission(): void
    {
        $this->assertSame(302, $this->get('/accounts')->getStatusCode());
        $this->assertSame(302, $this->get('/categories')->getStatusCode());

        $this->loginAs([]);

        $this->assertSame(403, $this->get('/accounts')->getStatusCode());
        $this->assertSame(403, $this->get('/categories')->getStatusCode());
    }

    public function test_writing_requires_settings_manage(): void
    {
        $this->loginAs(['kassenwart']); // darf lesen, nicht verwalten

        $res = $this->postForm('/categories', ['name' => 'Spenden']);
        $this->assertSame(403, $res->getStatusCode());
        $res = $this->postForm('/accounts', [
            'name' => 'Neu', 'type' => 'cash', 'opening_balance' => '0', 'opening_date' => '2026-01-01',
        ]);
        $this->assertSame(403, $res->getStatusCode());

        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM categories')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM accounts')->fetchColumn());
        $this->assertStringNotContainsString('action="/categories"', (string) $this->get('/categories')->getBody());
    }

    public function test_create_account_redirects_and_shows_flash(): void
    {
        $this->loginAs(['vorstand']);

        $res = $this->postForm('/accounts', [
            'name' => '  Vereinskonto ', 'type' => 'bank', 'opening_balance' => '1.234,50', 'opening_date' => '2026-01-01',
        ]);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/accounts', $res->getHeaderLine('Location'));
        $row = $this->pdo->query('SELECT name, type, opening_balance, opening_date, active FROM accounts')->fetch();
        $this->assertSame(
            ['name' => 'Vereinskonto', 'type' => 'bank', 'opening_balance' => '1234.50',
                'opening_date' => '2026-01-01', 'active' => true],
            $row
        );
        $html = (string) $this->get('/accounts')->getBody();
        $this->assertStringContainsString('Konto wurde angelegt.', $html);
        $this->assertStringNotContainsString('Konto wurde angelegt.', (string) $this->get('/accounts')->getBody());
    }

    public function test_invalid_account_rerenders_with_422_and_field_errors(): void
    {
        $this->loginAs(['vorstand']);

        $res = $this->postForm('/accounts', [
            'name' => '', 'type' => 'kasse', 'opening_balance' => 'abc', 'opening_date' => '31.02.2026',
        ]);

        $this->assertSame(422, $res->getStatusCode());
        $html = (string) $res->getBody();
        $this->assertStringContainsString('Bitte geben Sie einen Namen an.', $html);
        $this->assertStringContainsString('Bitte wählen Sie Bar oder Bank.', $html);
        $this->assertStringContainsString('Bitte geben Sie einen gültigen Betrag an.', $html);
        $this->assertStringContainsString('Bitte geben Sie ein gültiges Datum an.', $html);
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM accounts')->fetchColumn());
    }

    public function test_account_with_year_zero_or_invalid_utf8_name_is_422_not_500(): void
    {
        $this->loginAs(['vorstand']);

        $res = $this->postForm('/accounts', [
            'name' => "Kasse \xC3\x28", 'type' => 'cash', 'opening_balance' => '0', 'opening_date' => '0000-01-01',
        ]);

        $this->assertSame(422, $res->getStatusCode());
        $html = (string) $res->getBody();
        $this->assertStringContainsString('Bitte geben Sie ein gültiges Datum an.', $html);
        $this->assertStringContainsString('Der Text enthält ungültige Zeichen.', $html);
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM accounts')->fetchColumn());

        $res = $this->postForm('/categories', ['name' => "\xFF"]);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM categories')->fetchColumn());
    }

    public function test_rename_and_toggle_account(): void
    {
        $this->loginAs(['vorstand']);
        $id = $this->account('Alt', '0.00');

        $res = $this->postForm("/accounts/$id", ['name' => 'Neu']);
        $this->assertSame(302, $res->getStatusCode());
        $res = $this->postForm("/accounts/$id/toggle", []);
        $this->assertSame(302, $res->getStatusCode());

        $row = $this->pdo->query("SELECT name, active FROM accounts WHERE id = $id")->fetch();
        $this->assertSame(['name' => 'Neu', 'active' => false], $row);
    }

    public function test_unknown_account_id_is_404(): void
    {
        $this->loginAs(['vorstand']);

        $this->assertSame(404, $this->postForm('/accounts/9999', ['name' => 'X'])->getStatusCode());
        $this->assertSame(404, $this->postForm('/accounts/9999/toggle', [])->getStatusCode());
        $this->assertSame(404, $this->postForm('/categories/9999', ['name' => 'X'])->getStatusCode());
    }

    public function test_create_category_and_list_it(): void
    {
        $this->loginAs(['vorstand']);

        $res = $this->postForm('/categories', ['name' => ' Mitgliedsbeiträge ']);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/categories', $res->getHeaderLine('Location'));
        $html = (string) $this->get('/categories')->getBody();
        $this->assertStringContainsString('Mitgliedsbeiträge', $html);
        $this->assertStringContainsString('Kategorie wurde angelegt.', $html);
    }

    public function test_duplicate_category_name_shows_field_error(): void
    {
        $this->loginAs(['vorstand']);
        $this->postForm('/categories', ['name' => 'Spenden']);

        $res = $this->postForm('/categories', ['name' => 'spenden']);

        $this->assertSame(422, $res->getStatusCode());
        $html = (string) $res->getBody();
        $this->assertStringContainsString('Diese Kategorie gibt es bereits.', $html);
        $this->assertStringContainsString('is-invalid', $html);
        $this->assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM categories')->fetchColumn());
    }

    public function test_empty_category_name_is_rejected(): void
    {
        $this->loginAs(['vorstand']);

        $res = $this->postForm('/categories', ['name' => '   ']);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Bitte geben Sie einen Namen an.', (string) $res->getBody());
    }

    public function test_rename_category_to_existing_name_is_rejected_and_toggle_works(): void
    {
        $this->loginAs(['vorstand']);
        $this->postForm('/categories', ['name' => 'Spenden']);
        $this->postForm('/categories', ['name' => 'Material']);
        $id = (int) $this->pdo->query("SELECT id FROM categories WHERE name = 'Material'")->fetchColumn();

        $res = $this->postForm("/categories/$id", ['name' => 'Spenden']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Diese Kategorie gibt es bereits.', (string) $res->getBody());

        $res = $this->postForm("/categories/$id", ['name' => 'Büromaterial']);
        $this->assertSame(302, $res->getStatusCode());
        $res = $this->postForm("/categories/$id/toggle", []);
        $this->assertSame(302, $res->getStatusCode());

        $row = $this->pdo->query("SELECT name, active FROM categories WHERE id = $id")->fetch();
        $this->assertSame(['name' => 'Büromaterial', 'active' => false], $row);
    }

    public function test_write_forms_carry_csrf_and_post_without_token_is_403(): void
    {
        $this->loginAs(['vorstand']);

        $html = (string) $this->get('/categories')->getBody();
        $this->assertStringContainsString('action="/categories"', $html);
        $this->assertStringContainsString('name="_csrf"', $html);

        $res = $this->post('/categories', ['name' => 'Ohne Token']);
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_navigation_hides_entries_without_permission(): void
    {
        $this->loginAs(['kassenpruefer']);

        $html = (string) $this->get('/')->getBody();

        $this->assertStringContainsString('href="/accounts"', $html);
        $this->assertStringContainsString('href="/categories"', $html);
        $this->assertStringNotContainsString('href="/users"', $html);
    }

    public function test_navigation_without_any_role_has_no_ledger_entries(): void
    {
        $this->loginAs([]);

        $html = (string) $this->get('/')->getBody();

        $this->assertStringNotContainsString('href="/accounts"', $html);
        $this->assertStringNotContainsString('href="/categories"', $html);
    }

    public function test_navigation_shows_user_admin_for_vorstand(): void
    {
        $this->loginAs(['vorstand']);

        $html = (string) $this->get('/')->getBody();

        $this->assertStringContainsString('href="/users"', $html);
        $this->assertStringContainsString('href="/accounts"', $html);
    }

    public function test_layout_has_viewport_meta_and_mobile_nav_toggle(): void
    {
        $this->loginAs(['vorstand']);

        $html = (string) $this->get('/')->getBody();

        $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $html);
        $this->assertMatchesRegularExpression(
            '/<button[^>]*class="[^"]*navbar-toggler[^"]*"[^>]*data-bs-toggle="collapse"[^>]*data-bs-target="#sidebar-menu"/s',
            $html
        );
        $this->assertStringContainsString('id="sidebar-menu"', $html);
        $this->assertStringContainsString('/assets/vendor/tabler/tabler.min.css', $html);
        $this->assertStringContainsString('/assets/vendor/tabler/tabler.min.js', $html);
        $this->assertStringContainsString('/assets/app.css', $html);
        $this->assertStringContainsString('/assets/app.js', $html);
    }

    public function test_bundled_assets_exist_with_license(): void
    {
        $assets = dirname(__DIR__, 3) . '/public/assets';

        foreach (['app.css', 'app.js', 'vendor/tabler/tabler.min.css', 'vendor/tabler/tabler.min.js', 'vendor/tabler/LICENSE'] as $file) {
            $this->assertFileExists("$assets/$file");
        }
    }
}
