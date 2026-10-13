<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\BankImport;

use PDO;
use Vereinskiste\BankImport\ConfirmService;
use Vereinskiste\BankImport\ParseService;
use Vereinskiste\BankImport\Repository;
use Vereinskiste\BankImport\ReviewService;
use Vereinskiste\BankImport\Upload;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Tests\Receipts\ReceiptFiles;
use Vereinskiste\Tests\TestCase;

final class ImportTest extends TestCase
{
    use ReceiptFiles;
    private PDO $db;
    protected function setUp(): void
    {
        $this->db = $this->pdo();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->db->exec("INSERT INTO users(name,email,password_hash) VALUES ('Admin','admin@example.org','hash')");
        $this->db->exec("INSERT INTO accounts(name,type,opening_date,iban) VALUES ('Bank','bank','2026-01-01','DE89370400440532013000')");
    }
    protected function tearDown(): void { $this->cleanScratch(); }
    private function mapping(bool $reference = false): array
    {
        return ['booking_date' => '0','value_date' => '1','amount' => '2','currency' => '3','counterparty' => '4',
            'iban' => '5','purpose' => '6','bank_reference' => '7','account_iban' => '8','date_format' => 'd.m.Y',
            'decimal_format' => 'comma','account_checked' => '1','reference_unique' => $reference ? '1' : ''];
    }
    private function upload(string $rows): int
    {
        return (new Upload($this->db, $this->scratchDir() . '/documents'))->create(1,
            $this->tmpFile("Datum;Valuta;Betrag;Währung;Name;IBAN;Zweck;ID;Konto\n" . $rows), 'bank.csv', 1);
    }
    private function parse(int $id, bool $reference = false): void
    {
        $service = new ParseService($this->db, $this->scratchDir() . '/documents');
        $service->configure($id, 1, $this->mapping($reference), 1);
        while ((new Repository($this->db))->find($id)['status'] === 'parsing') { $service->step($id); }
    }
    private function confirm(int $id): void
    {
        (new ConfirmService($this->db))->step($id, (int) (new Repository($this->db))->find($id)['version'], 1);
    }
    public function test_same_file_is_reused_and_two_identical_real_payments_require_review(): void
    {
        $line = "02.01.2026;03.01.2026;-12,50;EUR;Müller;;Beitrag;NOTPROVIDED;DE89370400440532013000\n";
        $id = $this->upload($line . $line);
        $this->assertSame($id, $this->upload($line . $line));
        $this->assertSame(1, (int) $this->db->query('SELECT count(*) FROM stored_files')->fetchColumn());
        $this->parse($id, true); $this->confirm($id);
        $repo = new Repository($this->db);
        $this->assertSame('review', $repo->find($id)['status']);
        $this->assertSame(1, (int) $repo->summary($id)['adopted']);
        $this->assertSame('pending', $repo->rows($id)[1]['decision']);
        (new ReviewService($this->db))->decide($id, (int) $repo->find($id)['version'], 2,
            ['decision' => 'new','reason' => 'Zwei echte Zahlungen'], 1);
        $this->confirm($id); $this->confirm($id);
        $this->assertSame('confirmed', $repo->find($id)['status']);
        $this->assertSame(2, (int) $this->db->query('SELECT count(*) FROM bank_transactions')->fetchColumn());
        $this->assertSame(0, (int) $this->db->query('SELECT count(*) FROM bookings')->fetchColumn());
    }
    public function test_bank_reference_duplicates_cannot_be_forced_as_new_and_resolve_to_existing(): void
    {
        $id = $this->upload("02.01.2026;;10,00;EUR;Müller;;Beitrag;BANK-1;\n"); $this->parse($id, true); $this->confirm($id);
        $other = $this->upload("03.01.2026;;10,00;EUR;Müller;;Nachtrag;BANK-1;\n"); $this->parse($other, true); $this->confirm($other);
        $repo = new Repository($this->db); $review = new ReviewService($this->db);
        $review->decide($other, (int) $repo->find($other)['version'], 1, ['decision' => 'new','reason' => 'Versuch'], 1);
        $this->confirm($other);
        $this->assertSame('pending', $repo->rows($other)[0]['decision']);
        $review->decide($other, (int) $repo->find($other)['version'], 1,
            ['decision' => 'duplicate','reason' => 'Bank-ID identisch','duplicate_id' => '1'], 1);
        $this->confirm($other);
        $this->assertSame('confirmed', $repo->find($other)['status']);
        $this->assertSame(1, (int) $this->db->query('SELECT count(*) FROM bank_transactions')->fetchColumn());
    }
    public function test_invalid_currency_date_amount_or_account_blocks_until_excluded(): void
    {
        $id = $this->upload("31.02.2026;;falsch;USD;Name;;Zweck;;GB82WEST12345698765432\n"); $this->parse($id);
        $repo = new Repository($this->db);
        $this->assertContains('bank.row.date', $repo->rows($id)[0]['issues']);
        $this->assertContains('bank.row.currency', $repo->rows($id)[0]['issues']);
        $this->assertContains('bank.row.account', $repo->rows($id)[0]['issues']);
        try { $this->confirm($id); $this->fail('Ungültige Zeilen blockieren'); } catch (ValidationError) {}
        $this->assertSame(0, (int) $this->db->query('SELECT count(*) FROM bank_transactions')->fetchColumn());
        (new ReviewService($this->db))->decide($id, (int) $repo->find($id)['version'], 1, ['decision' => 'excluded','reason' => 'Falsche Exportzeile'], 1);
        $this->confirm($id); $this->assertSame('confirmed', $repo->find($id)['status']);
    }
    public function test_batches_resume_and_stale_confirmation_is_rejected(): void
    {
        $lines = '';
        for ($i = 0; $i < 501; $i++) { $lines .= "02.01.2026;;1,00;EUR;Name;;Zweck-$i;;\n"; }
        $id = $this->upload($lines); $this->parse($id);
        $repo = new Repository($this->db); $old = (int) $repo->find($id)['version'];
        $this->confirm($id); $this->assertSame(250, (int) $repo->summary($id)['adopted']);
        try { (new ConfirmService($this->db))->step($id, $old, 1); $this->fail('Version'); } catch (Conflict) {}
        $this->confirm($id); $this->confirm($id);
        $this->assertSame(501, (int) $repo->summary($id)['adopted']);
        $this->assertSame('confirmed', $repo->find($id)['status']);
    }
    public function test_reset_keeps_previous_proposals_and_queues_a_new_revision(): void
    {
        $id = $this->upload("02.01.2026;;1,00;EUR;Name;;Zweck;;\n"); $this->parse($id);
        $repo = new Repository($this->db);
        (new ReviewService($this->db))->reset($id, (int) $repo->find($id)['version'], 'Format prüfen', 1);
        $this->assertSame([], $repo->rows($id));
        $this->assertSame(1, (int) $this->db->query('SELECT count(*) FROM bank_import_rows WHERE NOT is_current')->fetchColumn());
        $service = new ParseService($this->db, $this->scratchDir() . '/documents');
        $service->configure($id, (int) $repo->find($id)['version'], $this->mapping(), 1); $service->step($id);
        $this->assertCount(1, $repo->rows($id));
        $this->assertSame(2, (int) $this->db->query("SELECT count(*) FROM jobs WHERE kind='bank_csv_parse'")->fetchColumn());
        $this->confirm($id);
        $this->expectException(Conflict::class);
        (new ReviewService($this->db))->reset($id, (int) $repo->find($id)['version'], 'Nicht erlaubt', 1);
    }
    public function test_parallel_connections_serialize_the_same_accounts_confirmation(): void
    {
        $first = $this->upload("02.01.2026;;10,00;EUR;Name;;Zweck;BANK-1;\n"); $this->parse($first, true);
        $second = $this->upload("02.01.2026;;10,00;EUR;Name;;Nachtrag;BANK-1;\n"); $this->parse($second, true);
        $repo = new Repository($this->db); $other = $this->connect(); $other->exec("SET lock_timeout='100ms'");
        $this->db->beginTransaction(); $this->db->query('SELECT id FROM accounts WHERE id=1 FOR UPDATE');
        try {
            try {
                (new ConfirmService($other))->step($second, (int) $repo->find($second)['version'], 1);
                $this->fail('Zweite Verbindung muss an der Kontosperre warten');
            } catch (\PDOException $e) { $this->assertSame('55P03', $e->getCode()); }
            $this->confirm($first); $this->db->commit();
        } finally { if ($this->db->inTransaction()) { $this->db->rollBack(); } }
        (new ConfirmService($other))->step($second, (int) $repo->find($second)['version'], 1);
        $this->assertSame('review', $repo->find($second)['status']);
        $this->assertSame(1, (int) $this->db->query('SELECT count(*) FROM bank_transactions')->fetchColumn());
    }
}
