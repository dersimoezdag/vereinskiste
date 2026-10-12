<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Reports;

use Vereinskiste\Reports\CsvExporter;
use Vereinskiste\Reports\PdfExporter;
use Vereinskiste\Reports\SummaryService;
use Vereinskiste\Tests\TestCase;

final class ExportTest extends TestCase
{
    use ReportFixtures;

    protected function setUp(): void
    {
        $this->migrated();
    }

    /** @param iterable<string> $chunks */
    private function csvRows(iterable $chunks): array
    {
        $all = '';
        foreach ($chunks as $chunk) {
            $all .= $chunk;
        }
        $this->assertStringStartsWith("\xEF\xBB\xBF", $all);
        $rows = [];
        $h = fopen('php://memory', 'w+');
        fwrite($h, substr($all, 3));
        rewind($h);
        while (($row = fgetcsv($h, 0, ';', '"', '')) !== false) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function test_csv_has_german_header_decimal_comma_and_all_statuses(): void
    {
        $u = $this->user();
        $a = $this->account();
        $cat = $this->category('Spenden');
        $this->booking($a, $u, '2026-03-01', 'in', '1234.50', 'Spende; "groß"', $cat, 'regular', 'active', 'Müller');
        $this->booking($a, $u, '2026-03-02', 'out', '5.00', 'Storniert', null, 'correction', 'cancelled');
        $this->booking($a, $u, '2025-03-02', 'out', '9.00', 'Außerhalb');

        $rows = $this->csvRows((new CsvExporter($this->pdo))->bookings(['from' => '2026-01-01', 'to' => '2026-12-31']));

        $this->assertCount(3, $rows);
        $this->assertSame(['ID', 'Datum', 'Konto', 'Richtung', 'Betrag (EUR)', 'Kategorie', 'Beschreibung', 'Gegenpartei', 'Belegnummer', 'Typ', 'Status'], $rows[0]);
        $this->assertSame(['2026-03-01', 'Barkasse', 'Einnahme', '1234,50', 'Spenden', 'Spende; "groß"', 'Müller', '', 'Regulär', 'Aktiv'], array_slice($rows[1], 1));
        $this->assertSame(['2026-03-02', 'Barkasse', 'Ausgabe', '5,00', '', 'Storniert', '', '', 'Korrektur', 'Storniert'], array_slice($rows[2], 1));
    }

    public function test_csv_neutralizes_formula_injection(): void
    {
        $u = $this->user();
        $a = $this->account('=Konto');
        $this->booking($a, $u, '2026-03-01', 'in', '1.00', '=HYPERLINK("http://evil")', null, 'regular', 'active', '@SUM(1)');
        $this->booking($a, $u, '2026-03-02', 'in', '1.00', '+1', null);
        $this->booking($a, $u, '2026-03-03', 'in', '1.00', '-1', null);
        $this->booking($a, $u, '2026-03-04', 'in', '1.00', "\tTab", null);
        $this->booking($a, $u, '2026-03-05', 'in', '1.00', 'Normal = fein', null);

        $rows = $this->csvRows((new CsvExporter($this->pdo))->bookings([]));

        $this->assertSame("'=Konto", $rows[1][2]);
        $this->assertSame("'=HYPERLINK(\"http://evil\")", $rows[1][6]);
        $this->assertSame("'@SUM(1)", $rows[1][7]);
        $this->assertSame("'+1", $rows[2][6]);
        $this->assertSame("'-1", $rows[3][6]);
        $this->assertSame("'\tTab", $rows[4][6]);
        $this->assertSame('Normal = fein', $rows[5][6]);
        $this->assertSame('1,00', $rows[1][4], 'amounts are never prefixed');
    }

    public function test_csv_is_streamed_for_5000_bookings_within_memory_limit(): void
    {
        $u = $this->user();
        $a = $this->account();
        $this->pdo->exec(
            "INSERT INTO bookings (account_id, booking_date, amount, direction, description, counterparty, created_by)
             SELECT $a, DATE '2026-01-01' + (g % 300), (g % 1000) + 0.25, CASE WHEN g % 2 = 0 THEN 'in' ELSE 'out' END,
                    'Buchung Nummer ' || g || repeat(' x', 40), 'Gegenpartei ' || g, $u
               FROM generate_series(1, 5000) g"
        );

        $lines = 0;
        $bytes = 0;
        $first = null;
        foreach ((new CsvExporter($this->pdo))->bookings([]) as $chunk) {
            $first ??= $chunk;
            $lines += substr_count($chunk, "\n");
            $bytes += strlen($chunk);
        }

        $this->assertStringStartsWith("\xEF\xBB\xBF", $first);
        $this->assertSame(5001, $lines);
        $this->assertGreaterThan(500_000, $bytes);
        $this->assertLessThan(64 * 1024 * 1024, memory_get_peak_usage(true));
    }

    public function test_csv_keyset_paging_loses_no_rows_across_page_boundaries(): void
    {
        $u = $this->user();
        $a = $this->account();
        for ($i = 0; $i < 7; $i++) {
            $this->booking($a, $u, '2026-03-01', 'in', '1.00', 'Gleiches Datum ' . $i);
        }
        $exporter = new CsvExporter($this->pdo, 3);
        $rows = $this->csvRows($exporter->bookings([]));
        $this->assertCount(8, $rows);
        $this->assertSame(7, count(array_unique(array_column(array_slice($rows, 1), 0))));
    }

    public function test_pdf_starts_with_pdf_header_and_contains_totals(): void
    {
        $u = $this->user();
        $a = $this->account();
        $cat = $this->category('Spenden');
        $this->booking($a, $u, '2026-03-01', 'in', '1234.50', 'Spende', $cat);
        $this->booking($a, $u, '2026-03-02', 'out', '34.50', 'Porto', null);
        $this->booking($a, $u, '2026-03-03', 'out', '2.00', 'Korrektur: Größe (ä ö ü ß)', null, 'correction');

        $summary = (new SummaryService($this->pdo))->summary('2026-01-01', '2026-12-31');
        $pdf = (new PdfExporter())->summary($summary, 'Verein Müller & Söhne ä ö ü ß €');

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString("1.234,50\xA0\x80", $pdf, 'income in cp1252 with euro sign');
        $this->assertStringContainsString("1.198,00\xA0\x80", $pdf, 'balance');
        $this->assertStringContainsString("\xE4 \xF6 \xFC \xDF \x80", $pdf, 'umlauts and euro in the club name');
        $this->assertStringContainsString('Korrekturbuchungen', $pdf);
        $this->assertStringContainsString("Gr\xF6\xDFe", $pdf, 'correction description is listed');
        $this->assertStringContainsString('01.01.2026', $pdf);
        $this->assertStringContainsString('Ohne Kategorie', $pdf);
    }

    public function test_pdf_survives_unmappable_characters_and_long_text(): void
    {
        $summary = [
            'from' => '2026-01-01', 'to' => '2026-12-31', 'account_id' => null, 'account_name' => null,
            'income' => '0.00', 'expense' => '0.00', 'balance' => '0.00', 'accountBalanceAtEnd' => null,
            'byCategory' => [],
            'corrections' => array_map(static fn (int $i): array => [
                'id' => $i, 'booking_date' => '2026-02-01', 'amount' => '1.00', 'direction' => 'in',
                'description' => str_repeat('Lang ł 漢 ', 40), 'status' => 'active', 'account_name' => 'Barkasse',
                'category_name' => null,
            ], range(1, 80)),
        ];
        $pdf = (new PdfExporter())->summary($summary, 'Łódź');
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1, preg_match_all('~/Type /Page\b~', $pdf), 'multiple pages');
    }
}
