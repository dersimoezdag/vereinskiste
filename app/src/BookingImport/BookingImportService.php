<?php
declare(strict_types=1);

namespace Vereinskiste\BookingImport;

use PDO;
use Vereinskiste\Audit\AuditLog;
use Vereinskiste\Auth\UserRepository;
use Vereinskiste\BankImport\Parsers\CsvReader;
use Vereinskiste\Events\EventRepository;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Ledger\AccountRepository;
use Vereinskiste\Ledger\BookingService;
use Vereinskiste\Ledger\ClosedPeriod;
use Vereinskiste\Ledger\ClosingService;
use Vereinskiste\Ledger\DateInput;
use Vereinskiste\Ledger\InvalidAmount;
use Vereinskiste\Ledger\Money;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

/**
 * Uebernimmt ein bestehendes Kassenbuch (Excel oder CSV) als echte Buchungen. Anders als der
 * Kontoauszug-Import legt er Buchungen an, ausschliesslich ueber den BookingService und nur komplett
 * oder gar nicht. Zeilen ohne gueltiges Datum oder Betrag werden nie automatisch uebernommen.
 */
final class BookingImportService
{
    private const MAX_ROWS = 2000;
    private const DESCRIPTION_SEPARATOR = ' – ';
    private const DRAFT_DAYS = 30;

    public function __construct(private PDO $pdo) {}

