<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Foundation;

use PDO;
use Vereinskiste\Club\Profile;
use Vereinskiste\Contacts\ContactRepository;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\History;
use Vereinskiste\Finance\AccountBank;
use Vereinskiste\Finance\BankDetails;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\BookingService;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Tests\TestCase;

final class FoundationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = $this->pdo();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->db->exec("INSERT INTO users(name,email,password_hash) VALUES ('Admin','admin@example.org','hash')");
        $this->db->exec("INSERT INTO accounts(name,type,opening_date) VALUES ('Kasse','cash','2026-01-01')");
    }

    public function test_profile_retains_old_name_saves_atomically_and_rejects_stale_or_bad_input(): void
    {
        $this->db->exec("INSERT INTO settings(key,value) VALUES ('club_name','Alter Verein')");
        $profile = new Profile($this->db);
        $this->assertSame('Alter Verein', $profile->name());
        $input = $profile->get()['data']; $input['name'] = 'Neuer Verein'; $input['website_url'] = 'https://verein.example.org';
        $profile->save($input, 1, 1, 'Neue Anschrift');
        $this->assertSame('Neuer Verein', $profile->name());
        $this->assertSame('Neuer Verein', $this->db->query("SELECT value FROM settings WHERE key='club_name'")->fetchColumn());
        $this->assertCount(1, (new History($this->db))->for('club', 1));
        $profile->save($input, 2, 1, 'Unverändert');
        $this->assertSame(2, $profile->get()['version']);
        $year = array_replace($input, ['year_start' => '07-01']);
        $profile->save($year, 2, 1, 'Geschäftsjahr');
        $this->assertSame(['from' => '2025-07-01', 'to' => '2026-06-30'], $profile->period(new \DateTimeImmutable('2026-01-01')));
        foreach (['http://insecure.example.org', 'https://user:password@example.org'] as $url) {
            try { $profile->save(array_replace($input, ['website_url' => $url]), 3, 1, 'Test'); $this->fail('URL abweisen'); }
            catch (ValidationError $e) { $this->assertArrayHasKey('website_url', $e->errors); }
        }
        $this->expectException(Conflict::class); $profile->save($input, 1, 1, 'Veraltet');
    }

    public function test_contact_snapshot_and_history_survive_rename_archive_and_booking_update(): void
    {
        $repo = new ContactRepository($this->db);
        $id = $repo->save(null, ['name' => 'Lieferant', 'kind' => 'company'], 0, 1, 'Anlage');
        $service = new BookingService($this->db);
        $booking = $service->create(['account_id' => '1', 'booking_date' => '2026-10-12', 'amount' => '10,00',
            'direction' => 'out', 'description' => 'Rechnung', 'finance_contact_id' => (string) $id], 1);
        $this->assertSame('Lieferant', $booking['counterparty']);
        $repo->save($id, ['name' => 'Neuer Name', 'kind' => 'company', 'active' => ''], 1, 1, 'Archiviert');
        $updated = $service->update($booking['id'], ['description' => 'Nachtrag'], 'Details', 1, 1);
        $this->assertSame('Lieferant', $updated['counterparty']);
        $this->assertSame($id, $updated['finance_contact_id']);
        $this->assertCount(2, (new History($this->db))->for('contact', $id));
        try { $repo->save($id, ['name' => 'Überholt', 'kind' => 'person'], 1, 1, 'Alt'); $this->fail('Konflikt'); }
        catch (Conflict) { $this->assertSame('Neuer Name', $repo->find($id)['name']); }
        $this->expectException(ValidationError::class);
        $service->create(['account_id' => '1', 'booking_date' => '2026-10-12', 'amount' => '10,00',
            'direction' => 'out', 'description' => 'Rechnung', 'finance_contact_id' => (string) $id], 1);
    }

    public function test_invalid_reason_rolls_back_contact_and_history_and_bank_data_are_optional(): void
    {
        (new ContactRepository($this->db))->save(null, ['name' => 'X', 'kind' => 'person'], 0, 1, '');
        $this->assertSame(1, (int) $this->db->query('SELECT count(*) FROM finance_contacts')->fetchColumn());
        $bank = new AccountBank($this->db);
        $bank->save(1, '', '', 1, 1, 'Barkasse');
        $bank->save(1, 'DE89 3704 0044 0532 0130 00', 'COBADEFFXXX', 1, 1, 'Bankdaten');
        $this->assertSame('DE89370400440532013000', $bank->get(1)['iban']);
        $this->assertFalse(BankDetails::validIban('DE89370400440532013001'));
        $this->expectException(ValidationError::class); $bank->save(1, 'wrong', '', 2, 1, 'Ungültig');
    }
}
