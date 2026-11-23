<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Db;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Expenses\ExpenseRepository;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Members\MemberRepository;
use Vereinskiste\Tests\TestCase;

final class ExpensePayoutTest extends TestCase
{
    private PDO $pdo;
    private ExpenseRepository $repo;
    private int $account;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->pdo->exec("INSERT INTO users (name, email, password_hash) VALUES ('Kasse', 'kasse@example.org', 'hash')");
        $this->pdo->exec("INSERT INTO users (name, email, password_hash) VALUES ('Jule', 'jule@example.org', 'hash')");
        $this->pdo->exec("INSERT INTO accounts (name, type, opening_balance, opening_date) VALUES ('Bar', 'cash', 0, '2026-01-01')");
        $this->account = (int) $this->pdo->query('SELECT id FROM accounts')->fetchColumn();
        $this->repo = new ExpenseRepository($this->pdo);
    }

    private function expenseWithBooking(int $memberId): int
    {
        $id = $this->repo->save(null, ['member_id' => (string) $memberId, 'purpose' => 'Würste', 'amount' => '48,60', 'spent_on' => '2026-02-01'], 0, 1);
        $this->repo->reimburse($id, ['account_id' => (string) $this->account, 'booking_date' => '2026-02-05', 'category_id' => ''], 1);
        return $id;
    }

    private function memberWithUser(int $userId): int
    {
        $id = (new MemberRepository($this->pdo))->save(null, ['name' => 'Jule Test', 'email' => 'jule@example.org'], 0, 1);
        $this->pdo->prepare('UPDATE users SET member_id = ? WHERE id = ?')->execute([$id, $userId]);
        return $id;
    }

    private function signatureDataUrl(): string
    {
        $img = imagecreatetruecolor(200, 80);
        imagefilledrectangle($img, 0, 0, 199, 79, imagecolorallocate($img, 255, 255, 255));
        $ink = imagecolorallocate($img, 0, 0, 0);
        for ($x = 10; $x < 190; $x += 2) { imagesetpixel($img, $x, 40 + ($x % 7), $ink); }
        for ($x = 10; $x < 190; $x += 3) { imagesetpixel($img, $x, 50, $ink); }
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);
        return 'data:image/png;base64,' . base64_encode($png);
    }

    public function test_mark_paid_out_requires_booking_and_validates_method_and_account(): void
    {
        $member = $this->memberWithUser(2);
        $open = $this->repo->save(null, ['member_id' => (string) $member, 'purpose' => 'Porto', 'amount' => '5,00', 'spent_on' => '2026-02-01'], 0, 1);
        try {
            $this->repo->markPaidOut($open, ['payout_method' => 'cash', 'payout_account_id' => (string) $this->account], 1, false);
            $this->fail('expected ValidationError');
        } catch (ValidationError $e) {
            $this->assertSame('expenses.payout_requires_booking', $e->errors['payout']);
        }

        $id = $this->expenseWithBooking($member);
        try {
            $this->repo->markPaidOut($id, ['payout_method' => 'paypal', 'payout_detail' => 'keine-mail', 'payout_account_id' => (string) $this->account], 1, false);
            $this->fail('expected ValidationError');
        } catch (ValidationError $e) {
            $this->assertSame('validation.email.invalid', $e->errors['payout_detail']);
        }
        try {
            $this->repo->markPaidOut($id, ['payout_method' => 'bank', 'payout_detail' => 'DE00370400440532013000', 'payout_account_id' => (string) $this->account], 1, false);
            $this->fail('expected ValidationError');
        } catch (ValidationError $e) {
            $this->assertSame('foundation.iban.invalid', $e->errors['payout_detail']);
        }
        try {
            $this->repo->markPaidOut($id, ['payout_method' => 'cash', 'payout_account_id' => '999'], 1, false);
            $this->fail('expected ValidationError');
        } catch (ValidationError $e) {
            $this->assertSame('foundation.invalid', $e->errors['payout_account_id']);
        }
    }

    public function test_bank_payout_stores_iban_and_counter_signature_flow(): void
    {
        $member = $this->memberWithUser(2);
        $id = $this->expenseWithBooking($member);

        // Gegenzeichnung aktiv, Empfaenger hat Login -> Auszahlung zunaechst offen gegenzeichnen
        $this->repo->markPaidOut($id, [
            'payout_method' => 'bank', 'payout_detail' => 'DE89 3704 0044 0532 0130 00', 'payout_bic' => 'COBADEFFXXX',
            'payout_account_id' => (string) $this->account,
        ], 1, true);
        $expense = $this->repo->find($id);
        $this->assertTrue($expense['paid']);
        $this->assertFalse($expense['counter_signed']);
        $this->assertSame('DE89370400440532013000 COBADEFFXXX', $expense['payout_detail']);
        $this->assertSame($this->account, (int) $expense['payout_account_id']);
        $this->assertSame('Bar', $expense['payout_account_name']);

        // Falscher Benutzer darf nicht gegenzeichnen
        try {
            $this->repo->counterSign($id, $this->signatureDataUrl(), 'Berlin', 1);
            $this->fail('expected ValidationError');
        } catch (ValidationError $e) {
            $this->assertSame('expenses.payout_not_recipient', $e->errors['payout']);
        }

        // Empfaenger zeichnet gegen
        $this->repo->counterSign($id, $this->signatureDataUrl(), 'Berlin', 2);
        $expense = $this->repo->find($id);
        $this->assertTrue($expense['counter_signed']);
        $signature = $this->repo->payoutSignature($id);
        $this->assertNotNull($signature);
        $this->assertSame('Berlin', $signature['signed_place']);
        $this->assertSame(hash('sha256', $signature['signature_png']), $signature['signature_sha256']);

        // Doppelte Gegenzeichnung abgelehnt
        try {
            $this->repo->counterSign($id, $this->signatureDataUrl(), 'Berlin', 2);
            $this->fail('expected ValidationError');
        } catch (ValidationError $e) {
            $this->assertSame('expenses.payout_already_signed', $e->errors['payout']);
        }

        // Erneutes Ausbezahlt-Markieren abgelehnt
        try {
            $this->repo->markPaidOut($id, ['payout_method' => 'cash', 'payout_account_id' => (string) $this->account], 1, false);
            $this->fail('expected ValidationError');
        } catch (ValidationError $e) {
            $this->assertSame('expenses.payout_already', $e->errors['payout']);
        }
    }

    public function test_cash_payout_without_counter_signature_is_final_and_paypal_stores_email(): void
    {
        $member = $this->memberWithUser(2);
        $id = $this->expenseWithBooking($member);
        $this->repo->markPaidOut($id, ['payout_method' => 'cash', 'payout_account_id' => (string) $this->account], 1, true);
        $expense = $this->repo->find($id);
        $this->assertTrue($expense['paid']);
        $this->assertFalse($expense['counter_signed']);
        $this->assertSame('', $expense['payout_detail']);

        $id2 = $this->expenseWithBooking($member);
        $this->repo->markPaidOut($id2, ['payout_method' => 'paypal', 'payout_detail' => 'jule@example.org', 'payout_account_id' => (string) $this->account], 1, false);
        $this->assertSame('jule@example.org', $this->repo->find($id2)['payout_detail']);
    }
}
