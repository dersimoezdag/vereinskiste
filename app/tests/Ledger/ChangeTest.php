<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Ledger;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\AccountRepository;
use Vereinskiste\Ledger\BookingLocked;
use Vereinskiste\Ledger\BookingNotFound;
use Vereinskiste\Ledger\BookingRepository;
use Vereinskiste\Ledger\BookingService;
use Vereinskiste\Ledger\ChangeLog;
use Vereinskiste\Ledger\TransferService;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Ledger\VersionConflict;
use Vereinskiste\Tests\TestCase;

final class ChangeTest extends TestCase
{
    private PDO $pdo;
    private BookingService $service;
    private ChangeLog $log;
    private int $userId;
    private int $cash;
    private int $bank;
    private int $rent;
    private int $postage;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->service = new BookingService($this->pdo);
        $this->log = new ChangeLog($this->pdo);
        $this->userId = (int) $this->pdo->query(
            "INSERT INTO users (email, name, password_hash) VALUES ('k@example.org', 'Kassenwart', 'x') RETURNING id"
        )->fetchColumn();
        $this->cash = (int) $this->pdo->query(
            "INSERT INTO accounts (name, type, opening_date) VALUES ('Barkasse', 'cash', '2026-01-01') RETURNING id"
        )->fetchColumn();
        $this->bank = (int) $this->pdo->query(
            "INSERT INTO accounts (name, type, opening_date) VALUES ('Girokonto', 'bank', '2026-01-01') RETURNING id"
        )->fetchColumn();
        $this->rent = (int) $this->pdo->query("INSERT INTO categories (name) VALUES ('Miete') RETURNING id")->fetchColumn();
        $this->postage = (int) $this->pdo->query("INSERT INTO categories (name) VALUES ('Porto') RETURNING id")->fetchColumn();
    }

    /**
     * Formularfoermige Eingabe (alles Strings, deutsches Komma).
     *
     * @param array<string,string> $override
     * @return array<string,string>
     */
    private function form(array $override = []): array
    {
        return $override + [
            'account_id' => (string) $this->cash, 'booking_date' => '2026-03-05', 'amount' => '12,5',
            'direction' => 'in', 'category_id' => (string) $this->rent, 'description' => 'Mitgliedsbeitrag',
            'counterparty' => '', 'receipt_no' => 'R-1',
        ];
    }

    /** @return array<string,mixed> */
    private function booking(array $override = []): array
    {
        return $this->service->create($this->form($override), $this->userId);
    }

    private function changeCount(): int
    {
        return (int) $this->pdo->query('SELECT count(*) FROM booking_changes')->fetchColumn();
    }

    public function test_update_writes_change_entry_with_old_and_new_value_and_reason(): void
    {
        $booking = $this->booking();

        $updated = $this->service->update(
            $booking['id'],
            $this->form(['amount' => '15', 'category_id' => (string) $this->postage, 'counterparty' => 'Frau Beispiel']),
            '  Tippfehler im Betrag  ',
            1,
            $this->userId
        );

        $this->assertSame(2, $updated['version']);
        $this->assertSame('15.00', $updated['amount']);
        $this->assertSame($this->postage, $updated['category_id']);
        $this->assertSame('Frau Beispiel', $updated['counterparty']);

        $history = $this->log->forBooking($booking['id']);
        $this->assertCount(1, $history);
        $this->assertSame($this->userId, $history[0]['changed_by']);
        $this->assertSame('Kassenwart', $history[0]['changed_by_name']);
        $this->assertNotEmpty($history[0]['changed_at']);
        $this->assertSame('Tippfehler im Betrag', $history[0]['reason']);
        $this->assertSame([
            ['field' => 'amount', 'from' => '12.50', 'to' => '15.00'],
            ['field' => 'category_id', 'from' => $this->rent, 'to' => $this->postage],
            ['field' => 'counterparty', 'from' => null, 'to' => 'Frau Beispiel'],
        ], $history[0]['changes']);
        $this->assertTrue((new BookingRepository($this->pdo))->find($booking['id'])['changed']);
    }

    public function test_update_without_reason_is_rejected(): void
    {
        $booking = $this->booking();

        foreach (['', "   \n "] as $reason) {
            try {
                $this->service->update($booking['id'], $this->form(['amount' => '99']), $reason, 1, $this->userId);
                $this->fail('ValidationError erwartet');
            } catch (ValidationError $e) {
                $this->assertSame(['reason' => 'validation.reason.required'], $e->errors);
            }
        }

        $row = (new BookingRepository($this->pdo))->find($booking['id']);
        $this->assertSame('12.50', $row['amount']);
        $this->assertSame(1, $row['version']);
        $this->assertSame(0, $this->changeCount());
    }

    public function test_update_reports_field_errors_together_with_missing_reason(): void
    {
        $booking = $this->booking();
        try {
            $this->service->update($booking['id'], $this->form(['amount' => '0', 'booking_date' => '0000-01-01']), '', 1, $this->userId);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertEqualsCanonicalizing(['amount', 'booking_date', 'reason'], array_keys($e->errors));
        }
        $this->assertSame(0, $this->changeCount());
    }

    public function test_stale_version_from_second_connection_conflicts_and_changes_nothing(): void
    {
        $booking = $this->booking();
        $alice = new BookingService($this->connect());
        $bob = new BookingService($this->connect());

        // Beide haben Version 1 geladen; Alice speichert zuerst (eigene Verbindung, committet).
        $alice->update($booking['id'], $this->form(['description' => 'Von Alice']), 'Alice korrigiert', 1, $this->userId);

        try {
            $bob->update($booking['id'], $this->form(['description' => 'Von Bob']), 'Bob korrigiert', 1, $this->userId);
            $this->fail('VersionConflict erwartet');
        } catch (VersionConflict) {
        }

        $row = (new BookingRepository($this->connect()))->find($booking['id']);
        $this->assertSame('Von Alice', $row['description']);
        $this->assertSame(2, $row['version']);
        $this->assertSame(1, $this->changeCount());
        $this->assertSame('Alice korrigiert', $this->log->forBooking($booking['id'])[0]['reason']);
        // Bobs Verbindung ist nicht in einer offenen Transaktion haengen geblieben.
        $this->assertSame(3, $bob->update($booking['id'], $this->form(['description' => 'Von Bob']), 'Bob neu geladen', 2, $this->userId)['version']);
    }

    public function test_update_noop_creates_no_entry(): void
    {
        $booking = $this->booking(['counterparty' => '']);

        // Dieselben Werte, nur anders geschrieben (Komma, Leerzeichen, String-IDs).
        $same = $this->service->update(
            $booking['id'],
            $this->form(['amount' => ' 12,50 ', 'description' => ' Mitgliedsbeitrag ', 'counterparty' => '  ']),
            'Nur angesehen',
            1,
            $this->userId
        );

        $this->assertSame(1, $same['version']);
        $this->assertSame(0, $this->changeCount());
        $this->assertFalse($same['changed']);
    }

    public function test_noop_update_with_stale_version_still_conflicts(): void
    {
        $booking = $this->booking();
        $this->service->update($booking['id'], $this->form(['amount' => '13']), 'Betrag', 1, $this->userId);

        $this->expectException(VersionConflict::class);
        $this->service->update($booking['id'], $this->form(['amount' => '13']), 'Betrag', 1, $this->userId);
    }

    public function test_update_only_touches_given_fields_and_ignores_unknown_keys(): void
    {
        $booking = $this->booking();

        $updated = $this->service->update(
            $booking['id'],
            ['description' => 'Neu', 'kind' => 'transfer', 'status' => 'cancelled', 'version' => '99', 'created_by' => '0'],
            'Beschreibung präzisiert',
            1,
            $this->userId
        );

        $this->assertSame('Neu', $updated['description']);
        $this->assertSame('12.50', $updated['amount']);
        $this->assertSame('regular', $updated['kind']);
        $this->assertSame('active', $updated['status']);
        $this->assertSame(2, $updated['version']);
        $this->assertSame(
            [['field' => 'description', 'from' => 'Mitgliedsbeitrag', 'to' => 'Neu']],
            $this->log->forBooking($booking['id'])[0]['changes']
        );
    }

    public function test_unchanged_inactive_account_and_category_do_not_block_other_edits(): void
    {
        $booking = $this->booking();
        $this->pdo->exec('UPDATE accounts SET active = false WHERE id = ' . $this->cash);
        $this->pdo->exec('UPDATE categories SET active = false WHERE id = ' . $this->rent);

        $updated = $this->service->update($booking['id'], $this->form(['description' => 'Neu']), 'Text', 1, $this->userId);
        $this->assertSame(2, $updated['version']);

        try {
            $this->service->update($booking['id'], $this->form(['account_id' => (string) $this->bank, 'category_id' => '999']), 'x', 2, $this->userId);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertSame(['category_id' => 'validation.booking.category'], $e->errors);
        }
        $this->pdo->exec('UPDATE accounts SET active = false WHERE id = ' . $this->bank);
        try {
            $this->service->update($booking['id'], $this->form(['account_id' => (string) $this->bank]), 'Konto', 2, $this->userId);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertSame(['account_id' => 'validation.booking.account'], $e->errors);
        }
    }

    public function test_update_of_unknown_booking_is_not_found(): void
    {
        $this->expectException(BookingNotFound::class);
        $this->service->update(4711, $this->form(), 'Grund', 1, $this->userId);
    }

    public function test_cancel_and_restore_each_create_change_entry(): void
    {
        $booking = $this->booking();
        $accounts = new AccountRepository($this->pdo);
        $this->assertSame('12.50', $accounts->balance($this->cash));

        $cancelled = $this->service->cancel($booking['id'], 'Doppelt erfasst', 1, $this->userId);
        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertSame(2, $cancelled['version']);
        $this->assertSame('0.00', $accounts->balance($this->cash));

        $restored = $this->service->restore($booking['id'], 'Doch nicht doppelt', 2, $this->userId);
        $this->assertSame('active', $restored['status']);
        $this->assertSame(3, $restored['version']);
        $this->assertSame('12.50', $accounts->balance($this->cash));

        $history = $this->log->forBooking($booking['id']);
        $this->assertCount(2, $history);
        $this->assertSame([['field' => 'status', 'from' => 'active', 'to' => 'cancelled']], $history[0]['changes']);
        $this->assertSame('Doppelt erfasst', $history[0]['reason']);
        $this->assertSame([['field' => 'status', 'from' => 'cancelled', 'to' => 'active']], $history[1]['changes']);
        $this->assertSame('Doch nicht doppelt', $history[1]['reason']);
    }

    public function test_cancel_requires_reason_and_current_version(): void
    {
        $booking = $this->booking();
        try {
            $this->service->cancel($booking['id'], ' ', 1, $this->userId);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertSame(['reason' => 'validation.reason.required'], $e->errors);
        }
        try {
            $this->service->cancel($booking['id'], 'Grund', 7, $this->userId);
            $this->fail('VersionConflict erwartet');
        } catch (VersionConflict) {
        }
        $this->assertSame('active', (new BookingRepository($this->pdo))->find($booking['id'])['status']);
        $this->assertSame(0, $this->changeCount());
    }

    public function test_cancelled_booking_cannot_be_edited_or_cancelled_again(): void
    {
        $booking = $this->booking();
        $this->service->cancel($booking['id'], 'Storno', 1, $this->userId);

        foreach ([
            fn () => $this->service->update($booking['id'], $this->form(['amount' => '1']), 'x', 2, $this->userId),
            fn () => $this->service->cancel($booking['id'], 'nochmal', 2, $this->userId),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('BookingLocked erwartet');
            } catch (BookingLocked $e) {
                $this->assertNotSame('', $e->textKey);
            }
        }
        $this->assertSame(1, $this->changeCount());
    }

    public function test_correction_is_visible_booking_of_kind_correction(): void
    {
        $original = $this->booking();

        $correction = $this->service->createCorrection(
            $this->form(['amount' => '2,50', 'direction' => 'out', 'description' => '', 'category_id' => '']),
            $original['id'],
            'Betrag war 2,50 € zu hoch',
            $this->userId
        );

        $this->assertSame('correction', $correction['kind']);
        $this->assertSame('active', $correction['status']);
        $this->assertSame($original['id'], $correction['correction_of_id']);
        $this->assertSame('Betrag war 2,50 € zu hoch', $correction['description']);
        $this->assertSame('2.50', $correction['amount']);
        $this->assertFalse($correction['changed'], 'Anlage-Eintrag zaehlt nicht als Aenderung');

        $repo = new BookingRepository($this->pdo);
        $this->assertContains($correction['id'], array_column($repo->search([], 50, 0), 'id'));
        $this->assertSame('10.00', (new AccountRepository($this->pdo))->balance($this->cash));

        $history = $this->log->forBooking($correction['id']);
        $this->assertCount(1, $history);
        $this->assertSame('Betrag war 2,50 € zu hoch', $history[0]['reason']);
        $this->assertSame([['field' => 'created', 'from' => null, 'to' => 'correction']], $history[0]['changes']);
        // Original bleibt unveraendert und ohne Verlauf
        $this->assertSame(1, $repo->find($original['id'])['version']);
        $this->assertSame([], $this->log->forBooking($original['id']));
    }

    public function test_correction_without_original_and_with_unknown_original(): void
    {
        $diff = $this->service->createCorrection(
            $this->form(['description' => 'Kassendifferenz', 'direction' => 'out', 'amount' => '0,30', 'category_id' => '']),
            null,
            'Kassenzählung 31.03.',
            $this->userId
        );
        $this->assertNull($diff['correction_of_id']);
        $this->assertSame('Kassendifferenz', $diff['description']);

        try {
            $this->service->createCorrection($this->form(), 4711, 'Grund', $this->userId);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertSame(['correction_of_id' => 'validation.correction.original'], $e->errors);
        }
        try {
            $this->service->createCorrection($this->form(), null, '  ', $this->userId);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertSame(['reason' => 'validation.reason.required'], $e->errors);
        }
        $this->assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM bookings WHERE kind = 'correction'")->fetchColumn());
    }

    public function test_transfer_creates_two_linked_bookings(): void
    {
        $legs = (new TransferService($this->pdo))->create(
            $this->cash, $this->bank, '100,00', '2026-03-10', 'Bargeld eingezahlt', $this->userId
        );

        $this->assertCount(2, $legs);
        [$out, $in] = $legs;
        $this->assertSame($this->cash, $out['account_id']);
        $this->assertSame('out', $out['direction']);
        $this->assertSame($this->bank, $in['account_id']);
        $this->assertSame('in', $in['direction']);
        foreach ($legs as $leg) {
            $this->assertSame('transfer', $leg['kind']);
            $this->assertSame('100.00', $leg['amount']);
            $this->assertSame('2026-03-10', $leg['booking_date']);
            $this->assertSame('Bargeld eingezahlt', $leg['description']);
            $this->assertNull($leg['category_id']);
            $this->assertSame(1, $leg['version']);
        }
        $this->assertNotNull($out['transfer_id']);
        $this->assertSame($out['transfer_id'], $in['transfer_id']);

        $accounts = new AccountRepository($this->pdo);
        $this->assertSame('-100.00', $accounts->balance($this->cash));
        $this->assertSame('100.00', $accounts->balance($this->bank));

        $second = (new TransferService($this->pdo))->create($this->bank, $this->cash, '1', '2026-03-11', 'Zurück', $this->userId);
        $this->assertNotSame($out['transfer_id'], $second[0]['transfer_id']);
    }

    public function test_transfer_validates_accounts_amount_date_and_description(): void
    {
        $transfers = new TransferService($this->pdo);
        try {
            $transfers->create($this->cash, $this->cash, '10', '2026-03-10', 'x', $this->userId);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertSame(['to_account_id' => 'validation.transfer.same_account'], $e->errors);
        }

        $this->pdo->exec('UPDATE accounts SET active = false WHERE id = ' . $this->bank);
        try {
            $transfers->create(4711, $this->bank, '0', '0000-01-01', " \xFF", $this->userId);
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertSame([
                'from_account_id' => 'validation.booking.account',
                'to_account_id' => 'validation.booking.account',
                'amount' => 'validation.booking.amount',
                'booking_date' => 'validation.date.invalid',
                'description' => 'validation.text.encoding',
            ], $e->errors);
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM bookings')->fetchColumn());
    }

    public function test_transfer_legs_change_only_together_via_transfer_service(): void
    {
        $transfers = new TransferService($this->pdo);
        [$out, $in] = $transfers->create($this->cash, $this->bank, '50', '2026-03-10', 'Einzahlung', $this->userId);

        foreach ([
            fn () => $this->service->update($out['id'], ['amount' => '60'], 'x', 1, $this->userId),
            fn () => $this->service->cancel($in['id'], 'x', 1, $this->userId),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('BookingLocked erwartet');
            } catch (BookingLocked $e) {
                $this->assertSame('bookings.locked.transfer', $e->textKey);
            }
        }

        $legs = $transfers->cancel($in['id'], 'Falsches Konto', 1, $this->userId);
        $this->assertSame(['cancelled', 'cancelled'], array_column($legs, 'status'));
        $this->assertSame([2, 2], array_column($legs, 'version'));
        $accounts = new AccountRepository($this->pdo);
        $this->assertSame('0.00', $accounts->balance($this->cash));
        $this->assertSame('0.00', $accounts->balance($this->bank));
        foreach ([$out['id'], $in['id']] as $id) {
            $history = $this->log->forBooking($id);
            $this->assertCount(1, $history);
            $this->assertSame([['field' => 'status', 'from' => 'active', 'to' => 'cancelled']], $history[0]['changes']);
            $this->assertSame('Falsches Konto', $history[0]['reason']);
        }

        try {
            $transfers->restore($out['id'], 'Doch richtig', 1, $this->userId);
            $this->fail('VersionConflict erwartet');
        } catch (VersionConflict) {
        }
        $this->assertSame(2, $this->changeCount());

        $legs = $transfers->restore($out['id'], 'Doch richtig', 2, $this->userId);
        $this->assertSame(['active', 'active'], array_column($legs, 'status'));
        $this->assertSame('-50.00', $accounts->balance($this->cash));
        $this->assertSame(4, $this->changeCount());
    }

    public function test_change_log_record_rejects_blank_reason_and_keeps_order(): void
    {
        $booking = $this->booking();
        try {
            $this->log->record($booking['id'], $this->userId, [['field' => 'x', 'from' => 1, 'to' => 2]], ' ');
            $this->fail('ValidationError erwartet');
        } catch (ValidationError $e) {
            $this->assertSame(['reason' => 'validation.reason.required'], $e->errors);
        }

        // Mehrere Eintraege in derselben Transaktion (gleiches now()) bleiben in Einfuegereihenfolge.
        $this->pdo->beginTransaction();
        $this->log->record($booking['id'], $this->userId, [['field' => 'a', 'from' => null, 'to' => 'ä']], 'eins');
        $this->log->record($booking['id'], $this->userId, [['field' => 'b', 'from' => null, 'to' => 'b']], 'zwei');
        $this->pdo->commit();
        $this->assertSame(['eins', 'zwei'], array_column($this->log->forBooking($booking['id']), 'reason'));
        $this->assertSame('ä', $this->log->forBooking($booking['id'])[0]['changes'][0]['to']);
    }
}
