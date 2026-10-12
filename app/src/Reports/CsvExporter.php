<?php
declare(strict_types=1);

namespace Vereinskiste\Reports;

use PDO;
use Vereinskiste\Ledger\BookingRepository;

/**
 * CSV-Export der Buchungen (UTF-8 mit BOM, Semikolon, Dezimalkomma, ISO-Datum).
 *
 * Die Zeilen werden gestreamt: Buchungen werden seitenweise per Keyset-Paging gelesen,
 * es liegt nie mehr als eine Seite im Speicher.
 */
final class CsvExporter
{
    public const BOM = "\xEF\xBB\xBF";
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /** @var array<string,string> */
    private array $texts;

    /**
     * @param array<string,string>|null $texts Textschluessel => Text (Standard: de.php)
     * @param int $pageSize Buchungen je Datenbankabfrage
     */
    public function __construct(private PDO $pdo, private int $pageSize = 500, ?array $texts = null)
    {
        $this->texts = $texts ?? require dirname(__DIR__) . '/Text/de.php';
    }

    /**
     * @param array<string,mixed> $filter wie BookingRepository::search (from, to, account_id, ...)
     * @return \Generator<int,string> BOM+Kopfzeile, danach je Buchung eine Zeile (CRLF)
     */
    public function bookings(array $filter): \Generator
    {
        yield self::BOM . $this->line([
            $this->t('reports.csv.id'), $this->t('bookings.date'), $this->t('bookings.account'),
            $this->t('reports.csv.direction'), $this->t('bookings.amount_eur'), $this->t('bookings.category'),
            $this->t('bookings.description'), $this->t('bookings.counterparty'), $this->t('bookings.receipt_no'),
            $this->t('bookings.kind'), $this->t('bookings.status'),
        ]);

        $repo = new BookingRepository($this->pdo);
        $after = null;
        do {
            $rows = $repo->searchAfter($filter, $after, $this->pageSize);
            foreach ($rows as $b) {
                yield $this->line([
                    (string) $b['id'],
                    $b['booking_date'],
                    self::safe((string) $b['account_name']),
                    $this->t('bookings.direction.' . $b['direction']),
                    str_replace('.', ',', (string) $b['amount']),
                    self::safe((string) ($b['category_name'] ?? '')),
                    self::safe((string) $b['description']),
                    self::safe((string) ($b['counterparty'] ?? '')),
                    self::safe((string) ($b['receipt_no'] ?? '')),
                    $this->t('bookings.kind.' . $b['kind']),
                    $this->t('bookings.status.' . $b['status']),
                ]);
            }
            $last = end($rows);
            $after = $last === false ? null : [$last['booking_date'], $last['id']];
        } while (count($rows) === max(1, $this->pageSize));
    }

    /**
     * Schutz vor CSV-/Formel-Injection: Text, der mit =, +, -, @, Tab oder CR beginnt,
     * wird in Tabellenkalkulationen als Formel gelesen; ein vorangestelltes ' neutralisiert das.
     */
    public static function safe(string $text): string
    {
        return $text !== '' && in_array($text[0], self::FORMULA_PREFIXES, true) ? "'" . $text : $text;
    }

    private function t(string $key): string
    {
        return $this->texts[$key] ?? $key;
    }

    /** @param list<string> $cells */
    private function line(array $cells): string
    {
        $h = fopen('php://memory', 'w+');
        if ($h === false) {
            throw new \RuntimeException('Speicherstrom nicht verfuegbar.');
        }
        // Leeres Escape-Zeichen: nur Verdopplung von Anfuehrungszeichen (RFC 4180), kein Backslash-Escape.
        fputcsv($h, $cells, ';', '"', '');
        rewind($h);
        $line = (string) stream_get_contents($h);
        fclose($h);
        return substr($line, 0, -1) . "\r\n";
    }
}
