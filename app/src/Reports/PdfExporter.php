<?php
declare(strict_types=1);

namespace Vereinskiste\Reports;

use DateTimeImmutable;
use FPDF;
use Vereinskiste\Ledger\MoneyFormat;

/**
 * PDF der Uebersicht (A4, FPDF mit Standardschrift).
 *
 * FPDF-Kernschriften erwarten Windows-1252: Texte werden vor der Ausgabe von UTF-8 dorthin
 * umgewandelt (ae oe ue ss und das Euro-Zeichen gehen, Unbekanntes wird zu "?").
 * Die Komprimierung ist aus: Dokumente sind klein, und der Text bleibt so pruefbar.
 */
final class PdfExporter
{
    private const MARGIN = 15.0;
    private const PAGE_BOTTOM = 270.0;

    /** @var array<string,string> */
    private array $texts;

    /** @param array<string,string>|null $texts Textschluessel => Text (Standard: de.php) */
    public function __construct(?array $texts = null)
    {
        $this->texts = $texts ?? require dirname(__DIR__) . '/Text/de.php';
    }

    /**
     * @param array<string,mixed> $summary Ergebnis von SummaryService::summary()
     * @return string PDF-Bytes
     */
    public function summary(array $summary, string $clubName, ?DateTimeImmutable $now = null): string
    {
        $now ??= new DateTimeImmutable();
        $pdf = new class (self::cp($clubName), self::cp($this->t('reports.pdf.page'))) extends FPDF {
            public function __construct(private string $club, private string $pageLabel)
            {
                parent::__construct('P', 'mm', 'A4');
            }

            public function Footer(): void
            {
                $this->SetY(-12);
                $this->SetFont('Helvetica', '', 8);
                $this->SetTextColor(110);
                $this->Cell(120, 5, $this->club);
                $this->Cell(0, 5, $this->pageLabel . ' ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
            }
        };
        $pdf->SetCompression(false);
        $pdf->SetCreator('Vereinskiste');
        $pdf->SetTitle(self::cp($clubName . ' - ' . $this->t('reports.pdf.title')));
        $pdf->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AliasNbPages();
        $pdf->AddPage();

        $this->heading($pdf, $clubName, $summary, $now);
        $this->totals($pdf, $summary);
        $this->categories($pdf, $summary);
        $this->corrections($pdf, $summary);

        return $pdf->Output('S');
    }

    /** @param array<string,mixed> $s */
    private function heading(FPDF $pdf, string $club, array $s, DateTimeImmutable $now): void
    {
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->Cell(0, 9, self::cp($club), 0, 1);
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Cell(0, 7, self::cp($this->t('reports.pdf.title')), 0, 1);
        $pdf->Ln(1);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(0, 5.5, self::cp($this->t('reports.pdf.period') . ': ' . self::date($s['from']) . ' - ' . self::date($s['to'])), 0, 1);
        if ($s['account_name'] !== null) {
            $pdf->Cell(0, 5.5, self::cp($this->t('reports.pdf.account') . ': ' . $s['account_name']), 0, 1);
        }
        $pdf->Cell(0, 5.5, self::cp($this->t('reports.pdf.created') . ': ' . $now->format('d.m.Y')), 0, 1);
        $pdf->Ln(5);
    }

    /** @param array<string,mixed> $s */
    private function totals(FPDF $pdf, array $s): void
    {
        $rows = [
            [$this->t('reports.income'), $s['income'], false],
            [$this->t('reports.expense'), $s['expense'], false],
            [$this->t('reports.balance'), $s['balance'], true],
        ];
        if ($s['accountBalanceAtEnd'] !== null) {
            $rows[] = [
                $this->t('reports.account_balance_at_end') . ' (' . self::date($s['to']) . ')',
                $s['accountBalanceAtEnd'],
                true,
            ];
        }
        foreach ($rows as [$label, $amount, $bold]) {
            $pdf->SetFont('Helvetica', $bold ? 'B' : '', 11);
            $pdf->Cell(120, 7, self::cp($label), 'B');
            $pdf->Cell(60, 7, self::cp(MoneyFormat::eur($amount)), 'B', 1, 'R');
        }
        $pdf->Ln(7);
    }

    /** @param array<string,mixed> $s */
    private function categories(FPDF $pdf, array $s): void
    {
        $head = static function (FPDF $pdf, string $c, string $in, string $out): void {
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->SetFillColor(235, 238, 242);
            $pdf->Cell(90, 7, PdfExporter::cp($c), 0, 0, 'L', true);
            $pdf->Cell(45, 7, PdfExporter::cp($in), 0, 0, 'R', true);
            $pdf->Cell(45, 7, PdfExporter::cp($out), 0, 1, 'R', true);
        };
        $labels = [$this->t('reports.category'), $this->t('reports.income'), $this->t('reports.expense')];

        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->Cell(0, 8, self::cp($this->t('reports.by_category')), 0, 1);
        $head($pdf, ...$labels);
        foreach ($s['byCategory'] as $row) {
            if ($pdf->GetY() > self::PAGE_BOTTOM) {
                $pdf->AddPage();
                $head($pdf, ...$labels);
            }
            $pdf->SetFont('Helvetica', '', 10);
            $name = $row['category_id'] === null ? $this->t('reports.uncategorized') : $row['name'];
            $pdf->Cell(90, 6.5, self::cp(self::fit($pdf, $name, 88)), 'B');
            $pdf->Cell(45, 6.5, self::cp(MoneyFormat::eur($row['income'])), 'B', 0, 'R');
            $pdf->Cell(45, 6.5, self::cp(MoneyFormat::eur($row['expense'])), 'B', 1, 'R');
        }
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(90, 7, self::cp($this->t('reports.total')));
        $pdf->Cell(45, 7, self::cp(MoneyFormat::eur($s['income'])), 0, 0, 'R');
        $pdf->Cell(45, 7, self::cp(MoneyFormat::eur($s['expense'])), 0, 1, 'R');
        $pdf->Ln(8);
    }

    /** @param array<string,mixed> $s */
    private function corrections(FPDF $pdf, array $s): void
    {
        if ($pdf->GetY() > self::PAGE_BOTTOM - 30) {
            $pdf->AddPage();
        }
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->Cell(0, 8, self::cp($this->t('reports.pdf.corrections')), 0, 1);
        if ($s['corrections'] === []) {
            $pdf->SetFont('Helvetica', '', 10);
            $pdf->Cell(0, 6, self::cp($this->t('reports.pdf.no_corrections')), 0, 1);
            return;
        }

        $widths = [22.0, 32.0, 70.0, 30.0, 26.0];
        $head = function () use ($pdf, $widths): void {
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->SetFillColor(235, 238, 242);
            $cells = ['bookings.date', 'bookings.account', 'bookings.description', 'bookings.amount', 'bookings.status'];
            foreach ($cells as $i => $key) {
                $pdf->Cell($widths[$i], 6.5, self::cp($this->t($key)), 0, 0, $i === 3 ? 'R' : 'L', true);
            }
            $pdf->Ln();
        };
        $head();
        foreach ($s['corrections'] as $c) {
            if ($pdf->GetY() > self::PAGE_BOTTOM) {
                $pdf->AddPage();
                $head();
            }
            $pdf->SetFont('Helvetica', '', 9);
            $signed = ($c['direction'] === 'in' ? '+' : '-') . MoneyFormat::eur($c['amount']);
            $pdf->Cell($widths[0], 6, self::date($c['booking_date']), 'B');
            $pdf->Cell($widths[1], 6, self::cp(self::fit($pdf, $c['account_name'], $widths[1] - 2)), 'B');
            $pdf->Cell($widths[2], 6, self::cp(self::fit($pdf, $c['description'], $widths[2] - 2)), 'B');
            $pdf->Cell($widths[3], 6, self::cp($signed), 'B', 0, 'R');
            $pdf->Cell($widths[4], 6, self::cp($this->t('bookings.status.' . $c['status'])), 'B', 1);
        }
    }

    /** UTF-8 nach Windows-1252 (Kernschriften von FPDF); nicht abbildbare Zeichen werden "?". */
    public static function cp(string $text): string
    {
        // Ungueltiges UTF-8 ersetzen; Steuerzeichen (z. B. Zeilenumbrueche) duerfen die Zellen nicht umbrechen.
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        return mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }

    /** Kuerzt `$text` mit "..." so, dass er (in der aktuellen Schrift) in `$width` mm passt. */
    private static function fit(FPDF $pdf, string $text, float $width): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        if ($pdf->GetStringWidth(self::cp($text)) <= $width) {
            return $text;
        }
        $length = mb_strlen($text);
        while ($length > 1 && $pdf->GetStringWidth(self::cp(mb_substr($text, 0, $length) . '...')) > $width) {
            $length--;
        }
        return mb_substr($text, 0, $length) . '...';
    }

    private static function date(string $iso): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $iso);
        return $date === false ? $iso : $date->format('d.m.Y');
    }

    private function t(string $key): string
    {
        return $this->texts[$key] ?? $key;
    }
}
