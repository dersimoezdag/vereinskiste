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
use Vereinskiste\Tests\TestCase;

final class BookingsPagesTest extends TestCase
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

    /** @param array<string,string> $query */
    private function get(string $path, array $query = []): ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', $path)->withQueryParams($query);
        return $this->app->handle($req);
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
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, name, password_hash) VALUES (?, ?, ?) RETURNING id'
        );
        $stmt->execute(['wer@example.org', 'Test Person', password_hash('richtig-richtig', PASSWORD_DEFAULT)]);
        $id = (int) $stmt->fetchColumn();
        foreach ($roles as $role) {
            $this->pdo->prepare('INSERT INTO user_roles (user_id, role) VALUES (?, ?)')->execute([$id, $role]);
        }
        $res = $this->post('/login', [
            '_csrf' => $this->csrf(), 'email' => 'wer@example.org', 'password' => 'richtig-richtig',
        ]);
        $this->assertSame(302, $res->getStatusCode());
        return $id;
    }

    private function account(string $name = 'Barkasse', bool $active = true): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO accounts (name, type, opening_date, active) VALUES (?, 'cash', '2026-01-01', ?) RETURNING id"
        );
        $stmt->bindValue(1, $name);
        $stmt->bindValue(2, $active, PDO::PARAM_BOOL);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    private function category(string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO categories (name) VALUES (?) RETURNING id');
        $stmt->execute([$name]);
        return (int) $stmt->fetchColumn();
    }

    private function booking(int $account, int $user, string $date, string $direction, string $amount, string $description = 'Test', ?int $category = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO bookings (account_id, booking_date, amount, direction, category_id, description, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id'
        );
        $stmt->execute([$account, $date, $amount, $direction, $category, $description, $user]);
        return (int) $stmt->fetchColumn();
    }

    public function test_list_requires_login_and_read_permission(): void
    {
        $this->assertSame(302, $this->get('/bookings')->getStatusCode());
        $this->loginAs([]);
        $this->assertSame(403, $this->get('/bookings')->getStatusCode());
    }

    public function test_list_shows_bookings_with_direction_sign_and_changed_marker_only_with_history(): void
    {
        $uid = $this->loginAs(['kassenpruefer']);
        $account = $this->account();
        $in = $this->booking($account, $uid, '2026-03-01', 'in', '1234.50', 'Spende Muster');
        $out = $this->booking($account, $uid, '2026-03-02', 'out', '20.00', 'Porto');
        $this->pdo->prepare(
            "INSERT INTO booking_changes (booking_id, changed_by, changes, reason) VALUES (?, ?, '{}'::jsonb, 'Tippfehler')"
        )->execute([$out, $uid]);

        $res = $this->get('/bookings');
        $html = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('Spende Muster', $html);
        $this->assertStringContainsString('+1.234,50', $html);
        $this->assertStringContainsString("\u{2212}20,00", $html);
        $this->assertSame(1, substr_count($html, 'geändert'), 'Marker nur fuer die geaenderte Buchung');
        $this->assertStringContainsString('/bookings/' . $out . '#history', $html);
        $this->assertStringNotContainsString('/bookings/' . $in . '#history', $html);
        $this->assertStringContainsString('table-cards', $html);
        // Kennzeichen ist ein Touch-Ziel (>= 44 px, Regel in app.css)
        $this->assertMatchesRegularExpression('/<a class="[^"]*\btouch-target\b[^"]*" href="\/bookings\/' . $out . '#history"/', $html);
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/assets/app.css');
        $this->assertMatchesRegularExpression('/\.touch-target\s*\{[^}]*min-height:\s*44px/', $css);
    }

    public function test_search_with_invalid_utf8_is_ignored_not_500(): void
    {
        $uid = $this->loginAs(['kassenwart']);
        $this->booking($this->account(), $uid, '2026-03-01', 'in', '5.00', 'Sichtbar');

        $res = $this->get('/bookings', ['q' => "M\xC3\x28ller", 'from' => '0000-01-01']);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('Sichtbar', (string) $res->getBody());
    }

    public function test_list_applies_filters_and_ignores_garbage(): void
    {
        $uid = $this->loginAs(['kassenwart']);
        $account = $this->account();
        $cat = $this->category('Miete');
        $this->booking($account, $uid, '2026-01-10', 'out', '10.00', 'Alt', $cat);
        $this->booking($account, $uid, '2026-03-10', 'out', '10.00', 'Neu', $cat);

        $html = (string) $this->get('/bookings', ['from' => '2026-02-01', 'category_id' => (string) $cat])->getBody();
        $this->assertStringContainsString('Neu', $html);
        $this->assertStringNotContainsString('>Alt<', $html);

        $res = $this->get('/bookings', ['from' => 'kaputt', 'account_id' => 'x', 'page' => '-3', 'q' => ['array']]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('Alt', (string) $res->getBody());
    }

    public function test_list_paginates_and_keeps_filters_in_links(): void
    {
        $uid = $this->loginAs(['kassenwart']);
        $account = $this->account();
        for ($i = 1; $i <= 30; $i++) {
            $this->booking($account, $uid, '2026-03-10', 'in', '1.00', sprintf('Eintrag %02d', $i));
        }

        $page1 = (string) $this->get('/bookings', ['direction' => 'in'])->getBody();
        $page2 = (string) $this->get('/bookings', ['direction' => 'in', 'page' => '2'])->getBody();

        $this->assertStringContainsString('Eintrag 30', $page1);
        $this->assertStringNotContainsString('Eintrag 05', $page1);
        $this->assertStringContainsString('Eintrag 05', $page2);
        $this->assertStringNotContainsString('Eintrag 30', $page2);
        $this->assertMatchesRegularExpression('/href="[^"]*direction=in[^"]*page=2"/', $page1);
    }

    public function test_new_form_requires_write_permission_and_offers_only_active_accounts(): void
    {
        $this->loginAs(['kassenpruefer']);
        $this->assertSame(403, $this->get('/bookings/new')->getStatusCode());
        $this->assertSame(403, $this->post('/bookings/new', ['_csrf' => $this->csrf()])->getStatusCode());
    }

    public function test_new_form_lists_active_accounts_only(): void
    {
        $this->loginAs(['kassenwart']);
        $this->account('Aktivkasse');
        $this->account('Altkasse', false);

        $html = (string) $this->get('/bookings/new')->getBody();

        $this->assertStringContainsString('Aktivkasse', $html);
        $this->assertStringNotContainsString('Altkasse', $html);
        $this->assertStringContainsString('name="_csrf"', $html);
    }

    public function test_create_redirects_to_detail_with_flash(): void
    {
        $uid = $this->loginAs(['kassenwart']);
        $account = $this->account();

        $res = $this->post('/bookings/new', [
            '_csrf' => $this->csrf(), 'account_id' => (string) $account, 'booking_date' => '2026-03-05',
            'amount' => '1.234,5', 'direction' => 'out', 'category_id' => '', 'description' => 'Bürobedarf <b>',
            'counterparty' => '', 'receipt_no' => 'R-7',
        ]);

        $this->assertSame(302, $res->getStatusCode());
        $row = $this->pdo->query('SELECT id, amount, created_by, version FROM bookings')->fetch();
        $this->assertSame('/bookings/' . $row['id'], $res->getHeaderLine('Location'));
        $this->assertSame('1234.50', $row['amount']);
        $this->assertSame($uid, (int) $row['created_by']);

        $detail = $this->get($res->getHeaderLine('Location'));
        $html = (string) $detail->getBody();
        $this->assertSame(200, $detail->getStatusCode());
        $this->assertStringContainsString('Buchung wurde angelegt.', $html);
        $this->assertStringContainsString('Bürobedarf &lt;b&gt;', $html);
        $this->assertStringContainsString('1.234,50', $html);
        $this->assertStringContainsString('R-7', $html);
    }

    public function test_invalid_form_rerenders_with_422_and_keeps_input(): void
    {
        $this->loginAs(['kassenwart']);
        $account = $this->account();

        $res = $this->post('/bookings/new', [
            '_csrf' => $this->csrf(), 'account_id' => (string) $account, 'booking_date' => '2026-02-30',
            'amount' => '12,345', 'direction' => 'in', 'category_id' => '', 'description' => 'Behalten <me>',
            'counterparty' => 'Frau Beispiel', 'receipt_no' => '',
        ]);
        $html = (string) $res->getBody();

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('value="2026-02-30"', $html);
        $this->assertStringContainsString('value="12,345"', $html);
        $this->assertStringContainsString('Behalten &lt;me&gt;', $html);
        $this->assertStringContainsString('value="Frau Beispiel"', $html);
        $this->assertStringContainsString('Bitte geben Sie einen Betrag größer als 0', $html);
        $this->assertStringContainsString('Bitte geben Sie ein gültiges Datum an.', $html);
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM bookings')->fetchColumn());
    }

    public function test_detail_unknown_id_is_404_and_requires_read(): void
    {
        $this->assertSame(302, $this->get('/bookings/1')->getStatusCode());
        $this->loginAs(['schriftfuehrer']);
        $this->assertSame(404, $this->get('/bookings/999')->getStatusCode());
    }
}
