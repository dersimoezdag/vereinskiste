<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Ledger\BookingRepository;
use Vereinskiste\Ledger\BookingService;
use Vereinskiste\Tests\Receipts\ReceiptFiles;
use Vereinskiste\Tests\TestCase;

final class ReceiptsPagesTest extends TestCase
{
    use ReceiptFiles;

    private PDO $pdo;
    private App $app;
    private string $dir;
    private int $cash;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->dir = $this->scratchDir() . '/receipts';
        $this->app = Kernel::create(['require_https' => false, 'security_dir' => $this->securityDir(), 
            'pdo' => $this->pdo, 'session_store' => new ArraySessionStore(), 'receipts_dir' => $this->dir,
        ]);
        $this->cash = (int) $this->pdo->query(
            "INSERT INTO accounts (name, type, opening_date) VALUES ('Barkasse', 'cash', '2026-01-01') RETURNING id"
        )->fetchColumn();
    }

    protected function tearDown(): void
    {
        $this->cleanScratch();
    }

    private function get(string $path): ResponseInterface
    {
        return $this->app->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
    }

    /**
     * @param array<string,string> $body
     * @param array<string,array{path:string,name:string,error?:int}> $files
     */
    private function post(string $path, array $body = [], array $files = []): ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', $path)
            ->withParsedBody(['_csrf' => $this->csrf()] + $body);
        $uploads = [];
        foreach ($files as $field => $f) {
            $error = $f['error'] ?? UPLOAD_ERR_OK;
            $uploads[$field] = new UploadedFile(
                $f['path'], $f['name'], 'application/octet-stream',
                $error === UPLOAD_ERR_OK ? filesize($f['path']) : 0, $error
            );
        }
        return $this->app->handle($req->withUploadedFiles($uploads));
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

    /** @return array<string,string> */
    private function form(): array
    {
        return [
            'account_id' => (string) $this->cash, 'booking_date' => '2026-03-05', 'amount' => '12,50',
            'direction' => 'out', 'category_id' => '', 'description' => 'Raummiete', 'counterparty' => '', 'receipt_no' => '',
        ];
    }

    private function booking(int $userId): int
    {
        return (new BookingService($this->pdo))->create($this->form(), $userId)['id'];
    }

    private function upload(int $bookingId, string $bytes, string $name, string $field = 'receipt'): ResponseInterface
    {
        return $this->post('/bookings/' . $bookingId . '/receipts', [], [
            $field => ['path' => $this->tmpFile($bytes, 'u.bin'), 'name' => $name],
        ]);
    }

    private function receiptId(): int
    {
        return (int) $this->pdo->query('SELECT max(id) FROM receipts')->fetchColumn();
    }

    public function test_upload_stores_receipt_and_detail_lists_it_with_history(): void
    {
        $uid = $this->loginAs(['kassenwart']);
        $id = $this->booking($uid);

        $res = $this->upload($id, $this->pdfBytes(), 'Rechnung <1>.pdf');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/bookings/' . $id . '#receipts', $res->getHeaderLine('Location'));

        $html = (string) $this->get('/bookings/' . $id)->getBody();
        $this->assertStringContainsString('id="receipts"', $html);
        $this->assertStringContainsString('Rechnung &lt;1&gt;.pdf', $html);
        $this->assertStringContainsString('href="/receipts/' . $this->receiptId() . '"', $html);
        $this->assertStringContainsString('Beleg hinzugefügt', $html);
        $this->assertStringContainsString('Beleg wurde gespeichert.', $html);
    }

    public function test_detail_offers_file_and_camera_inputs_for_writers_only(): void
    {
        $uid = $this->loginAs(['kassenwart']);
        $id = $this->booking($uid);

        $html = (string) $this->get('/bookings/' . $id)->getBody();
        $this->assertStringContainsString('action="/bookings/' . $id . '/receipts"', $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('accept="image/jpeg,image/png,application/pdf"', $html);
        $this->assertStringContainsString('capture="environment"', $html);

        $this->assertStringContainsString('enctype="multipart/form-data"', (string) $this->get('/bookings/new')->getBody());
    }

    public function test_detail_hides_upload_for_read_only_user(): void
    {
        $uid = $this->loginAs(['kassenpruefer']);
        $id = $this->booking($uid);

        $html = (string) $this->get('/bookings/' . $id)->getBody();
        $this->assertStringContainsString('id="receipts"', $html);
        $this->assertStringNotContainsString('/receipts"', $html);
        $this->assertStringNotContainsString('capture=', $html);
    }

    public function test_camera_field_is_accepted(): void
    {
        $uid = $this->loginAs(['kassenwart']);
        $id = $this->booking($uid);

        $res = $this->upload($id, $this->jpegBytes(), 'foto.jpg', 'receipt_camera');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM receipts')->fetchColumn());
    }

    public function test_upload_requires_write_permission(): void
    {
        $uid = $this->loginAs(['kassenpruefer']);
        $id = $this->booking($uid);

        $res = $this->upload($id, $this->pdfBytes(), 'a.pdf');
        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM receipts')->fetchColumn());
    }

    public function test_upload_without_file_and_php_upload_errors_show_german_messages(): void
    {
        $uid = $this->loginAs(['kassenwart']);
        $id = $this->booking($uid);

        $res = $this->post('/bookings/' . $id . '/receipts');
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Bitte wählen Sie eine Datei aus.', (string) $res->getBody());

        $cases = [
            UPLOAD_ERR_NO_FILE => 'Bitte wählen Sie eine Datei aus.',
            UPLOAD_ERR_INI_SIZE => 'höchstens 10 MB',
            UPLOAD_ERR_FORM_SIZE => 'höchstens 10 MB',
            UPLOAD_ERR_PARTIAL => 'nur teilweise hochgeladen',
            UPLOAD_ERR_NO_TMP_DIR => 'Der Upload ist fehlgeschlagen',
        ];
        foreach ($cases as $code => $text) {
            $res = $this->post('/bookings/' . $id . '/receipts', [], ['receipt' => ['path' => '', 'name' => 'a.pdf', 'error' => $code]]);
            $this->assertSame(422, $res->getStatusCode(), (string) $code);
            $this->assertStringContainsString($text, (string) $res->getBody(), (string) $code);
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM receipts')->fetchColumn());
    }

    public function test_invalid_file_types_get_specific_messages(): void
    {
        $uid = $this->loginAs(['kassenwart']);
        $id = $this->booking($uid);

        $res = $this->upload($id, "MZ\x90\x00\x03", 'virus.pdf');
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Nur PDF, JPEG und PNG', (string) $res->getBody());

        $res = $this->upload($id, $this->heicBytes(), 'IMG_0001.heic');
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('HEIC-Format werden nicht unterstützt', (string) $res->getBody());
        $this->assertStringContainsString('Kompatibelste', (string) $res->getBody());

        $res = $this->upload($id, $this->pngBytes(10000, 5000), 'riesig.png');
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Megapixel', (string) $res->getBody());
    }

    public function test_post_max_size_exceeded_gives_hint_instead_of_csrf_error(): void
    {
        $this->loginAs(['kassenwart']);
        // PHP verwirft bei Ueberschreitung von post_max_size Body und Dateien: _csrf fehlt dann ebenfalls.
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/bookings/1/receipts')
            ->withHeader('Content-Type', 'multipart/form-data; boundary=x')
            ->withHeader('Content-Length', '9999999999999')
            ->withParsedBody([]);
        $res = $this->app->handle($req);

        $this->assertSame(413, $res->getStatusCode());
        $this->assertStringContainsString('zu groß', (string) $res->getBody());
        $this->assertStringContainsString('10 MB', (string) $res->getBody());
    }

    public function test_download_requires_permission(): void
    {
        $uid = $this->loginAs(['kassenwart']);
        $id = $this->booking($uid);
        $this->upload($id, $this->pdfBytes('geheim'), 'rechnung.pdf');
        $rid = $this->receiptId();

        // Eingeloggt ohne Leserecht: 403.
        $this->pdo->exec("DELETE FROM user_roles WHERE user_id = {$uid}");
        $res = $this->get('/receipts/' . $rid);
        $this->assertSame(403, $res->getStatusCode());
        $this->assertStringNotContainsString('geheim', (string) $res->getBody());

        // Mit Leserecht: Download.
        $this->pdo->prepare("INSERT INTO user_roles (user_id, role) VALUES (?, 'kassenpruefer')")->execute([$uid]);
        $this->assertSame(200, $this->get('/receipts/' . $rid)->getStatusCode());
    }

    public function test_download_requires_login(): void
    {
        $res = $this->get('/receipts/1');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/login', $res->getHeaderLine('Location'));
    }

    public function test_download_sends_safe_headers_and_original_bytes(): void
    {
        $uid = $this->loginAs(['kassenpruefer']);
        $id = $this->booking($uid);
        $bytes = $this->pdfBytes('inhalt');
        (new \Vereinskiste\Receipts\ReceiptStore($this->pdo, $this->dir))
            ->add($id, $this->tmpFile($bytes), "Quittung \"März\"\r\n.pdf", $uid);

        $res = $this->get('/receipts/' . $this->receiptId());
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('application/pdf', $res->getHeaderLine('Content-Type'));
        $this->assertSame('nosniff', $res->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame((string) strlen($bytes), $res->getHeaderLine('Content-Length'));
        $this->assertStringContainsString("default-src 'none'", $res->getHeaderLine('Content-Security-Policy'));
        $disposition = $res->getHeaderLine('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
        $this->assertStringContainsString("filename*=UTF-8''Quittung%20%22M%C3%A4rz%22.pdf", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
        $this->assertSame($bytes, (string) $res->getBody());
    }

    public function test_download_unknown_receipt_is_404(): void
    {
        $this->loginAs(['kassenpruefer']);
        $this->assertSame(404, $this->get('/receipts/9999')->getStatusCode());
    }

    public function test_replace_flow_marks_old_receipt_replaced_but_downloadable(): void
    {
        $uid = $this->loginAs(['kassenwart']);
        $id = $this->booking($uid);
        $this->upload($id, $this->pdfBytes('alt'), 'alt.pdf');
        $old = $this->receiptId();

        $res = $this->post('/receipts/' . $old . '/replace', ['reason' => 'Scan war unleserlich'], [
            'receipt' => ['path' => $this->tmpFile($this->jpegBytes(), 'n.bin'), 'name' => 'neu.jpg'],
        ]);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/bookings/' . $id . '#receipts', $res->getHeaderLine('Location'));

        $html = (string) $this->get('/bookings/' . $id)->getBody();
        $this->assertStringContainsString('ersetzt', $html);
        $this->assertStringContainsString('Scan war unleserlich', $html);
        $this->assertMatchesRegularExpression('/alt\.pdf\s*→\s*neu\.jpg/u', $html);
        $this->assertStringContainsString('href="/receipts/' . $old . '"', $html);
        // Nur der aktuelle Beleg bietet "Ersetzen" an.
        $this->assertStringNotContainsString('action="/receipts/' . $old . '/replace"', $html);
        $this->assertStringContainsString('action="/receipts/' . $this->receiptId() . '/replace"', $html);

        $dl = $this->get('/receipts/' . $old);
        $this->assertSame(200, $dl->getStatusCode());
        $this->assertStringContainsString('alt', (string) $dl->getBody());
    }

    public function test_replace_without_reason_is_rejected(): void
    {
        $uid = $this->loginAs(['kassenwart']);
        $id = $this->booking($uid);
        $this->upload($id, $this->pdfBytes(), 'alt.pdf');
        $old = $this->receiptId();

        $res = $this->post('/receipts/' . $old . '/replace', ['reason' => '  '], [
            'receipt' => ['path' => $this->tmpFile($this->jpegBytes(), 'n.bin'), 'name' => 'neu.jpg'],
        ]);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Bitte geben Sie einen Grund an.', (string) $res->getBody());
        $this->assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM receipts')->fetchColumn());
    }

    public function test_replace_requires_write_permission(): void
    {
        $uid = $this->loginAs(['kassenwart']);
        $id = $this->booking($uid);
        $this->upload($id, $this->pdfBytes(), 'alt.pdf');
        $old = $this->receiptId();
        $this->pdo->exec("UPDATE user_roles SET role = 'kassenpruefer' WHERE user_id = {$uid}");

        $res = $this->post('/receipts/' . $old . '/replace', ['reason' => 'x'], [
            'receipt' => ['path' => $this->tmpFile($this->jpegBytes(), 'n.bin'), 'name' => 'neu.jpg'],
        ]);
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_create_booking_with_receipt_in_one_step(): void
    {
        $this->loginAs(['kassenwart']);

        $res = $this->post('/bookings/new', $this->form(), [
            'receipt' => ['path' => $this->tmpFile($this->pdfBytes(), 'u.bin'), 'name' => 'beleg.pdf'],
        ]);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM bookings')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM receipts')->fetchColumn());
    }

    public function test_create_booking_without_receipt_still_works(): void
    {
        $this->loginAs(['kassenwart']);

        $res = $this->post('/bookings/new', $this->form(), [
            'receipt' => ['path' => '', 'name' => '', 'error' => UPLOAD_ERR_NO_FILE],
        ]);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM receipts')->fetchColumn());
    }

    public function test_create_booking_with_invalid_receipt_creates_nothing(): void
    {
        $this->loginAs(['kassenwart']);

        $res = $this->post('/bookings/new', $this->form(), [
            'receipt' => ['path' => $this->tmpFile("MZ\x90\x00", 'u.bin'), 'name' => 'x.pdf'],
        ]);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Nur PDF, JPEG und PNG', (string) $res->getBody());
        $this->assertStringContainsString('Raummiete', (string) $res->getBody());
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM bookings')->fetchColumn());
    }

    public function test_both_file_fields_filled_is_rejected(): void
    {
        $uid = $this->loginAs(['kassenwart']);
        $id = $this->booking($uid);

        $res = $this->post('/bookings/' . $id . '/receipts', [], [
            'receipt' => ['path' => $this->tmpFile($this->pdfBytes(), 'a.bin'), 'name' => 'a.pdf'],
            'receipt_camera' => ['path' => $this->tmpFile($this->jpegBytes(), 'b.bin'), 'name' => 'b.jpg'],
        ]);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('nur eine Datei', (string) $res->getBody());
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM receipts')->fetchColumn());
    }

    public function test_pdf_with_appended_html_is_served_as_safe_attachment(): void
    {
        $uid = $this->loginAs(['kassenwart']);
        $id = $this->booking($uid);
        $bytes = $this->pdfBytes('<html><script>alert(1)</script></html>');
        $this->assertSame(302, $this->upload($id, $bytes, 'evil.html')->getStatusCode());

        $this->assertSame('application/pdf', $this->pdo->query('SELECT mime FROM receipts')->fetchColumn());
        $res = $this->get('/receipts/' . $this->receiptId());
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('application/pdf', $res->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('attachment;', $res->getHeaderLine('Content-Disposition'));
        $this->assertSame('nosniff', $res->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringContainsString("default-src 'none'", $res->getHeaderLine('Content-Security-Policy'));
    }

    public function test_receipt_of_cancelled_booking_is_downloadable_and_addable(): void
    {
        $uid = $this->loginAs(['kassenwart']);
        $id = $this->booking($uid);
        $this->upload($id, $this->pdfBytes(), 'a.pdf');
        $this->pdo->exec("UPDATE bookings SET status = 'cancelled' WHERE id = {$id}");

        $this->assertSame(200, $this->get('/receipts/' . $this->receiptId())->getStatusCode());
        $this->assertSame(302, $this->upload($id, $this->jpegBytes(), 'b.jpg')->getStatusCode());
    }

    public function test_receipt_posts_without_valid_csrf_are_forbidden(): void
    {
        $uid = $this->loginAs(['kassenwart']);
        $id = $this->booking($uid);
        $this->upload($id, $this->pdfBytes(), 'a.pdf');
        $rid = $this->receiptId();
        $file = new UploadedFile($this->tmpFile($this->pdfBytes(), 'c.bin'), 'c.pdf', 'application/pdf', 10, UPLOAD_ERR_OK);

        $targets = ['/bookings/' . $id . '/receipts' => [], '/receipts/' . $rid . '/replace' => ['reason' => 'x']];
        foreach ($targets as $path => $body) {
            foreach ([$body, $body + ['_csrf' => 'falsch']] as $parsed) {
                $req = (new ServerRequestFactory())->createServerRequest('POST', $path)
                    ->withParsedBody($parsed)->withUploadedFiles(['receipt' => $file]);
                $this->assertSame(403, $this->app->handle($req)->getStatusCode(), $path);
            }
        }
        $this->assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM receipts')->fetchColumn());
    }

    public function test_create_with_receipt_is_not_marked_changed_but_later_add_is(): void
    {
        $this->loginAs(['kassenwart']);
        $res = $this->post('/bookings/new', $this->form(), [
            'receipt' => ['path' => $this->tmpFile($this->pdfBytes(), 'u.bin'), 'name' => 'beleg.pdf'],
        ]);
        $this->assertSame(302, $res->getStatusCode());
        $id = (int) $this->pdo->query('SELECT max(id) FROM bookings')->fetchColumn();
        $repo = new BookingRepository($this->pdo);

        $this->assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM receipts')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM booking_changes')->fetchColumn());
        $this->assertFalse($repo->find($id)['changed']);

        $this->upload($id, $this->jpegBytes(), 'spaeter.jpg');
        $this->assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM booking_changes')->fetchColumn());
        $this->assertTrue($repo->find($id)['changed']);
    }
}
