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
use Vereinskiste\Ledger\BookingService;
use Vereinskiste\Ledger\TransferService;
use Vereinskiste\Tests\TestCase;

final class BookingChangesPagesTest extends TestCase
{
    private PDO $pdo;
    private App $app;
    private ArraySessionStore $store;
    private int $cash;
    private int $bank;
    private int $rent;
    private int $postage;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->store = new ArraySessionStore();
        $this->app = Kernel::create(['pdo' => $this->pdo, 'session_store' => $this->store]);
        $this->cash = (int) $this->pdo->query(
            "INSERT INTO accounts (name, type, opening_date) VALUES ('Barkasse', 'cash', '2026-01-01') RETURNING id"
        )->fetchColumn();
        $this->bank = (int) $this->pdo->query(
            "INSERT INTO accounts (name, type, opening_date) VALUES ('Girokonto', 'bank', '2026-01-01') RETURNING id"
        )->fetchColumn();
        $this->rent = (int) $this->pdo->query("INSERT INTO categories (name) VALUES ('Miete') RETURNING id")->fetchColumn();
        $this->postage = (int) $this->pdo->query("INSERT INTO categories (name) VALUES ('Porto') RETURNING id")->fetchColumn();
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
        $req = (new ServerRequestFactory())->createServerRequest('POST', $path)
            ->withParsedBody(['_csrf' => $this->csrf()] + $body);
        return $this->app->handle($req);
    }

    private function csrf(): string
    {
        $html = (string) $this->app->handle((new ServerRequestFactory())->createServerRequest('GET', '/login'))->getBody();
        if ($html === '') {
            $html = (string) $this->get('/')->getBody();
        }
        $this->assertSame(1, preg_match('/name="_csrf" value="([^"]+)"/', $html, $m), 'csrf field missing');
        return $m[1];
    }

    /** @param string[] $roles */
    private function loginAs(array $roles): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO users (email, name, password_hash) VALUES (?, ?, ?) RETURNING id');
        $stmt->execute(['wer@example.org', 'Test Person', password_hash('richtig-richtig', PASSWORD_DEFAULT)]);
        $id = (int) $stmt->fetchColumn();
        foreach ($roles as $role) {
            $this->pdo->prepare('INSERT INTO user_roles (user_id, role) VALUES (?, ?)')->execute([$id, $role]);
        }
        $res = $this->post('/login', ['email' => 'wer@example.org', 'password' => 'richtig-richtig']);
        $this->assertSame(302, $res->getStatusCode());
        return $id;
    }

    /**
     * @param array<string,string> $override
     * @return array<string,string>
     */
    private function form(array $override = []): array
    {
        return $override + [
            'account_id' => (string) $this->cash, 'booking_date' => '2026-03-05', 'amount' => '12,50',
            'direction' => 'out', 'category_id' => (string) $this->rent, 'description' => 'Raummiete März',
            'counterparty' => '', 'receipt_no' => '',
        ];
    }

    private function booking(int $userId): int
    {
        return (new BookingService($this->pdo))->create($this->form(), $userId)['id'];
    }

    private function changeCount(): int
    {
        return (int) $this->pdo->query('SELECT count(*) FROM booking_changes')->fetchColumn();
    }

    public function test_detail_page_shows_history_but_list_does_not(): void
    {
        $uid = $this->loginAs(['kassenpruefer']);
        $id = $this->booking($uid);
        (new BookingService($this->pdo))->update(
            $id,
            $this->form(['amount' => '15', 'category_id' => (string) $this->postage, 'account_id' => (string) $this->bank]),
            'Falsche <Kategorie> erwischt',
            1,
            $uid
        );

        $res = $this->get('/bookings/' . $id);
        $html = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('id="history"', $html);
        $this->assertStringContainsString('Falsche &lt;Kategorie&gt; erwischt', $html);
        $this->assertStringContainsString('Test Person', $html);
        $this->assertMatchesRegularExpression('/Barkasse\s*→\s*Girokonto/u', $html);
        $this->assertMatchesRegularExpression('/Miete\s*→\s*Porto/u', $html);
        $this->assertMatchesRegularExpression('/12,50\x{00A0}€\s*→\s*15,00\x{00A0}€/u', $html);
        // Nur Leserecht: keine Aenderungsaktionen
        $this->assertStringNotContainsString('/bookings/' . $id . '/edit', $html);
        $this->assertStringNotContainsString('/bookings/' . $id . '/cancel', $html);

        $list = (string) $this->get('/bookings')->getBody();
        $this->assertStringNotContainsString('Falsche', $list);
        $this->assertStringNotContainsString('id="history"', $list);
        $this->assertStringContainsString('/bookings/' . $id . '#history', $list);
    }

    public function test_detail_page_without_changes_has_empty_history_anchor(): void
    {
        $uid = $this->loginAs(['kassenwart']);
        $id = $this->booking($uid);

        $html = (string) $this->get('/bookings/' . $id)->getBody();

        $this->assertStringContainsString('id="history"', $html);
        $this->assertStringContainsString('Noch keine Änderungen.', $html);
        $this->assertStringContainsString('href="/bookings/' . $id . '/edit"', $html);
        $this->assertStringContainsString('action="/bookings/' . $id . '/cancel"', $html);
        $this->assertStringContainsString('href="/corrections/new?of=' . $id . '"', $html);
    }

    public function test_edit_form_carries_version_and_saves_change(): void
    {
        $uid = $this->loginAs(['kassenwart']);
        $id = $this->booking($uid);

        $form = (string) $this->get('/bookings/' . $id . '/edit')->getBody();
        $this->assertMatchesRegularExpression('/<input type="hidden" name="version" value="1">/', $form);
        $this->assertStringContainsString('value="12,50"', $form);
        $this->assertStringContainsString('Raummiete März', $form);
        $this->assertStringContainsString('name="reason"', $form);

        $res = $this->post('/bookings/' . $id . '/edit', $this->form(['description' => 'Raummiete April']) + [
            'version' => '1', 'reason' => 'Monat vertauscht',
        ]);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/bookings/' . $id, $res->getHeaderLine('Location'));

        $detail = (string) $this->get('/bookings/' . $id)->getBody();
        $this->assertStringContainsString('Buchung wurde geändert.', $detail);
        $this->assertStringContainsString('Monat vertauscht', $detail);
        $this->assertMatchesRegularExpression('/Raummiete März\s*→\s*Raummiete April/u', $detail);
        $this->assertSame(1, $this->changeCount());
    }

    public function test_edit_form_keeps_current_inactive_account_and_category_selected(): void
    {
        $uid = $this->loginAs(['kassenwart']);
        $id = $this->booking($uid);
        $this->pdo->exec("UPDATE accounts SET active = false WHERE id = {$this->cash}");
        $this->pdo->exec("UPDATE categories SET active = false WHERE id = {$this->rent}");

        $form = (string) $this->get('/bookings/' . $id . '/edit')->getBody();
        $this->assertStringContainsString('<option value="' . $this->cash . '" selected>Barkasse</option>', $form);
        $this->assertStringContainsString('<option value="' . $this->rent . '" selected>Miete</option>', $form);

        $res = $this->post('/bookings/' . $id . '/edit', $this->form(['description' => 'Tippfehler behoben']) + [
            'version' => '1', 'reason' => 'Tippfehler',
        ]);
        $this->assertSame(302, $res->getStatusCode());

        // Neue Buchungen bieten das inaktive Konto weiterhin nicht an.
        $this->assertStringNotContainsString('Barkasse', (string) $this->get('/bookings/new')->getBody());
    }

    public function test_edit_without_reason_rerenders_422_with_submitted_version_and_input(): void
    {
        $uid = $this->loginAs(['kassenwart']);
        $id = $this->booking($uid);

        $res = $this->post('/bookings/' . $id . '/edit', $this->form(['description' => 'Behalten <me>']) + [
            'version' => '1', 'reason' => '  ',
        ]);
        $html = (string) $res->getBody();

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Bitte geben Sie einen Grund an.', $html);
        $this->assertStringContainsString('Behalten &lt;me&gt;', $html);
        $this->assertStringContainsString('name="version" value="1"', $html);
        $this->assertSame(0, $this->changeCount());
    }

    public function test_update_with_stale_version_is_409_and_changes_nothing(): void
    {
        $uid = $this->loginAs(['kassenwart']);
        $id = $this->booking($uid);
        $first = $this->post('/bookings/' . $id . '/edit', $this->form(['amount' => '13']) + [
            'version' => '1', 'reason' => 'Erste Änderung',
        ]);
        $this->assertSame(302, $first->getStatusCode());

        // Zweites Formular wurde noch mit Version 1 geladen.
        $res = $this->post('/bookings/' . $id . '/edit', $this->form(['amount' => '99']) + [
            'version' => '1', 'reason' => 'Veraltete Änderung',
        ]);
        $html = (string) $res->getBody();

        $this->assertSame(409, $res->getStatusCode());
        $this->assertStringContainsString('inzwischen geändert', $html);
        $this->assertStringContainsString('Bitte laden Sie die Buchung neu', $html);
        $this->assertStringContainsString('href="/bookings/' . $id . '"', $html);
        $row = $this->pdo->query("SELECT amount, version FROM bookings WHERE id = $id")->fetch();
        $this->assertSame('13.00', $row['amount']);
        $this->assertSame(2, (int) $row['version']);
        $this->assertSame(1, $this->changeCount());

        $cancel = $this->post('/bookings/' . $id . '/cancel', ['version' => '1', 'reason' => 'Storno']);
        $this->assertSame(409, $cancel->getStatusCode());
        $this->assertSame(1, $this->changeCount());
    }

    public function test_change_routes_require_write_permission(): void
    {
        $uid = $this->loginAs(['kassenpruefer']);
        $id = $this->booking($uid);

        $this->assertSame(403, $this->get('/bookings/' . $id . '/edit')->getStatusCode());
        $this->assertSame(403, $this->post('/bookings/' . $id . '/edit', ['version' => '1', 'reason' => 'x'])->getStatusCode());
        $this->assertSame(403, $this->post('/bookings/' . $id . '/cancel', ['version' => '1', 'reason' => 'x'])->getStatusCode());
        $this->assertSame(403, $this->post('/bookings/' . $id . '/restore', ['version' => '1', 'reason' => 'x'])->getStatusCode());
        $this->assertSame(403, $this->get('/corrections/new')->getStatusCode());
        $this->assertSame(403, $this->post('/corrections/new', ['reason' => 'x'])->getStatusCode());
        $this->assertSame(403, $this->get('/transfers/new')->getStatusCode());
        $this->assertSame(403, $this->post('/transfers/new', [])->getStatusCode());
        $this->assertSame(0, $this->changeCount());
    }

    public function test_unknown_booking_is_404_for_edit_cancel_and_correction(): void
    {
        $this->loginAs(['kassenwart']);

        $this->assertSame(404, $this->get('/bookings/4711/edit')->getStatusCode());
        $this->assertSame(404, $this->post('/bookings/4711/edit', $this->form() + ['version' => '1', 'reason' => 'x'])->getStatusCode());
        $this->assertSame(404, $this->post('/bookings/4711/cancel', ['version' => '1', 'reason' => 'x'])->getStatusCode());
        $this->assertSame(404, $this->get('/corrections/new', ['of' => '4711'])->getStatusCode());
    }

    public function test_cancel_and_restore_via_detail_page(): void
    {
        $uid = $this->loginAs(['kassenwart']);
        $id = $this->booking($uid);

        $empty = $this->post('/bookings/' . $id . '/cancel', ['version' => '1', 'reason' => '']);
        $this->assertSame(422, $empty->getStatusCode());
        $this->assertStringContainsString('Bitte geben Sie einen Grund an.', (string) $empty->getBody());
        $this->assertStringContainsString('name="version" value="1"', (string) $empty->getBody());

        $res = $this->post('/bookings/' . $id . '/cancel', ['version' => '1', 'reason' => 'Doppelt erfasst']);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/bookings/' . $id, $res->getHeaderLine('Location'));

        $detail = (string) $this->get('/bookings/' . $id)->getBody();
        $this->assertStringContainsString('Buchung wurde storniert.', $detail);
        $this->assertMatchesRegularExpression('/Aktiv\s*→\s*Storniert/u', $detail);
        $this->assertStringContainsString('action="/bookings/' . $id . '/restore"', $detail);
        $this->assertStringNotContainsString('href="/bookings/' . $id . '/edit"', $detail);
        $this->assertSame(409, $this->get('/bookings/' . $id . '/edit')->getStatusCode());

        $res = $this->post('/bookings/' . $id . '/restore', ['version' => '2', 'reason' => 'War doch richtig']);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('active', $this->pdo->query("SELECT status FROM bookings WHERE id = $id")->fetchColumn());
        $this->assertSame(2, $this->changeCount());
    }

    public function test_transfer_page_creates_two_bookings_and_cancel_hits_both_legs(): void
    {
        $this->loginAs(['kassenwart']);

        $form = (string) $this->get('/transfers/new')->getBody();
        $this->assertStringContainsString('name="from_account_id"', $form);
        $this->assertStringContainsString('name="to_account_id"', $form);

        $same = $this->post('/transfers/new', [
            'from_account_id' => (string) $this->cash, 'to_account_id' => (string) $this->cash,
            'amount' => '100', 'booking_date' => '2026-03-10', 'description' => 'Einzahlung',
        ]);
        $this->assertSame(422, $same->getStatusCode());
        $this->assertStringContainsString('Bitte wählen Sie zwei verschiedene Konten.', (string) $same->getBody());

        $res = $this->post('/transfers/new', [
            'from_account_id' => (string) $this->cash, 'to_account_id' => (string) $this->bank,
            'amount' => '100,00', 'booking_date' => '2026-03-10', 'description' => 'Einzahlung',
        ]);
        $this->assertSame(302, $res->getStatusCode());
        $legs = $this->pdo->query("SELECT id, account_id, direction, transfer_id FROM bookings WHERE kind = 'transfer' ORDER BY id")->fetchAll();
        $this->assertCount(2, $legs);
        $this->assertSame('/bookings/' . $legs[0]['id'], $res->getHeaderLine('Location'));
        $this->assertSame($legs[0]['transfer_id'], $legs[1]['transfer_id']);

        $detail = (string) $this->get('/bookings/' . $legs[0]['id'])->getBody();
        $this->assertStringContainsString('href="/bookings/' . $legs[1]['id'] . '"', $detail, 'Link zur Gegenbuchung');
        $this->assertStringNotContainsString('/bookings/' . $legs[0]['id'] . '/edit', $detail);
        $this->assertSame(409, $this->get('/bookings/' . $legs[0]['id'] . '/edit')->getStatusCode());

        $cancel = $this->post('/bookings/' . $legs[1]['id'] . '/cancel', ['version' => '1', 'reason' => 'Falsches Konto']);
        $this->assertSame(302, $cancel->getStatusCode());
        $this->assertSame(
            ['cancelled', 'cancelled'],
            $this->pdo->query("SELECT status FROM bookings WHERE kind = 'transfer' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)
        );
        $this->assertSame(2, $this->changeCount());
    }

    public function test_correction_page_creates_correction_with_reference(): void
    {
        $uid = $this->loginAs(['kassenwart']);
        $original = $this->booking($uid);

        $form = $this->get('/corrections/new', ['of' => (string) $original]);
        $html = (string) $form->getBody();
        $this->assertSame(200, $form->getStatusCode());
        $this->assertStringContainsString('Raummiete März', $html);
        $this->assertStringContainsString('name="correction_of_id" value="' . $original . '"', $html);

        $missing = $this->post('/corrections/new', $this->form(['description' => '']) + [
            'correction_of_id' => (string) $original, 'reason' => '',
        ]);
        $this->assertSame(422, $missing->getStatusCode());
        $this->assertStringContainsString('Bitte geben Sie einen Grund an.', (string) $missing->getBody());

        $res = $this->post('/corrections/new', $this->form(['description' => '', 'amount' => '2,50', 'direction' => 'in']) + [
            'correction_of_id' => (string) $original, 'reason' => 'Miete war zu hoch gebucht',
        ]);
        $this->assertSame(302, $res->getStatusCode());
        $row = $this->pdo->query("SELECT id, kind, correction_of_id, description FROM bookings WHERE kind = 'correction'")->fetch();
        $this->assertSame('/bookings/' . $row['id'], $res->getHeaderLine('Location'));
        $this->assertSame($original, (int) $row['correction_of_id']);
        $this->assertSame('Miete war zu hoch gebucht', $row['description']);

        $detail = (string) $this->get('/bookings/' . $row['id'])->getBody();
        $this->assertStringContainsString('Korrekturbuchung wurde angelegt.', $detail);
        $this->assertStringContainsString('href="/bookings/' . $original . '"', $detail);
        $this->assertStringContainsString('Als Korrekturbuchung angelegt', $detail);

        $list = (string) $this->get('/bookings')->getBody();
        $this->assertSame(0, substr_count($list, 'geändert'), 'Neue Korrektur ist nicht "geaendert"');
    }

    public function test_correction_without_original_for_cash_difference(): void
    {
        $this->loginAs(['kassenwart']);

        $html = (string) $this->get('/corrections/new')->getBody();
        $this->assertStringContainsString('name="correction_of_id" value=""', $html);

        $res = $this->post('/corrections/new', $this->form(['description' => 'Kassendifferenz', 'category_id' => '']) + [
            'correction_of_id' => '', 'reason' => 'Kassenzählung',
        ]);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertNull($this->pdo->query("SELECT correction_of_id FROM bookings WHERE kind = 'correction'")->fetchColumn());
    }

    public function test_list_offers_correction_and_transfer_entry_points_for_writers_only(): void
    {
        $this->loginAs(['kassenwart']);
        $html = (string) $this->get('/bookings')->getBody();
        $this->assertStringContainsString('href="/corrections/new"', $html);
        $this->assertStringContainsString('href="/transfers/new"', $html);
    }

    public function test_list_hides_correction_and_transfer_entry_points_for_readers(): void
    {
        $this->loginAs(['kassenpruefer']);
        $html = (string) $this->get('/bookings')->getBody();
        $this->assertStringNotContainsString('href="/corrections/new"', $html);
        $this->assertStringNotContainsString('href="/transfers/new"', $html);
    }
}
