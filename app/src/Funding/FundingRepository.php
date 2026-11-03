<?php
declare(strict_types=1);

namespace Vereinskiste\Funding;

use PDO;
use Vereinskiste\ClubDocuments\Content;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Foundation\History;
use Vereinskiste\Ledger\DateInput;
use Vereinskiste\Ledger\InvalidAmount;
use Vereinskiste\Ledger\Money;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

/** Foerdermittelantraege beliebiger Geldgeber. Eingegangene Betraege kommen immer aus den Buchungen. */
final class FundingRepository
{
    public const FIELDS = ['title' => 200, 'funder' => 200, 'notes' => 20000];
    public const STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'closed'];
    private const SELECT = "SELECT f.id, f.title, f.funder, f.event_id, e.title AS event_title, f.status,
            f.requested_amount::text AS requested_amount, f.approved_amount::text AS approved_amount,
            f.submitted_on::text AS submitted_on, f.decided_on::text AS decided_on, f.proof_due_on::text AS proof_due_on,
            f.notes, f.version,
            (SELECT COALESCE(sum(CASE b.direction WHEN 'in' THEN b.amount ELSE -b.amount END), 0) FROM bookings b
              WHERE b.funding_id = f.id AND b.status = 'active' AND b.kind <> 'transfer')::text AS received
        FROM funding_applications f LEFT JOIN events e ON e.id = f.event_id";

    public function __construct(private PDO $pdo) {}

    public function list(): array
    {
        return $this->pdo->query(self::SELECT . ' ORDER BY f.id DESC LIMIT 500')->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE f.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) { return null; }
        $row['id'] = (int) $row['id']; $row['version'] = (int) $row['version'];
        return $row;
    }

    public function forEvent(int $eventId): array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE f.event_id = ? ORDER BY f.id DESC');
        $stmt->execute([$eventId]);
        return $stmt->fetchAll();
    }

    public function options(?int $keep = null): array
    {
        $stmt = $this->pdo->prepare("SELECT id, title, funder FROM funding_applications
            WHERE status NOT IN ('rejected', 'closed') OR id = ?::bigint ORDER BY id DESC LIMIT 500");
        $stmt->execute([$keep]);
        return $stmt->fetchAll();
    }

    public function save(?int $id, array $input, int $version, int $actor): int
    {
        $values = $this->validate($input);
        return Transaction::run($this->pdo, function () use ($id, $values, $version, $actor): int {
            $before = [];
            if ($id !== null) {
                $lock = $this->pdo->prepare('SELECT version FROM funding_applications WHERE id = ? FOR UPDATE');
                $lock->execute([$id]);
                $current = $lock->fetchColumn();
                if ($current === false || (int) $current !== $version) { throw new Conflict(); }
                $before = array_intersect_key($this->find($id), $values);
                $before['event_id'] = $before['event_id'] === null ? null : (int) $before['event_id'];
                $this->pdo->prepare('UPDATE funding_applications SET title = ?, funder = ?, event_id = ?, status = ?, requested_amount = ?,
                    approved_amount = ?, submitted_on = ?, decided_on = ?, proof_due_on = ?, notes = ?, version = version + 1, updated_at = now() WHERE id = ?')
                    ->execute([...array_values($values), $id]);
            } else {
                $stmt = $this->pdo->prepare('INSERT INTO funding_applications (title, funder, event_id, status, requested_amount, approved_amount,
                    submitted_on, decided_on, proof_due_on, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id');
                $stmt->execute([...array_values($values), $actor]);
                $id = (int) $stmt->fetchColumn();
            }
            if ($before !== []) { $before['notes'] = Content::plain($before['notes']); }
            (new History($this->pdo))->record('funding', $id, $before, ['notes' => Content::plain($values['notes'])] + $values, $actor, '');
            return $id;
        });
    }

    /** Antraege mit zugeordneten Buchungen bleiben als Nachweis erhalten. */
    public function delete(int $id): bool
    {
        return Transaction::run($this->pdo, function () use ($id): bool {
            $lock = $this->pdo->prepare('SELECT 1 FROM funding_applications WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            if ($lock->fetchColumn() === false) { return false; }
            $stmt = $this->pdo->prepare('SELECT 1 FROM bookings WHERE funding_id = ? LIMIT 1');
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() !== false) { throw new ValidationError(['delete' => 'funding.delete_blocked']); }
            $this->pdo->prepare('DELETE FROM funding_applications WHERE id = ?')->execute([$id]);
            return true;
        });
    }

    public function items(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT id, label, amount::text AS amount FROM funding_items WHERE application_id = ? ORDER BY id');
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    }

    public function addItem(int $id, array $input): void
    {
        $data = Fields::read($input, ['label' => 200], ['label']);
        $raw = is_string($input['amount'] ?? null) ? trim($input['amount']) : '';
        try {
            $amount = $raw === '' ? null : Money::parse($raw);
        } catch (InvalidAmount) {
            throw new ValidationError(['amount' => 'validation.booking.amount']);
        }
        $this->pdo->prepare('INSERT INTO funding_items (application_id, label, amount) VALUES (?, ?, ?)')->execute([$id, $data['label'], $amount]);
    }

    public function removeItem(int $id, int $itemId): void
    {
        $this->pdo->prepare('DELETE FROM funding_items WHERE id = ? AND application_id = ?')->execute([$itemId, $id]);
    }

    /** @return array<string,mixed> Reihenfolge entspricht den SQL-Spalten in save() */
    private function validate(array $input): array
    {
        $errors = [];
        $data = ['title' => '', 'funder' => '', 'notes' => ''];
        try {
            $data = Fields::read($input, self::FIELDS, ['title', 'funder']);
        } catch (ValidationError $e) {
            $errors = $e->errors;
        }
        $text = static fn (string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        $status = $text('status') ?: 'draft';
        if (!in_array($status, self::STATUSES, true)) { $errors['status'] = 'foundation.invalid'; }
        $requested = $approved = null;
        try {
            $requested = Money::parse($text('requested_amount'));
        } catch (InvalidAmount) {
            $errors['requested_amount'] = 'validation.booking.amount';
        }
        if ($text('approved_amount') !== '') {
            try {
                $approved = Money::parseSigned($text('approved_amount'));
                if (str_starts_with($approved, '-')) { $errors['approved_amount'] = 'validation.booking.amount'; }
            } catch (InvalidAmount) {
                $errors['approved_amount'] = 'validation.booking.amount';
            }
        }
        $dates = [];
        foreach (['submitted_on', 'decided_on', 'proof_due_on'] as $key) {
            $dates[$key] = $text($key) === '' ? null : $text($key);
            if ($dates[$key] !== null && !DateInput::valid($dates[$key])) { $errors[$key] = 'validation.date.invalid'; }
        }
        $event = $text('event_id') === '' ? null : Fields::version($text('event_id'));
        if ($event !== null) {
            $stmt = $this->pdo->prepare("SELECT 1 FROM events WHERE id = ? AND kind = 'event'");
            $stmt->execute([$event]);
            if ($stmt->fetchColumn() === false) { $errors['event_id'] = 'foundation.invalid'; }
        }
        if ($errors !== []) { throw new ValidationError($errors); }
        return ['title' => $data['title'], 'funder' => $data['funder'], 'event_id' => $event, 'status' => $status,
            'requested_amount' => $requested, 'approved_amount' => $approved] + $dates + ['notes' => Content::clean($data['notes'])];
    }
}
