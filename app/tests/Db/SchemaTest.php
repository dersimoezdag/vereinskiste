<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Db;

use PDO;
use PDOException;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Tests\TestCase;

final class SchemaTest extends TestCase
{
    private function migrated(): PDO
    {
        $pdo = $this->pdo();
        (new Migrator($pdo, __DIR__ . '/../../migrations'))->migrate();
        return $pdo;
    }

    /** @return array{0: PDO, 1: int} PDO und ID einer Buchung */
    private function withBooking(): array
    {
        $pdo = $this->migrated();
        $pdo->exec("INSERT INTO users (email, name, password_hash) VALUES ('a@example.org', 'A', 'x')");
        $pdo->exec("INSERT INTO accounts (name, type, opening_balance, opening_date) VALUES ('Kasse', 'cash', 0, '2026-01-01')");
        $pdo->exec(
            "INSERT INTO bookings (account_id, booking_date, amount, direction, description, created_by)
             VALUES (1, '2026-01-02', 10.00, 'in', 'Spende', 1)"
        );
        return [$pdo, 1];
    }

    private function assertRejected(PDO $pdo, string $sql, string $sqlState): void
    {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            $this->assertSame($sqlState, $e->errorInfo[0] ?? null, $e->getMessage());
            return;
        }
        $this->fail("Statement wurde nicht abgelehnt: $sql");
    }

    public function test_delete_on_bookings_is_rejected(): void
    {
        [$pdo, $id] = $this->withBooking();
        $this->assertRejected($pdo, "DELETE FROM bookings WHERE id = $id", '23001');
        $this->assertSame(1, (int) $pdo->query('SELECT count(*) FROM bookings')->fetchColumn());
    }

    public function test_update_and_delete_on_booking_changes_is_rejected(): void
    {
        [$pdo, $id] = $this->withBooking();
        $pdo->exec(
            "INSERT INTO booking_changes (booking_id, changed_by, changes, reason)
             VALUES ($id, 1, '{\"amount\":{\"old\":\"10.00\",\"new\":\"12.00\"}}', 'Tippfehler')"
        );
        $this->assertRejected($pdo, "UPDATE booking_changes SET reason = 'andere' WHERE booking_id = $id", '23001');
        $this->assertRejected($pdo, "DELETE FROM booking_changes WHERE booking_id = $id", '23001');
    }

    public function test_audit_log_is_immutable(): void
    {
        $pdo = $this->migrated();
        $pdo->exec("INSERT INTO audit_log (action, detail) VALUES ('login', '{}')");
        $this->assertRejected($pdo, "UPDATE audit_log SET action = 'x'", '23001');
        $this->assertRejected($pdo, 'DELETE FROM audit_log', '23001');
    }

    public function test_amount_must_be_positive(): void
    {
        [$pdo] = $this->withBooking();
        foreach (['0', '-5.00'] as $amount) {
            $this->assertRejected(
                $pdo,
                "INSERT INTO bookings (account_id, booking_date, amount, direction, description, created_by)
                 VALUES (1, '2026-01-03', $amount, 'out', 'x', 1)",
                '23514'
            );
        }
    }

    public function test_booking_changes_requires_non_empty_reason(): void
    {
        [$pdo, $id] = $this->withBooking();
        foreach (["''", "'   '"] as $reason) {
            $this->assertRejected(
                $pdo,
                "INSERT INTO booking_changes (booking_id, changed_by, changes, reason)
                 VALUES ($id, 1, '{}', $reason)",
                '23514'
            );
        }
        $this->assertRejected(
            $pdo,
            "INSERT INTO booking_changes (booking_id, changed_by, changes) VALUES ($id, 1, '{}')",
            '23502'
        );
    }

    public function test_correction_without_original_booking_is_allowed(): void
    {
        [$pdo] = $this->withBooking();
        $pdo->exec(
            "INSERT INTO bookings (account_id, booking_date, amount, direction, description, kind, created_by)
             VALUES (1, '2026-01-04', 2.50, 'out', 'Kassendifferenz', 'correction', 1)"
        );
        $row = $pdo->query("SELECT kind, correction_of_id FROM bookings WHERE kind = 'correction'")->fetch();
        $this->assertSame('correction', $row['kind']);
        $this->assertNull($row['correction_of_id']);
    }
}