    public function upload(string $accountId, string $path, string $name, int $actor): int
    {
        $account = preg_match('/^[1-9]\d{0,17}\z/', $accountId) === 1 ? (new AccountRepository($this->pdo))->find((int) $accountId) : null;
        if ($account === null || !$account['active']) { throw new ValidationError(['account_id' => 'validation.booking.account']); }
        $sheets = $this->sheets($path);
        $name = UserRepository::isStorableText($name) ? mb_substr($name, 0, 200) : 'import';
        $stmt = $this->pdo->prepare('INSERT INTO booking_imports (account_id, file_name, file_sha256, sheets, created_by) VALUES (?, ?, ?, ?::jsonb, ?) RETURNING id');
        $stmt->execute([$account['id'], $name, hash_file('sha256', $path), json_encode($sheets, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $actor]);
        return (int) $stmt->fetchColumn();
    }

    public function list(): array
    {
        return $this->pdo->query('SELECT i.id, i.file_name, i.status, i.created_count, i.created_at, a.name AS account_name
            FROM booking_imports i JOIN accounts a ON a.id = i.account_id ORDER BY i.id DESC LIMIT 100')->fetchAll();
    }

    public function find(int $id, bool $lock = false): ?array
    {
        $stmt = $this->pdo->prepare('SELECT i.*, a.name AS account_name, a.opening_date::text AS opening_date,
            EXISTS (SELECT 1 FROM booking_imports o WHERE o.id <> i.id AND o.account_id = i.account_id
                AND o.file_sha256 = i.file_sha256 AND o.status = \'confirmed\') AS already_imported
            FROM booking_imports i JOIN accounts a ON a.id = i.account_id WHERE i.id = ?' . ($lock ? ' FOR UPDATE OF i' : ''));
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) { return null; }
        $row['id'] = (int) $row['id']; $row['version'] = (int) $row['version'];
        $row['sheets'] = json_decode($row['sheets'], true, 16, JSON_THROW_ON_ERROR);
        $row['settings'] = json_decode($row['settings'], true, 16, JSON_THROW_ON_ERROR);
        return $row;
    }

    /** Vorschlag aus den Spaltenueberschriften; der Benutzer bestaetigt oder aendert ihn. */
    public function guess(array $sheet): array
    {
        $header = $sheet['rows'][0];
        foreach ($sheet['rows'] as $row) {
            if (count(array_filter($row['c'], static fn (string $v): bool => $v !== '')) >= 3) { $header = $row; break; }
        }
        $mapping = ['header_row' => $header['n'], 'date' => '', 'amount' => '', 'description' => '', 'counterparty' => '', 'event' => '', 'notes' => [], 'category_id' => '', 'ignore_rows' => ''];
        $patterns = ['date' => '/datum/iu', 'amount' => '/betrag|menge|summe|wert/iu', 'description' => '/zweck|verwendung|beschreibung|text/iu',
            'counterparty' => '/person|name|empf|gegen|auftrag/iu'];
        foreach ($header['c'] as $index => $label) {
            foreach ($patterns as $field => $pattern) {
                if ($mapping[$field] === '' && preg_match($pattern, $label) === 1) { $mapping[$field] = $index; break; }
            }
            if (preg_match('/anmerk|notiz|bemerk|kommentar/iu', $label) === 1) { $mapping['notes'][] = $index; }
        }
        // Spalten ohne Ueberschrift, aber mit Inhalt, sind in gewachsenen Tabellen meist Anmerkungen.
        foreach ($this->columns($sheet, $header['n']) as $index => $label) {
            if (($header['c'][$index] ?? '') === '' && $mapping['notes'] !== [] && $index > $mapping['notes'][0]) { $mapping['notes'][] = $index; }
        }
        return $mapping;
    }

    /** @return array<int,string> Spaltenindex => Ueberschrift oder Spaltenbuchstabe, nur Spalten mit Inhalt */
    public function columns(array $sheet, int $headerRow): array
    {
        $labels = $used = [];
        foreach ($sheet['rows'] as $row) {
            foreach ($row['c'] as $index => $value) {
                if ($value === '') { continue; }
                $used[$index] = true;
                if ($row['n'] === $headerRow) { $labels[$index] = $value; }
            }
        }
        $out = [];
        ksort($used);
        foreach (array_keys($used) as $index) {
            $letter = XlsxReader::columnName($index);
            $out[$index] = isset($labels[$index]) ? $labels[$index] . ' (' . $letter . ')' : $letter;
        }
        return $out;
    }

    public function saveMapping(int $id, array $input, int $version): void
    {
        Transaction::run($this->pdo, function () use ($id, $input, $version): void {
            $import = $this->editable($id, $version);
            $sheet = $import['sheets'][(int) ($input['sheet'] ?? -1)] ?? null;
            if ($sheet === null) { throw new ValidationError(['sheet' => 'foundation.invalid']); }
            $column = static fn (string $key): ?int => is_string($input[$key] ?? null) && preg_match('/^\d{1,2}\z/', $input[$key]) === 1 ? (int) $input[$key] : null;
            $errors = [];
            foreach (['date', 'amount'] as $required) {
                if ($column($required) === null) { $errors[$required] = 'foundation.required'; }
            }
            if ($column('description') === null && $column('counterparty') === null) { $errors['description'] = 'foundation.required'; }
            $header = Fields::version($input['header_row'] ?? null);
            if ($header === 0) { $errors['header_row'] = 'foundation.invalid'; }
            $category = is_string($input['category_id'] ?? null) ? $input['category_id'] : '';
            if ($category !== '' && preg_match('/^[1-9]\d{0,17}\z/', $category) !== 1) { $errors['category_id'] = 'validation.booking.category'; }
            $ignore = is_string($input['ignore_rows'] ?? null) ? trim($input['ignore_rows']) : '';
            if ($ignore !== '' && preg_match('/^\d{1,6}(\s*-\s*\d{1,6})?(\s*,\s*\d{1,6}(\s*-\s*\d{1,6})?)*\z/', $ignore) !== 1) {
                $errors['ignore_rows'] = 'booking_import.ignore_rows_invalid';
            }
            if ($errors !== []) { throw new ValidationError($errors); }
            $notes = [];
            foreach ((array) ($input['notes'] ?? []) as $note) {
                if (is_string($note) && preg_match('/^\d{1,2}\z/', $note) === 1) { $notes[] = (int) $note; }
            }
            $settings = ['sheet' => (int) $input['sheet'], 'header_row' => $header, 'date' => $column('date'), 'amount' => $column('amount'),
                'description' => $column('description'), 'counterparty' => $column('counterparty'), 'event' => $column('event'),
                'notes' => $notes, 'category_id' => $category, 'ignore_rows' => $ignore];
            $this->pdo->prepare('UPDATE booking_imports SET settings = ?::jsonb, version = version + 1 WHERE id = ?')
                ->execute([json_encode($settings, JSON_THROW_ON_ERROR), $id]);
        });
    }

    /**
     * Rechnet die Zeilen nach der Zuordnung aus, ohne etwas zu speichern.
     *
     * @return array{rows:list<array<string,mixed>>,event_values:list<string>,ready:int,income:string,expense:string}
     */
    public function preview(array $import): array
    {
        $settings = $import['settings'];
        $closedUntil = (new ClosingService($this->pdo))->closedUntil();
        $existing = $this->existingBookings((int) $import['account_id']);
        $rows = $eventValues = [];
        $ready = $income = $expense = 0;
        foreach ($import['sheets'][$settings['sheet']]['rows'] as $source) {
            if ($source['n'] <= $settings['header_row']) { continue; }
            $row = $this->row($source, $settings);
            if ($row === null) { continue; }
            $row['problem'] = null;
            $row['duplicate'] = false;
            if ($this->ignored($source['n'], (string) ($settings['ignore_rows'] ?? ''))) {
                $row['state'] = 'ignored';
                $row['problem'] = 'booking_import.problem.ignored';
            } elseif ($row['amount'] === null) {
                $row['state'] = 'ignored';
                $row['problem'] = 'booking_import.problem.amount';
            } elseif ($row['date'] === null) {
                $row['state'] = 'needs_date';
                $row['problem'] = 'booking_import.problem.date';
            } elseif ($row['date'] < $import['opening_date']) {
                $row['state'] = 'needs_date';
                $row['problem'] = 'booking_import.problem.before_opening';
            } elseif ($closedUntil !== null && $row['date'] <= $closedUntil) {
                $row['state'] = 'needs_date';
                $row['problem'] = 'booking_import.problem.closed';
            } else {
                $row['state'] = 'ok';
                $key = $row['date'] . '|' . $row['amount'] . '|' . $row['direction'];
                if (($existing[$key] ?? 0) > 0) {
                    $existing[$key]--;
                    $row['duplicate'] = true;
                } else {
                    $ready++;
                    $cents = Money::toCents($row['amount']);
                    if ($row['direction'] === 'in') { $income += $cents; } else { $expense += $cents; }
                }
            }
            if ($row['event_value'] !== '' && $row['state'] !== 'ignored' && !in_array($row['event_value'], $eventValues, true)) {
                $eventValues[] = $row['event_value'];
            }
            $rows[] = $row;
        }
        return ['rows' => $rows, 'event_values' => $eventValues, 'ready' => $ready,
            'income' => Money::fromCents($income), 'expense' => Money::fromCents($expense)];
    }

    /**
     * Legt alle gewaehlten Zeilen in einer Transaktion an. Sperre und Status verhindern doppeltes Buchen.
     *
     * @return int Anzahl der angelegten Buchungen
     * @throws ValidationError Schluessel row_<Zeile> oder event_map
     */
    public function confirm(int $id, array $input, int $version, int $actor): int
    {
        return Transaction::run($this->pdo, function () use ($id, $input, $version, $actor): int {
            $import = $this->editable($id, $version);
            if (($input['confirm_check'] ?? '') !== '1') { throw new ValidationError(['rows' => 'booking_import.confirm_required']); }
            $preview = $this->preview($import);
            $skip = (array) ($input['skip'] ?? []);
            $dates = (array) ($input['date'] ?? []);
            $selected = $errors = [];
            foreach ($preview['rows'] as $row) {
                if ($row['state'] === 'ignored' || isset($skip[$row['n']])) { continue; }
                $typed = is_string($dates[$row['n']] ?? null) ? trim($dates[$row['n']]) : '';
                if ($row['state'] === 'needs_date' && $typed === '') { continue; }
                if ($row['state'] === 'needs_date' && !DateInput::valid($typed)) {
                    $errors['row_' . $row['n']] = 'validation.date.invalid';
                    continue;
                }
                $selected[] = ['date' => $row['state'] === 'needs_date' ? $typed : $row['date']] + $row;
            }
            if ($selected === [] && $errors === []) { throw new ValidationError(['rows' => 'booking_import.nothing_selected']); }
            $events = $this->resolveEvents($preview['event_values'], (array) ($input['event_map'] ?? []), $selected, $actor);
            $bookings = new BookingService($this->pdo);
            foreach ($selected as $row) {
                try {
                    $bookings->create(['account_id' => (string) $import['account_id'], 'booking_date' => $row['date'], 'amount' => $row['amount'],
                        'direction' => $row['direction'], 'description' => $row['description'], 'counterparty' => $row['counterparty'],
                        'category_id' => $import['settings']['category_id'], 'event_id' => (string) ($events[$row['event_value']] ?? '')], $actor);
                } catch (ValidationError $e) {
                    $errors['row_' . $row['n']] = array_values($e->errors)[0];
                } catch (ClosedPeriod) {
                    $errors['row_' . $row['n']] = 'booking_import.problem.closed';
                }
            }
            if ($errors !== []) { throw new ValidationError($errors); }
            // Nach der Uebernahme keine Tabellendaten mehr aufbewahren: sie enthalten auch Nichtgebuchtes.
            $this->pdo->prepare("UPDATE booking_imports SET status = 'confirmed', sheets = '[]'::jsonb, created_count = ?,
                confirmed_at = now(), version = version + 1 WHERE id = ?")->execute([count($selected), $id]);
            (new AuditLog($this->pdo))->record('booking_import_confirmed', $actor, ['import' => $id, 'count' => count($selected)]);
            return count($selected);
        });
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare("DELETE FROM booking_imports WHERE id = ? AND status = 'draft'")->execute([$id]);
    }

    /** Liegengebliebene Entwuerfe enthalten vollstaendige Tabellen samt Personennamen. */
    public function purgeDrafts(): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM booking_imports WHERE status = 'draft' AND created_at < now() - make_interval(days => ?)");
        $stmt->execute([self::DRAFT_DAYS]);
        return $stmt->rowCount();
    }

    /** @return array<string,int> Spaltenwert => Veranstaltung */
    private function resolveEvents(array $values, array $map, array $selected, int $actor): array
    {
        $events = new EventRepository($this->pdo);
        $resolved = [];
        foreach ($values as $index => $value) {
            $choice = is_string($map[$index] ?? null) ? $map[$index] : '';
            if ($choice === '') { continue; }
            if ($choice !== 'new') {
                $event = preg_match('/^[1-9]\d{0,17}\z/', $choice) === 1 ? $events->find((int) $choice) : null;
                if ($event === null || $event['kind'] !== 'event') { throw new ValidationError(['event_map' => 'foundation.invalid']); }
                $resolved[$value] = $event['id'];
                continue;
            }
            $dates = array_column(array_filter($selected, static fn (array $row): bool => $row['event_value'] === $value), 'date');
            if ($dates === []) { continue; }
            $resolved[$value] = $events->save(null, ['title' => mb_substr($value, 0, 200), 'kind' => 'event', 'starts_on' => min($dates), 'ends_on' => max($dates),
                'status' => max($dates) < date('Y-m-d') ? 'done' : 'planned'], 0, $actor);
        }
        return $resolved;
    }

    /** $list wie "4, 75-76" */
    private function ignored(int $row, string $list): bool
    {
        foreach ($list === '' ? [] : explode(',', $list) as $part) {
            $range = array_map('intval', explode('-', $part));
            if ($row >= $range[0] && $row <= ($range[1] ?? $range[0])) { return true; }
        }
        return false;
    }

    private function row(array $source, array $settings): ?array
    {
        $cell = static fn (?int $column): string => $column === null ? '' : trim($source['c'][$column] ?? '');
        $dateRaw = $cell($settings['date']);
        $amountRaw = $cell($settings['amount']);
        $counterparty = $cell($settings['counterparty']);
        $parts = [$cell($settings['description'])];
        foreach ($settings['notes'] as $note) { $parts[] = $cell($note); }
        $description = implode(self::DESCRIPTION_SEPARATOR, array_filter($parts, static fn (string $part): bool => $part !== ''));
        if ($dateRaw === '' && $amountRaw === '' && $description === '' && $counterparty === '') { return null; }
        $cents = $this->cents($amountRaw);
        return ['n' => $source['n'], 'date_raw' => $dateRaw, 'date' => $this->date($dateRaw), 'amount_raw' => $amountRaw,
            'amount' => $cents === null || $cents === 0 ? null : Money::fromCents(abs($cents)), 'direction' => $cents !== null && $cents > 0 ? 'in' : 'out',
            'description' => $description === '' ? $counterparty : $description, 'counterparty' => $counterparty, 'event_value' => $cell($settings['event'])];
    }

    private function cents(string $raw): ?int
    {
        try {
            return Money::toCents(trim(str_replace('€', '', $raw)), true);
        } catch (InvalidAmount) {
            return null;
        }
    }

    private function date(string $raw): ?string
    {
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})\z/', $raw, $m) === 1) {
            $raw = sprintf('%04d-%02d-%02d', strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        return DateInput::valid($raw) ? $raw : null;
    }

    /** @return array<string,int> Datum|Betrag|Richtung => Anzahl aktiver Buchungen des Kontos */
    private function existingBookings(int $accountId): array
    {
        $stmt = $this->pdo->prepare("SELECT booking_date::text || '|' || amount::text || '|' || direction AS key, count(*) AS n
            FROM bookings WHERE account_id = ? AND status = 'active' GROUP BY 1");
        $stmt->execute([$accountId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    private function editable(int $id, int $version): array
    {
        $import = $this->find($id, true);
        if ($import === null || $import['status'] !== 'draft' || $import['version'] !== $version) { throw new Conflict(); }
        return $import;
    }

    private function sheets(string $path): array
    {
        $handle = fopen($path, 'rb');
        $magic = $handle === false ? '' : (string) fread($handle, 4);
        if ($handle !== false) { fclose($handle); }
        if ($magic === "PK\x03\x04") { return (new XlsxReader())->read($path); }
        if ($magic === "\xD0\xCF\x11\xE0") { throw new ValidationError(['receipt' => 'booking_import.file_xls']); }
        $reader = new CsvReader();
        try {
            $settings = $reader->inspect($path);
            $rows = [];
            foreach ($reader->rows($path, $settings) as $number => $cells) {
                if ($number >= self::MAX_ROWS) { throw new ValidationError(['receipt' => 'booking_import.file_limit']); }
                $rows[] = ['n' => $number + 1, 'c' => array_map(static fn (?string $cell): string => mb_substr((string) $cell, 0, XlsxReader::MAX_CELL), $cells)];
            }
        } catch (\ValueError) {
            throw new ValidationError(['receipt' => 'booking_import.file_invalid']);
        }
        return [['name' => 'CSV', 'rows' => $rows]];
    }
}
