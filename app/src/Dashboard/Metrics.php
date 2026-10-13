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
            'members' => ['value' => $this->count('SELECT count(*) FROM members WHERE anonymized_at IS NULL'), 'description' => \Vereinskiste\Text\Catalogue::get('home.metric.mitglieder_insgesamt_ohne_anonymisierte_datensaetze')],
            'applications' => ['value' => $this->count("SELECT count(*) FROM membership_applications WHERE status='pending'"), 'description' => \Vereinskiste\Text\Catalogue::get('home.metric.antraege_warten_auf_eine_entscheidung')],
            'finance' => $this->finance(),
            'invoices' => ['value' => $this->count("SELECT count(*) FROM invoices WHERE status='draft'"), 'description' => \Vereinskiste\Text\Catalogue::get('home.metric.entwuerfe_warten_auf_pruefung')],
            'contacts' => ['value' => $this->count('SELECT count(*) FROM finance_contacts WHERE active'), 'description' => \Vereinskiste\Text\Catalogue::get('home.metric.aktive_personen_und_unternehmen')],
            'operations' => ['value' => $this->count("SELECT count(*) FROM mail_outbox WHERE status IN ('failed','uncertain')"), 'description' => \Vereinskiste\Text\Catalogue::get('home.metric.fehlgeschlagene_oder_unklare_zustellungen')],
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
        $row=(new \Vereinskiste\Reports\BookingAggregation($this->pdo))->totals($from,$today->format('Y-m-d'));
        return ['value' => MoneyFormat::eur($row['balance']),
            'description' => \Vereinskiste\Text\Catalogue::get('home.metric.saldo_im_geschaeftsjahr_bis_heute_from_to',['{from}'=>(new \DateTimeImmutable($from))->format('d.m.Y'),'{to}'=>$today->format('d.m.Y')]),
            'details' => [\Vereinskiste\Text\Catalogue::get('reports.income') => MoneyFormat::eur($row['income']), \Vereinskiste\Text\Catalogue::get('reports.expense') => MoneyFormat::eur($row['expense'])]];
    }
}
