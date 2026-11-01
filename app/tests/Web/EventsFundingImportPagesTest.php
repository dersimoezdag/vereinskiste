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
        $this->loginAs(['board']);
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

        $this->assertSame(422, $this->postForm('/imports/bookings/1/confirm', ['version' => '2', 'date' => ['5' => 'kaputt']])->getStatusCode());
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM bookings')->fetchColumn());
        $done = $this->postForm('/imports/bookings/1/confirm', ['version' => '2', 'date' => ['5' => '2026-02-15'], 'event_map' => ['0' => 'new']]);
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
        $this->assertSame(302, $this->postForm('/imports/bookings/1/confirm', ['version' => '2', 'skip' => ['2' => '1']])->getStatusCode());
        $this->assertSame(2, (int) $this->pdo->query('SELECT count(*) FROM bookings')->fetchColumn());
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
