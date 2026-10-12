<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Reports;

use PDO;
use Vereinskiste\Db\Migrator;

/** Gemeinsame Testdaten-Helfer fuer die Berichtstests (Schema + einfache Inserts). */
trait ReportFixtures
{
    private PDO $pdo;

    private function migrated(): PDO
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        return $this->pdo;
    }

    private function user(string $email = 'wer@example.org'): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO users (email, name, password_hash) VALUES (?, ?, ?) RETURNING id');
        $stmt->execute([$email, 'Test Person', password_hash('richtig-richtig', PASSWORD_DEFAULT)]);
        return (int) $stmt->fetchColumn();
    }

    private function account(string $name = 'Barkasse', string $opening = '0', string $openingDate = '2026-01-01'): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO accounts (name, type, opening_balance, opening_date) VALUES (?, 'cash', ?, ?) RETURNING id"
        );
        $stmt->execute([$name, $opening, $openingDate]);
        return (int) $stmt->fetchColumn();
    }

    private function category(string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO categories (name) VALUES (?) RETURNING id');
        $stmt->execute([$name]);
        return (int) $stmt->fetchColumn();
    }

    private function booking(
        int $account,
        int $user,
        string $date,
        string $direction,
        string $amount,
        string $description = 'Test',
        ?int $category = null,
        string $kind = 'regular',
        string $status = 'active',
        ?string $counterparty = null,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO bookings (account_id, booking_date, amount, direction, category_id, description, counterparty, kind, status, created_by, transfer_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ' . ($kind === 'transfer' ? "nextval('booking_transfer_id_seq')" : 'NULL') . ') RETURNING id'
        );
        $stmt->execute([$account, $date, $amount, $direction, $category, $description, $counterparty, $kind, $status, $user]);
        return (int) $stmt->fetchColumn();
    }
}
