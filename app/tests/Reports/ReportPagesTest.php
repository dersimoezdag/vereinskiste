<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Reports;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Tests\TestCase;

final class ReportPagesTest extends TestCase
{
    use ReportFixtures;

    private App $app;

    protected function setUp(): void
    {
        $this->migrated();
        $this->app = Kernel::create(['pdo' => $this->pdo, 'session_store' => new ArraySessionStore()]);
    }

    /** @param array<string,string> $query */
    private function get(string $path, array $query = []): ResponseInterface
    {
        return $this->app->handle((new ServerRequestFactory())->createServerRequest('GET', $path)->withQueryParams($query));
    }

    /** @param string[] $roles */
    private function loginAs(array $roles): int
    {
        $id = $this->user();
        foreach ($roles as $role) {
            $this->pdo->prepare('INSERT INTO user_roles (user_id, role) VALUES (?, ?)')->execute([$id, $role]);
        }
        $html = (string) $this->get('/login')->getBody();
        $this->assertSame(1, preg_match('/name="_csrf" value="([^"]+)"/', $html, $m));
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/login')
            ->withParsedBody(['_csrf' => $m[1], 'email' => 'wer@example.org', 'password' => 'richtig-richtig']);
        $this->assertSame(302, $this->app->handle($req)->getStatusCode());
        return $id;
    }

    /** @return string[] */
    public static function routes(): array
    {
        return [['/reports/summary'], ['/reports/bookings.csv'], ['/reports/summary.pdf']];
    }

    #[DataProvider('routes')]
    public function test_reports_require_login_and_reports_read(string $path): void
    {
        $this->assertSame(302, $this->get($path)->getStatusCode());
        $this->loginAs(['schriftfuehrer']);
        $this->assertSame(403, $this->get($path)->getStatusCode());
    }

    public function test_summary_page_shows_totals_categories_and_export_links_with_same_filter(): void
    {
        $u = $this->loginAs(['kassenpruefer']);
        $a = $this->account('Barkasse', '100.00');
        $cat = $this->category('Spenden');
        $this->booking($a, $u, '2026-03-01', 'in', '1234.50', 'Spende', $cat);
        $this->booking($a, $u, '2026-03-02', 'out', '34.50', 'Porto');

        $res = $this->get('/reports/summary', ['from' => '2026-01-01', 'to' => '2026-12-31', 'account_id' => (string) $a]);
        $html = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('1.234,50', $html);
        $this->assertStringContainsString('34,50', $html);
        $this->assertStringContainsString('Spenden', $html);
        $this->assertStringContainsString('Ohne Kategorie', $html);
        $this->assertStringContainsString('<option value="' . $a . '" selected>', $html);
        $this->assertStringContainsString('1.300,00', $html, 'closing balance of the selected account');
        $this->assertMatchesRegularExpression(
            '~href="/reports/bookings\.csv\?from=2026-01-01&amp;to=2026-12-31&amp;account_id=' . $a . '"~',
            $html
        );
        $this->assertMatchesRegularExpression('~href="/reports/summary\.pdf\?from=2026-01-01&amp;to=2026-12-31&amp;account_id=' . $a . '"~', $html);
    }

    public function test_summary_defaults_to_current_calendar_year(): void
    {
        $this->loginAs(['kassenwart']);
        $html = (string) $this->get('/reports/summary')->getBody();
        $year = date('Y');
        $this->assertStringContainsString('value="' . $year . '-01-01"', $html);
        $this->assertStringContainsString('value="' . $year . '-12-31"', $html);
    }

    public function test_swapped_range_and_invalid_date_show_validation_error(): void
    {
        $this->loginAs(['kassenwart']);
        $res = $this->get('/reports/summary', ['from' => '2026-12-31', 'to' => '2026-01-01']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('nicht nach dem Enddatum', (string) $res->getBody());

        $res = $this->get('/reports/summary', ['from' => 'kaputt']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('gültiges Datum', (string) $res->getBody());

        $this->assertSame(422, $this->get('/reports/summary.pdf', ['from' => '2026-12-31', 'to' => '2026-01-01'])->getStatusCode());
        $this->assertSame(422, $this->get('/reports/summary', ['account_id' => '99999'])->getStatusCode());
    }

    public function test_csv_download_headers_and_content(): void
    {
        $u = $this->loginAs(['kassenpruefer']);
        $a = $this->account();
        $this->booking($a, $u, '2026-03-01', 'in', '12.50', 'Spende');
        $this->booking($a, $u, '2025-03-01', 'in', '99.00', 'Vorjahr');

        $res = $this->get('/reports/bookings.csv', ['from' => '2026-01-01', 'to' => '2026-12-31']);
        $body = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('text/csv; charset=utf-8', $res->getHeaderLine('Content-Type'));
        $this->assertSame('attachment; filename="buchungen_2026-01-01_2026-12-31.csv"', $res->getHeaderLine('Content-Disposition'));
        $this->assertSame('nosniff', $res->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringStartsWith("\xEF\xBB\xBFID;Datum;", $body);
        $this->assertStringContainsString(';12,50;', $body);
        $this->assertStringNotContainsString('Vorjahr', $body);
    }

    public function test_pdf_download_headers_use_club_name_from_settings(): void
    {
        $u = $this->loginAs(['kassenwart']);
        $a = $this->account();
        $this->booking($a, $u, '2026-03-01', 'in', '12.50', 'Spende');
        $this->pdo->exec("INSERT INTO settings (key, value) VALUES ('club_name', 'Turnverein Äpfelstadt')");

        $res = $this->get('/reports/summary.pdf', ['from' => '2026-01-01', 'to' => '2026-12-31']);
        $body = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('application/pdf', $res->getHeaderLine('Content-Type'));
        $this->assertSame('attachment; filename="uebersicht_2026-01-01_2026-12-31.pdf"', $res->getHeaderLine('Content-Disposition'));
        $this->assertSame('nosniff', $res->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringStartsWith('%PDF-', $body);
        $this->assertStringContainsString("Turnverein \xC4pfelstadt", $body);
    }

    public function test_pdf_falls_back_to_generic_club_name(): void
    {
        $this->loginAs(['kassenwart']);
        $body = (string) $this->get('/reports/summary.pdf')->getBody();
        $this->assertStringContainsString('(Verein)', $body);
    }

    public function test_nav_links_reports_for_readers_only(): void
    {
        $this->loginAs(['schriftfuehrer']);
        $this->assertStringNotContainsString('href="/reports/summary"', (string) $this->get('/')->getBody());
    }

    public function test_nav_shows_reports_entry_with_permission(): void
    {
        $this->loginAs(['kassenpruefer']);
        $this->assertStringContainsString('href="/reports/summary"', (string) $this->get('/')->getBody());
    }
}
