<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use PDO;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;
use Vereinskiste\BookingImport\XlsxReader;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Tests\TestCase;
use ZipArchive;

final class EventsFundingImportPagesTest extends TestCase
{
    use WebHarness;

    private PDO $pdo;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->pdo->exec("INSERT INTO accounts (name, type, opening_balance, opening_date) VALUES ('Giro', 'bank', 100, '2026-01-01')");
        $this->bootApp();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) { @unlink($file); }
    }

    public function test_template_is_cloned_with_budget_and_tasks_but_without_progress(): void
    {
        $this->loginAs(['treasurer']);
        $this->assertSame(302, $this->postForm('/events/new', ['kind' => 'template', 'title' => 'Sommerfest'])->getStatusCode());
        $this->assertSame(302, $this->postForm('/events/1/budget', ['label' => 'Würste', 'direction' => 'out', 'planned_amount' => '368,93'])->getStatusCode());
        $this->assertSame(302, $this->postForm('/events/1/task', ['title' => 'Grill besorgen', 'person_name' => 'Jule', 'due_offset_days' => '-7'])->getStatusCode());
        $this->pdo->exec('UPDATE event_tasks SET done = true');
        $this->assertSame(422, $this->postForm('/events/1/clone', ['title' => 'Sommerfest 2027', 'kind' => 'event'])->getStatusCode());
        $clone = $this->postForm('/events/1/clone', ['title' => 'Sommerfest 2027', 'kind' => 'event', 'starts_on' => '2027-06-20']);
        $this->assertSame('/events/2', $clone->getHeaderLine('Location'));
        $html = (string) $this->get('/events/2')->getBody();
        $this->assertStringContainsString('Würste', $html);
        $this->assertStringContainsString('Jule', $html);
        $this->assertStringContainsString('13.06.2027', $html);
        $this->assertSame('f', $this->pdo->query('SELECT done::text FROM event_tasks WHERE event_id = 2')->fetchColumn()[0]);
        $this->assertStringContainsString('Sommerfest', (string) $this->get('/events?view=templates')->getBody());
    }

    public function test_booking_on_event_and_funding_feeds_actuals_and_blocks_deletion(): void
    {
        $this->loginAs(['treasurer']);
        $this->postForm('/events/new', ['title' => 'Erstigrillen', 'starts_on' => '2026-05-01']);
        $this->postForm('/events/1/budget', ['label' => 'Zuschuss', 'direction' => 'in', 'planned_amount' => '500']);
        $this->assertSame(302, $this->postForm('/funding/new', ['title' => 'Erstigrillen', 'funder' => 'Dekanat', 'event_id' => '1',
            'status' => 'submitted', 'requested_amount' => '474,35'])->getStatusCode());
        $booking = $this->postForm('/bookings/new', ['account_id' => '1', 'booking_date' => '2026-05-13', 'amount' => '474,35', 'direction' => 'in',
            'description' => 'Erstattung', 'event_id' => '1', 'funding_id' => '1']);
        $this->assertSame(302, $booking->getStatusCode(), (string) $booking->getBody());
        $this->assertStringContainsString('474,35', (string) $this->get('/events/1')->getBody());
        $this->assertStringContainsString('474,35', (string) $this->get('/funding')->getBody());
        $this->assertSame(200, $this->get('/bookings/new')->getStatusCode());
        $this->assertStringContainsString('Dekanat', (string) $this->get('/funding/1')->getBody());
        $this->assertSame(422, $this->postForm('/events/1/delete', [])->getStatusCode());
        $this->assertSame(422, $this->postForm('/funding/1/delete', [])->getStatusCode());
        $this->assertSame(422, $this->postForm('/bookings/new', ['account_id' => '1', 'booking_date' => '2026-05-13', 'amount' => '1', 'direction' => 'in',
            'description' => 'x', 'event_id' => '999'])->getStatusCode());
    }

    public function test_permissions_separate_reading_from_writing(): void
    {
        $this->loginAs(['auditor']);
        $this->assertSame(200, $this->get('/events')->getStatusCode());
        $this->assertSame(200, $this->get('/funding')->getStatusCode());
        $this->assertSame(200, $this->get('/imports/bookings')->getStatusCode());
        $this->assertSame(403, $this->postForm('/events/new', ['title' => 'x', 'starts_on' => '2026-05-01'])->getStatusCode());
        $this->assertSame(403, $this->postForm('/funding/new', ['title' => 'x'])->getStatusCode());
        $this->assertSame(403, $this->postForm('/imports/bookings/1/confirm', [])->getStatusCode());
    }

    public function test_xlsx_import_needs_review_and_creates_bookings_once(): void
    {
        $this->loginAs(['treasurer']);
        $this->assertSame(302, $this->upload($this->workbook()));
        $page = (string) $this->get('/imports/bookings/1')->getBody();
        $this->assertStringContainsString('Kasse 2026', $page);
        $this->assertSame(302, $this->postForm('/imports/bookings/1/mapping', ['version' => '1', 'sheet' => '0', 'header_row' => '1', 'date' => '1', 'amount' => '2',
            'counterparty' => '3', 'description' => '4', 'event' => '4', 'notes' => ['5']])->getStatusCode());
        $page = (string) $this->get('/imports/bookings/1')->getBody();
        // Vor der Bestaetigung existiert nichts, nur die zwei Zeilen mit Datum sind bereit.
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM bookings')->fetchColumn());
        $this->assertStringContainsString('<strong>2 ', $page);
        $this->assertStringContainsString('name="date[4]"', $page);

        $this->assertSame(422, $this->postForm('/imports/bookings/1/confirm', ['version' => '2'])->getStatusCode());
        $this->assertSame(422, $this->postForm('/imports/bookings/1/confirm', ['version' => '2', 'confirm_check' => '1', 'date' => ['5' => 'kaputt']])->getStatusCode());
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM bookings')->fetchColumn());
        $done = $this->postForm('/imports/bookings/1/confirm', ['version' => '2', 'confirm_check' => '1', 'date' => ['5' => '2026-02-15'], 'event_map' => ['0' => 'new']]);
        $this->assertSame(302, $done->getStatusCode(), (string) $done->getBody());
        $rows = $this->pdo->query('SELECT booking_date::text AS d, amount::text AS a, direction, description, counterparty, event_id FROM bookings ORDER BY booking_date, id')->fetchAll();
        $this->assertCount(3, $rows);
        $this->assertSame(['2026-01-28', '99.00', 'out', 'Grillen', 'Massoud'], [$rows[0]['d'], $rows[0]['a'], $rows[0]['direction'], $rows[0]['description'], $rows[0]['counterparty']]);
        $this->assertSame('Pfand – Beleg fehlt', $rows[1]['description']);
        $this->assertSame('140.38', $rows[1]['a']);
        $this->assertSame('in', $rows[2]['direction']);
        $this->assertSame('Grillen', $this->pdo->query('SELECT title FROM events WHERE id = ' . (int) $rows[0]['event_id'])->fetchColumn());
        $this->assertSame('[]', $this->pdo->query('SELECT sheets::text FROM booking_imports')->fetchColumn());
        // Zweites Absenden aendert nichts.
        $this->assertSame(409, $this->postForm('/imports/bookings/1/confirm', ['version' => '2'])->getStatusCode());
        $this->assertSame(3, (int) $this->pdo->query('SELECT count(*) FROM bookings')->fetchColumn());
    }

    public function test_csv_import_flags_duplicates_and_rejects_rows_before_opening(): void
    {
        $this->loginAs(['treasurer']);
        $this->postForm('/bookings/new', ['account_id' => '1', 'booking_date' => '2026-03-01', 'amount' => '10,00', 'direction' => 'in', 'description' => 'Vorhanden']);
        $path = $this->tmpFile("Datum;Betrag;Zweck\n01.03.2026;10,00;Doppelt\n02.03.2026;-5,50;Neu\n31.12.2025;7,00;Zu alt\n");
        $this->assertSame(302, $this->upload($path, 'kasse.csv'));
        $this->assertSame(302, $this->postForm('/imports/bookings/1/mapping', ['version' => '1', 'sheet' => '0', 'header_row' => '1', 'date' => '0', 'amount' => '1', 'description' => '2'])->getStatusCode());
        $page = (string) $this->get('/imports/bookings/1')->getBody();
        $this->assertStringContainsString('<strong>1 ', $page);
        $this->assertMatchesRegularExpression('/name="skip\[2\]"[^>]*checked/', $page);
        $this->assertStringContainsString('name="date[4]"', $page);
        $this->assertSame(302, $this->postForm('/imports/bookings/1/confirm', ['version' => '2', 'confirm_check' => '1', 'skip' => ['2' => '1']])->getStatusCode());
        $this->assertSame(2, (int) $this->pdo->query('SELECT count(*) FROM bookings')->fetchColumn());
    }

    public function test_rows_can_be_ignored_in_mapping_and_preview(): void
    {
        $this->loginAs(['treasurer']);
        $path = $this->tmpFile("Datum;Betrag;Zweck\n01.03.2026;10,00;Eins\n02.03.2026;20,00;Zwei\n03.03.2026;30,00;Drei\n;40,00;Ohne Datum\n05.03.2026;50,00;Fuenf\n");
        $this->assertSame(302, $this->upload($path, 'kasse.csv'));
        $mapping = ['version' => '1', 'sheet' => '0', 'header_row' => '1', 'date' => '0', 'amount' => '1', 'description' => '2'];
        $this->assertSame(422, $this->postForm('/imports/bookings/1/mapping', $mapping + ['ignore_rows' => 'vier'])->getStatusCode());
        $this->assertSame(302, $this->postForm('/imports/bookings/1/mapping', $mapping + ['ignore_rows' => '2, 6-6'])->getStatusCode());
        $page = (string) $this->get('/imports/bookings/1')->getBody();
        $this->assertStringContainsString('value="2, 6-6"', $page);
        $this->assertStringContainsString('<strong>2 ', $page);
        $this->assertStringNotContainsString('name="skip[2]"', $page);
        // Auch eine Zeile mit eingetragenem Datum bleibt draussen, wenn sie angekreuzt ist.
        $this->assertSame(302, $this->postForm('/imports/bookings/1/confirm', ['version' => '2', 'confirm_check' => '1',
            'skip' => ['3' => '1', '5' => '1'], 'date' => ['5' => '2026-03-04']])->getStatusCode());
        $this->assertSame(['Drei'], $this->pdo->query('SELECT description FROM bookings')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function test_booking_list_exports_the_current_filter_as_csv(): void
    {
        $this->loginAs(['treasurer']);
        $this->postForm('/events/new', ['title' => 'Sommerfest', 'starts_on' => '2026-06-20']);
        $base = ['account_id' => '1', 'booking_date' => '2026-06-21', 'amount' => '5', 'direction' => 'in'];
        $this->postForm('/bookings/new', $base + ['description' => 'Mit Fest', 'event_id' => '1']);
        $this->postForm('/bookings/new', $base + ['description' => 'Ohne Fest']);
        $this->assertStringContainsString('href="/reports/bookings.csv?event_id=1"', (string) $this->get('/bookings?event_id=1')->getBody());
        $csv = $this->app->handle((new ServerRequestFactory())->createServerRequest('GET', '/reports/bookings.csv')->withQueryParams(['event_id' => '1']));
        $this->assertSame(200, $csv->getStatusCode());
        $body = (string) $csv->getBody();
        $this->assertStringContainsString('Mit Fest', $body);
        $this->assertStringContainsString('Sommerfest', $body);
        $this->assertStringNotContainsString('Ohne Fest', $body);
        $this->pdo->exec("UPDATE user_roles SET role = 'secretary'");
        $this->assertStringNotContainsString('/reports/bookings.csv', (string) $this->get('/bookings')->getBody());
    }

    public function test_typed_date_before_opening_shows_a_message_and_books_nothing(): void
    {
        $this->loginAs(['treasurer']);
        $this->assertSame(302, $this->upload($this->tmpFile("Datum;Betrag;Zweck\n05.03.2026;10,00;Ok\n01.02.-28.02.;20,00;Zeitraum\n"), 'kasse.csv'));
        $this->postForm('/imports/bookings/1/mapping', ['version' => '1', 'sheet' => '0', 'header_row' => '1', 'date' => '0', 'amount' => '1', 'description' => '2']);
        $res = $this->postForm('/imports/bookings/1/confirm', ['version' => '2', 'confirm_check' => '1', 'date' => ['3' => '2025-02-15']]);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('01.01.2026', (string) $res->getBody());
        $this->assertStringContainsString('value="2025-02-15"', (string) $res->getBody());
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM bookings')->fetchColumn());
    }

    public function test_cashbook_overview_shows_balances_running_saldo_and_charts(): void
    {
        $this->loginAs(['secretary']);
        $this->pdo->exec("INSERT INTO accounts (name, type, opening_balance, opening_date) VALUES ('Kasse', 'cash', 50, '2026-01-01')");
        $insert = $this->pdo->prepare("INSERT INTO bookings (account_id, booking_date, amount, direction, description, created_by, status) VALUES (?, ?, ?, ?, ?, 1, ?)");
        $insert->execute([1, '2026-02-01', '1000.00', 'in', 'Spende', 'active']);
        $insert->execute([1, '2026-02-02', '250.50', 'out', 'Einkauf', 'active']);
        $insert->execute([1, '2026-02-03', '9.00', 'out', 'Storniert', 'cancelled']);
        $res = $this->get('/cashbook?from=2026-01-01&to=2026-12-31');
        $this->assertSame(200, $res->getStatusCode());
        $page = (string) $res->getBody();
        $this->assertStringContainsString('899,50', $page);
        $this->assertStringContainsString('849,50', $page);
        $this->assertStringContainsString('1.150,00', $page);
        $this->assertStringNotContainsString('Storniert', $page);
        $this->assertStringContainsString('id="chart-monthly"', $page);
        $this->assertStringContainsString('chart.umd.min.js', $page);
        $this->assertStringContainsString('Noch keine Buchungen vorhanden', (string) $this->get('/cashbook?account_id=2&from=2026-01-01&to=2026-12-31')->getBody());
        $this->assertSame(422, $this->get('/cashbook?from=2026-12-31&to=2026-01-01')->getStatusCode());
        $this->pdo->exec('DELETE FROM user_roles');
        $this->assertSame(403, $this->get('/cashbook')->getStatusCode());
    }

    public function test_expense_receipt_upload_can_be_reviewed(): void
    {
        $this->loginAs(['treasurer']);
        $path = $this->tmpFile(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lXcAAAAASUVORK5CYII='));
        $response = $this->app->handle((new ServerRequestFactory())->createServerRequest('POST', '/expenses/new')
            ->withParsedBody(['_csrf' => $this->csrf(), 'person_name' => 'Mira', 'purpose' => 'Material',
                'amount' => '12,50', 'spent_on' => '2026-02-01', 'has_receipt' => '1', 'version' => '0'])
            ->withUploadedFiles(['receipts' => [new UploadedFile($path, 'beleg.png', 'image/png', filesize($path))]]));
        $this->assertSame(302, $response->getStatusCode());
        $document = $this->pdo->query('SELECT id, file_id, review_status FROM expense_documents')->fetch();
        $this->assertSame('pending', $document['review_status']);
        $this->assertSame(200, $this->get('/documents/' . $document['file_id'])->getStatusCode());
        $this->assertSame(302, $this->postForm('/expenses/1/documents/' . $document['id'] . '/review', [])->getStatusCode());
        $this->assertSame('reviewed', $this->pdo->query('SELECT review_status FROM expense_documents')->fetchColumn());
    }

    public function test_expense_status_follows_the_linked_booking(): void
    {
        $user = $this->loginAs(['treasurer']);
        $member = (new \Vereinskiste\Members\MemberRepository($this->pdo))->save(null, ['name' => 'Jule Test'], 0, $user);
        $this->assertSame(422, $this->postForm('/expenses/new', ['purpose' => 'Ohne Person', 'amount' => '1', 'spent_on' => '2026-02-01'])->getStatusCode());
        $this->assertSame(302, $this->postForm('/expenses/new', ['member_id' => (string) $member, 'purpose' => 'Würste', 'amount' => '48,60', 'spent_on' => '2026-02-01', 'has_receipt' => '1'])->getStatusCode());
        $this->assertStringContainsString('48,60', (string) $this->get('/expenses')->getBody());
        $this->assertStringContainsString('48,60', (string) $this->get('/cashbook')->getBody());

        $this->assertSame(422, $this->postForm('/expenses/1/reimburse', ['account_id' => '1', 'booking_date' => '2025-01-01'])->getStatusCode());
        $this->assertSame(302, $this->postForm('/expenses/1/reimburse', ['account_id' => '1', 'booking_date' => '2026-02-05'])->getStatusCode());
        $booking = $this->pdo->query('SELECT id, amount::text AS amount, direction, counterparty, version FROM bookings')->fetch();
        $this->assertSame(['48.60', 'out', 'Jule Test'], [$booking['amount'], $booking['direction'], $booking['counterparty']]);
        $this->assertStringContainsString('Jule Test · Würste', (string) $this->get('/bookings/' . $booking['id'])->getBody());
        $this->assertStringContainsString('Erstattet', (string) $this->get('/members/' . $member)->getBody());
        $this->assertStringNotContainsString('Würste', (string) $this->get('/expenses')->getBody());
        $this->assertSame(422, $this->postForm('/expenses/1/reimburse', ['account_id' => '1', 'booking_date' => '2026-02-05'])->getStatusCode());
        $this->assertSame(422, $this->postForm('/expenses/1/delete', [])->getStatusCode());

        $this->assertSame(302, $this->postForm('/bookings/' . $booking['id'] . '/cancel', ['version' => (string) $booking['version'], 'reason' => 'Falsch gebucht'])->getStatusCode());
        $this->assertStringContainsString('Würste', (string) $this->get('/expenses')->getBody());

        $this->postForm('/bookings/new', ['account_id' => '1', 'booking_date' => '2026-03-01', 'amount' => '48,60', 'direction' => 'out', 'description' => 'Aus Import']);
        $other = (int) $this->pdo->query("SELECT id FROM bookings WHERE description = 'Aus Import'")->fetchColumn();
        $this->assertSame(422, $this->postForm('/expenses/1/link', ['booking_id' => (string) $booking['id']])->getStatusCode());
        $this->assertSame(302, $this->postForm('/expenses/1/link', ['booking_id' => (string) $other])->getStatusCode());
        $this->assertStringContainsString('Würste', (string) $this->get('/expenses?state=reimbursed')->getBody());
        $this->assertSame(302, $this->postForm('/expenses/1/unlink', [])->getStatusCode());
        $this->assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM bookings WHERE id = $other AND status = 'active'")->fetchColumn());
        $this->assertSame(302, $this->postForm('/expenses/1/delete', [])->getStatusCode());
    }

    public function test_amount_sign_decides_the_direction_and_is_not_stored(): void
    {
        $this->loginAs(['treasurer']);
        $this->pdo->exec("INSERT INTO accounts (name, type, opening_date) VALUES ('Sign', 'cash', '2026-01-01')");
        $account = (string) $this->pdo->query("SELECT id FROM accounts WHERE name = 'Sign'")->fetchColumn();
        $base = ['account_id' => $account, 'booking_date' => '2026-02-01', 'description' => 'Vorzeichen'];
        $this->assertSame(302, $this->postForm('/bookings/new', $base + ['amount' => '-1.234,50', 'direction' => 'in'])->getStatusCode());
        $this->assertSame(302, $this->postForm('/bookings/new', $base + ['amount' => '+5,00', 'direction' => 'out'])->getStatusCode());
        $rows = $this->pdo->query("SELECT amount::text AS amount, direction FROM bookings WHERE description = 'Vorzeichen' ORDER BY id")->fetchAll();
        $this->assertSame(['1234.50', 'out'], [$rows[0]['amount'], $rows[0]['direction']]);
        $this->assertSame(['5.00', 'in'], [$rows[1]['amount'], $rows[1]['direction']]);
    }

    public function test_notes_are_sanitized_and_old_plain_text_stays_readable(): void
    {
        $this->loginAs(['treasurer']);
        $this->postForm('/events/new', ['title' => 'Fest', 'starts_on' => '2026-06-20', 'notes' => '<p><strong>Wichtig</strong></p><script>alert(1)</script>']);
        $stored = (string) $this->pdo->query('SELECT notes FROM events')->fetchColumn();
        $this->assertStringContainsString('<strong>Wichtig</strong>', $stored);
        $this->assertStringNotContainsString('script', $stored);
        $this->assertStringContainsString('data-rich-text', (string) $this->get('/events/1')->getBody());
        $this->assertStringContainsString('rich-text.js', (string) $this->get('/events/1')->getBody());
        $this->assertSame("Zeile 1<br />\nZeile &lt;2&gt;", \Vereinskiste\ClubDocuments\Content::note("Zeile 1\nZeile <2>"));

        $this->pdo->exec("UPDATE events SET notes = '<script>alert(2)</script><b>alt</b>'");
        $this->pdo->exec("UPDATE user_roles SET role = 'auditor'");
        $page = (string) $this->get('/events/1')->getBody();
        $this->assertStringContainsString('<b>alt</b>', $page);
        $this->assertStringNotContainsString('alert(2)', $page);
        $this->assertStringNotContainsString('data-rich-text', $page);
    }

    public function test_lists_are_kept_per_event_and_cloned_without_ticks(): void
    {
        $this->loginAs(['treasurer']);
        $this->postForm('/events/new', ['kind' => 'template', 'title' => 'Sommerfest']);
        $this->postForm('/events/new', ['title' => 'Anderes', 'starts_on' => '2026-06-20']);
        $this->assertSame(422, $this->postForm('/events/1/list', ['list_title' => ''])->getStatusCode());
        $this->assertSame(302, $this->postForm('/events/1/list', ['list_title' => 'Einkaufsliste'])->getStatusCode());
        $this->assertSame(302, $this->postForm('/events/1/list-item/1', ['label' => 'Würste', 'quantity' => '50 Stück', 'estimated_cost' => '40,00', 'person_name' => 'Jule'])->getStatusCode());
        $this->assertSame(302, $this->postForm('/events/1/list-item/1', ['label' => 'Senf', 'estimated_cost' => '2,50'])->getStatusCode());
        $this->assertSame(422, $this->postForm('/events/1/list-item/1', ['label' => 'Kaputt', 'estimated_cost' => 'viel'])->getStatusCode());
        $this->assertSame(302, $this->postForm('/events/1/item-toggle/1', [])->getStatusCode());
        $page = (string) $this->get('/events/1')->getBody();
        $this->assertStringContainsString('42,50', $page);
        $this->assertStringContainsString('1 / 2', $page);

        // Fremde Veranstaltung darf Eintraege nicht aendern.
        $this->postForm('/events/2/item-save/2', ['label' => 'Fremd']);
        $this->postForm('/events/2/list-item/1', ['label' => 'Fremd']);
        $this->assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM event_list_items WHERE label = 'Fremd'")->fetchColumn());
        $this->assertSame(302, $this->postForm('/events/1/item-save/2', ['label' => 'Scharfer Senf', 'quantity' => '2'])->getStatusCode());
        $this->assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM event_list_items WHERE label = 'Scharfer Senf' AND estimated_cost IS NULL")->fetchColumn());

        $clone = $this->postForm('/events/1/clone', ['title' => 'Sommerfest 2027', 'kind' => 'event', 'starts_on' => '2027-06-20']);
        $id = (int) basename($clone->getHeaderLine('Location'));
        $this->assertSame(2, (int) $this->pdo->query("SELECT count(*) FROM event_list_items i JOIN event_lists l ON l.id = i.list_id WHERE l.event_id = $id AND NOT i.done")->fetchColumn());
        $this->assertStringContainsString('Einkaufsliste', (string) $this->get('/events/' . $id)->getBody());
        $this->assertSame(302, $this->postForm('/events/1/list-remove/1', [])->getStatusCode());
        $this->assertSame(2, (int) $this->pdo->query('SELECT count(*) FROM event_list_items')->fetchColumn());
    }

    public function test_reader_rejects_unsafe_and_legacy_files(): void
    {
        $this->loginAs(['treasurer']);
        $this->assertSame(422, $this->upload($this->tmpFile("\xD0\xCF\x11\xE0 alte Datei"), 'alt.xls'));
        $zip = new ZipArchive();
        $path = $this->tmpFile('');
        unlink($path);
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('xl/workbook.xml', '<!DOCTYPE x [<!ENTITY a SYSTEM "file:///etc/passwd">]><workbook>&a;</workbook>');
        $zip->close();
        $this->assertSame(422, $this->upload($path));
        $this->assertSame('AA', XlsxReader::columnName(26));
    }

    public function test_budget_and_tasks_can_be_edited_and_history_shows_names(): void
    {
        $this->loginAs(['treasurer']);
        $this->postForm('/events/new', ['title' => 'Sommerfest', 'starts_on' => '2026-06-20']);
        $this->postForm('/events/1/budget', ['label' => 'Würste', 'direction' => 'out', 'planned_amount' => '10']);
        $this->postForm('/events/1/task', ['title' => 'Grill', 'person_name' => 'Jule']);
        $this->pdo->exec('UPDATE event_tasks SET done = true');
        $this->assertSame(302, $this->postForm('/events/1/budget-save/1', ['label' => 'Bratwürste', 'direction' => 'out', 'planned_amount' => '20,50'])->getStatusCode());
        $this->assertSame(422, $this->postForm('/events/1/budget-save/1', ['label' => '', 'direction' => 'out', 'planned_amount' => '1'])->getStatusCode());
        $this->assertSame(302, $this->postForm('/events/1/task-save/1', ['title' => 'Grill holen', 'person_name' => 'Henrik', 'due_offset_days' => '-3'])->getStatusCode());
        $this->assertSame('20.50', $this->pdo->query('SELECT planned_amount::text FROM event_budget_items')->fetchColumn());
        $task = $this->pdo->query('SELECT title, person_name, due_offset_days, done::text AS done FROM event_tasks')->fetch();
        $this->assertSame(['Grill holen', 'Henrik', -3, 'true'], [$task['title'], $task['person_name'], (int) $task['due_offset_days'], $task['done']]);

        $this->postForm('/bookings/new', ['account_id' => '1', 'booking_date' => '2026-06-21', 'amount' => '5', 'direction' => 'in', 'description' => 'Spende']);
        $this->assertSame(302, $this->postForm('/bookings/1/edit', ['account_id' => '1', 'booking_date' => '2026-06-21', 'amount' => '5', 'direction' => 'in', 'description' => 'Spende',
            'event_id' => '1', 'version' => '1', 'reason' => 'Zuordnung'])->getStatusCode());
        $this->assertStringContainsString('Sommerfest', (string) $this->get('/bookings/1')->getBody());
        $this->assertMatchesRegularExpression('/→\s*Sommerfest/u', (string) $this->get('/bookings/1')->getBody());
        $this->assertSame(1, substr_count((string) $this->get('/bookings?event_id=1')->getBody(), 'href="/bookings/1"'));
        $this->assertStringContainsString('name="event_id"', (string) $this->get('/bookings')->getBody());
    }

    public function test_old_drafts_are_purged_and_confirmed_imports_stay(): void
    {
        $user = $this->loginAs(['treasurer']);
        $insert = $this->pdo->prepare("INSERT INTO booking_imports (account_id, file_name, file_sha256, status, sheets, created_by, created_at)
            VALUES (1, 'x', 'x', ?, '[]', ?, ?::timestamptz)");
        $insert->execute(['draft', $user, '2020-01-01']);
        $insert->execute(['confirmed', $user, '2020-01-01']);
        $insert->execute(['draft', $user, date('Y-m-d')]);
        $this->assertSame(1, (new \Vereinskiste\BookingImport\BookingImportService($this->pdo))->purgeDrafts());
        $this->assertSame(2, (int) $this->pdo->query('SELECT count(*) FROM booking_imports')->fetchColumn());
    }

    public function test_dashboard_offers_new_cards_only_with_permission(): void
    {
        $metrics = new \Vereinskiste\Dashboard\Metrics($this->pdo);
        $this->assertSame('0', $metrics->forCard('events')['value']);
        $this->assertSame('0', $metrics->forCard('funding')['value']);
        $allowed = \Vereinskiste\Dashboard\Catalog::allowed(\Vereinskiste\Dashboard\Catalog::SHORTCUTS, static fn (string $right): bool => $right === 'events.read');
        $this->assertSame([], $allowed);
    }

    public function test_pages_render_for_markup_checks(): void
    {
        $this->loginAs(['admin']);
        foreach (['/events', '/events?view=templates', '/events/new', '/events/new?kind=template', '/funding', '/funding/new', '/imports/bookings'] as $path) {
            $this->assertSame(200, $this->get($path)->getStatusCode(), $path);
        }
    }

    private function upload(string $path, string $name = 'kasse.xlsx'): int
    {
        return $this->app->handle((new ServerRequestFactory())->createServerRequest('POST', '/imports/bookings')
            ->withParsedBody(['_csrf' => $this->csrf(), 'account_id' => '1'])
            ->withUploadedFiles(['receipt' => new UploadedFile($path, $name, 'application/octet-stream', filesize($path))]))->getStatusCode();
    }

    private function tmpFile(string $content): string
    {
        $path = sys_get_temp_dir() . '/vk_import_' . bin2hex(random_bytes(6));
        $this->files[] = $path;
        file_put_contents($path, $content);
        return $path;
    }

    /** Aufbau wie ein echtes Kassenbuch: Ueberschrift, Startzeile, Datumsstil, gemeinsame Texte, Formelsumme. */
    private function workbook(): string
    {
        $path = $this->tmpFile('');
        unlink($path);
        $main = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('xl/workbook.xml', '<workbook ' . $main . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Kasse 2026" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="x" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<styleSheet ' . $main . '><cellXfs><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs></styleSheet>');
        $strings = ['Datum', 'Wertstellung', 'Person', 'Zweck', 'Anmerkung', 'Start', 'Massoud', 'Grillen', 'Kilian', 'Pfand', 'Beleg fehlt', '01.02.-28.02.', 'Teilnehmer', 'Gesamt'];
        $zip->addFromString('xl/sharedStrings.xml', '<sst ' . $main . '>' . implode('', array_map(static fn (string $s): string => '<si><t>' . $s . '</t></si>', $strings)) . '</sst>');
        $s = static fn (string $ref, int $i): string => '<c r="' . $ref . '" t="s"><v>' . $i . '</v></c>';
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet ' . $main . '><sheetData>'
            . '<row r="1">' . $s('B1', 0) . $s('C1', 1) . $s('D1', 2) . $s('E1', 3) . $s('F1', 4) . '</row>'
            . '<row r="2"><c r="B2" s="1"><v>46050</v></c><c r="C2"><v>-99</v></c>' . $s('D2', 6) . $s('E2', 7) . '</row>'
            . '<row r="3"><c r="B3" s="1"><v>46051</v></c><c r="C3"><v>-140.38000000000002</v></c>' . $s('D3', 8) . $s('E3', 9) . $s('F3', 10) . '</row>'
            . '<row r="4">' . $s('A4', 5) . '<c r="C4"><v>2227.59</v></c></row>'
            . '<row r="5">' . $s('B5', 11) . '<c r="C5"><v>6384</v></c>' . $s('D5', 12) . '</row>'
            . '<row r="6">' . $s('A6', 13) . '<c r="C6"><f>SUM(C2:C5)</f><v>8372.21</v></c></row>'
            . '</sheetData></worksheet>');
        $zip->close();
        return $path;
    }
}
