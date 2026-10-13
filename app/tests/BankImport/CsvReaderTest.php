<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\BankImport;

use Vereinskiste\BankImport\Parsers\CsvReader;
use Vereinskiste\BankImport\Parsers\CsvEntry;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Tests\Receipts\ReceiptFiles;
use Vereinskiste\Tests\TestCase;

final class CsvReaderTest extends TestCase
{
    use ReceiptFiles;
    protected function tearDown(): void { $this->cleanScratch(); }
    public function test_bom_comma_multiline_and_windows_encoding(): void
    {
        $reader = new CsvReader();
        $meta = $reader->inspect($this->tmpFile("\xEF\xBB\xBFDate,Amount,Name\r\n2026-01-01,\"1,234.56\",\"Müller\nVerein\"\r\n"));
        $this->assertSame(',', $meta['delimiter']); $this->assertSame('Date', $meta['headers'][0]);
        $this->assertSame("Müller\nVerein", $meta['preview'][0][2]);
        $meta = $reader->inspect($this->tmpFile(mb_convert_encoding("Datum;Betrag;Name\n01.01.2026;-10,50;Müller\n", 'Windows-1252', 'UTF-8')));
        $this->assertSame('Windows-1252', $meta['encoding']); $this->assertSame('Müller', $meta['preview'][0][2]);
    }
    public function test_row_limit_rejects_10001_transactions(): void
    {
        $this->expectException(ValidationError::class);
        (new CsvReader())->inspect($this->tmpFile("Date;Amount\n" . str_repeat("2026-01-01;1\n", 10001)));
    }
    public function test_explicit_international_numbers_do_not_use_floats(): void
    {
        $settings = ['mapping' => ['booking_date' => 0,'value_date' => null,'amount' => 1,'currency' => null,
            'counterparty' => null,'iban' => null,'purpose' => null,'bank_reference' => null,'account_iban' => null],
            'date_format' => 'Y-m-d','decimal_format' => 'dot','reference_unique' => false];
        $entry = (new CsvEntry())->read(['2026-01-01','-1,234.56'], $settings, ['opening_date' => '2026-01-01','iban' => '']);
        $this->assertSame('-1234.56', $entry['proposal']['amount']); $this->assertSame([], $entry['issues']);
    }
    public function test_unclosed_quoted_cell_cannot_silently_swallow_transactions(): void
    {
        $this->expectException(ValidationError::class);
        (new CsvReader())->inspect($this->tmpFile("Date;Amount;Name\n2026-01-01;10;\"Name\n2026-01-02;20;Other\n"));
    }
    public function test_empty_mapped_currency_is_not_silently_assumed_to_be_eur(): void
    {
        $settings = ['mapping' => ['booking_date' => 0,'value_date' => null,'amount' => 1,'currency' => 2,
            'counterparty' => null,'iban' => null,'purpose' => null,'bank_reference' => null,'account_iban' => null],
            'date_format' => 'Y-m-d','decimal_format' => 'dot','reference_unique' => false];
        $entry = (new CsvEntry())->read(['2026-01-01','10.00',''], $settings, ['opening_date' => '2026-01-01','iban' => '']);
        $this->assertContains('bank.row.currency', $entry['issues']);
    }
    public function test_counterparty_or_value_date_changes_do_not_hide_duplicate_candidates(): void
    {
        $settings = ['mapping' => ['booking_date' => 0,'value_date' => 1,'amount' => 2,'currency' => null,
            'counterparty' => 3,'iban' => null,'purpose' => 4,'bank_reference' => null,'account_iban' => null],
            'date_format' => 'Y-m-d','decimal_format' => 'dot','reference_unique' => false];
        $reader = new CsvEntry(); $account = ['opening_date' => '2026-01-01','iban' => ''];
        $first = $reader->read(['2026-01-01','2026-01-02','10.00','Müller','Beitrag'], $settings, $account);
        $other = $reader->read(['2026-01-01','','10.00','Müller GmbH','Beitrag'], $settings, $account);
        $this->assertSame($first['proposal']['fingerprint'], $other['proposal']['fingerprint']);
    }
}
