<?php
declare(strict_types=1);
namespace Vereinskiste\Dashboard;

use PDO;
use Vereinskiste\Club\Profile;
use Vereinskiste\Ledger\MoneyFormat;

/** Nur für bereits autorisierte, ausgewählte Karten aufrufen. */
final class Metrics
{
    public function __construct(private PDO $pdo) {}

    public function forCard(string $id): array
    {
        return match ($id) {
            'members' => ['value' => $this->count('SELECT count(*) FROM members WHERE anonymized_at IS NULL'), 'description' => 'Mitglieder insgesamt, ohne anonymisierte Datensätze'],
            'applications' => ['value' => $this->count("SELECT count(*) FROM membership_applications WHERE status='pending'"), 'description' => 'Anträge warten auf eine Entscheidung'],
            'finance' => $this->finance(),
            'invoices' => ['value' => $this->count("SELECT count(*) FROM invoices WHERE status='draft'"), 'description' => 'Entwürfe warten auf Prüfung'],
            'contacts' => ['value' => $this->count('SELECT count(*) FROM finance_contacts WHERE active'), 'description' => 'Aktive Personen und Unternehmen'],
            'operations' => ['value' => $this->count("SELECT count(*) FROM mail_outbox WHERE status IN ('failed','uncertain')"), 'description' => 'Fehlgeschlagene oder unklare Zustellungen'],
            default => throw new \InvalidArgumentException('Unknown dashboard card'),
        };
    }

    private function count(string $sql): string
    {
        return number_format((int) $this->pdo->query($sql)->fetchColumn(), 0, ',', '.');
    }

    private function finance(): array
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Berlin'));
        $from = (new Profile($this->pdo))->period($today)['from'];
        $stmt = $this->pdo->prepare("SELECT
            COALESCE(SUM(amount) FILTER(WHERE direction='in'),0)::text AS income,
            COALESCE(SUM(amount) FILTER(WHERE direction='out'),0)::text AS expense,
            COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE -amount END),0)::text AS balance
            FROM bookings WHERE status='active' AND kind<>'transfer' AND booking_date BETWEEN ? AND ?");
        $stmt->execute([$from, $today->format('Y-m-d')]);
        $row = $stmt->fetch();
        return ['value' => MoneyFormat::eur($row['balance']),
            'description' => 'Saldo im Geschäftsjahr bis heute · ' . (new \DateTimeImmutable($from))->format('d.m.Y') . ' – ' . $today->format('d.m.Y'),
            'details' => ['Einnahmen' => MoneyFormat::eur($row['income']), 'Ausgaben' => MoneyFormat::eur($row['expense'])]];
    }
}
