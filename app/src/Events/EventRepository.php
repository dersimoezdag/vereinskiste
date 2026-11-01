<?php
declare(strict_types=1);

namespace Vereinskiste\Events;

use PDO;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Foundation\History;
use Vereinskiste\Ledger\DateInput;
use Vereinskiste\Ledger\InvalidAmount;
use Vereinskiste\Ledger\Money;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

/** Veranstaltungen und wiederverwendbare Vorlagen samt Budgetposten und Aufgaben. */
final class EventRepository
{
    public const FIELDS = ['title' => 200, 'location' => 200, 'notes' => 2000];
    public const STATUSES = ['planned', 'done', 'cancelled'];

    public function __construct(private PDO $pdo) {}

    public function list(string $kind): array
    {
        $order = $kind === 'template' ? 'lower(e.title), e.id' : 'e.starts_on DESC, e.id DESC';
        $stmt = $this->pdo->prepare("SELECT e.id, e.kind, e.title, e.starts_on::text AS starts_on, e.ends_on::text AS ends_on,
                e.location, e.status,
                (SELECT count(*) FROM event_tasks t WHERE t.event_id = e.id) AS tasks_total,
                (SELECT count(*) FROM event_tasks t WHERE t.event_id = e.id AND t.done) AS tasks_done
            FROM events e WHERE e.kind = ? ORDER BY $order LIMIT 500");
        $stmt->execute([$kind]);
        return $stmt->fetchAll();
    }

    public function find(int $id, bool $lock = false): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, kind, title, starts_on::text AS starts_on, ends_on::text AS ends_on,
            location, notes, status, source_event_id, version FROM events WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) { return null; }
        $row['id'] = (int) $row['id']; $row['version'] = (int) $row['version'];
        return $row;
    }

    /** Auswahl fuer Buchungs- und Foerderformulare; abgesagte nur, wenn bereits zugeordnet. */
    public function options(?int $keep = null): array
    {
        $stmt = $this->pdo->prepare("SELECT id, title, starts_on::text AS starts_on FROM events
            WHERE kind = 'event' AND (status <> 'cancelled' OR id = ?::bigint) ORDER BY starts_on DESC, id DESC LIMIT 500");
        $stmt->execute([$keep]);
        return $stmt->fetchAll();
    }

    public function save(?int $id, array $input, int $version, int $actor): int
    {
        $data = Fields::read($input, self::FIELDS, ['title']);
        return Transaction::run($this->pdo, function () use ($id, $input, $data, $version, $actor): int {
            $before = $id === null ? null : $this->find($id, true);
            if ($id !== null && ($before === null || $before['version'] !== $version)) { throw new Conflict(); }
            $kind = $before['kind'] ?? (($input['kind'] ?? '') === 'template' ? 'template' : 'event');
            [$starts, $ends] = $this->dates($kind, $input);
            $status = $kind === 'template' ? 'planned' : ($input['status'] ?? 'planned');
            if (!in_array($status, self::STATUSES, true)) { throw new ValidationError(['status' => 'foundation.invalid']); }
            $after = $data + ['starts_on' => $starts, 'ends_on' => $ends, 'status' => $status];
            if ($id === null) {
                $stmt = $this->pdo->prepare('INSERT INTO events (kind, title, starts_on, ends_on, location, notes, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id');
                $stmt->execute([$kind, $data['title'], $starts, $ends, $data['location'], $data['notes'], $status, $actor]);
                $id = (int) $stmt->fetchColumn();
            } else {
                $this->pdo->prepare('UPDATE events SET title = ?, starts_on = ?, ends_on = ?, location = ?, notes = ?, status = ?,
                    version = version + 1, updated_at = now() WHERE id = ?')
                    ->execute([$data['title'], $starts, $ends, $data['location'], $data['notes'], $status, $id]);
            }
            (new History($this->pdo))->record('event', $id, $before === null ? [] : array_intersect_key($before, $after), $after, $actor, '');
            return $id;
        });
    }

    /** Neue Veranstaltung oder Vorlage aus einer vorhandenen; Ist-Werte und Erledigt-Haken kommen nicht mit. */
    public function duplicate(int $sourceId, array $input, int $actor): int
    {
        $data = Fields::read($input, ['title' => self::FIELDS['title']], ['title']);
        $kind = ($input['kind'] ?? '') === 'template' ? 'template' : 'event';
        [$starts, $ends] = $this->dates($kind, $input);
        return Transaction::run($this->pdo, function () use ($sourceId, $data, $kind, $starts, $ends, $actor): int {
            $source = $this->find($sourceId, true);
            if ($source === null) { throw new ValidationError(['source' => 'foundation.invalid']); }
            $stmt = $this->pdo->prepare('INSERT INTO events (kind, title, starts_on, ends_on, location, notes, source_event_id, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id');
            $stmt->execute([$kind, $data['title'], $starts, $ends, $source['location'], $source['notes'], $sourceId, $actor]);
            $id = (int) $stmt->fetchColumn();
            $this->pdo->prepare('INSERT INTO event_budget_items (event_id, label, direction, planned_amount)
                SELECT ?, label, direction, planned_amount FROM event_budget_items WHERE event_id = ? ORDER BY id')->execute([$id, $sourceId]);
            $this->pdo->prepare('INSERT INTO event_tasks (event_id, title, member_id, person_name, due_offset_days)
                SELECT ?, title, member_id, person_name, due_offset_days FROM event_tasks WHERE event_id = ? ORDER BY id')->execute([$id, $sourceId]);
            (new History($this->pdo))->record('event', $id, [], ['title' => $data['title'], 'starts_on' => $starts, 'ends_on' => $ends], $actor, '');
            return $id;
        });
    }

    /** Zugeordnete Buchungen und Foerderantraege verhindern das Loeschen: Der Bezug im Kassenbuch bleibt erhalten. */
    public function delete(int $id): bool
    {
        return Transaction::run($this->pdo, function () use ($id): bool {
            if ($this->find($id, true) === null) { return false; }
            $stmt = $this->pdo->prepare('SELECT (EXISTS (SELECT 1 FROM bookings WHERE event_id = ?)
                OR EXISTS (SELECT 1 FROM funding_applications WHERE event_id = ?))::int');
            $stmt->execute([$id, $id]);
            if ((int) $stmt->fetchColumn() === 1) { throw new ValidationError(['delete' => 'events.delete_blocked']); }
            $this->pdo->prepare('DELETE FROM events WHERE id = ?')->execute([$id]);
            return true;
        });
    }

    public function addBudgetItem(int $eventId, array $input): void
    {
        [$label, $direction, $amount] = $this->budgetValues($input);
        $this->pdo->prepare('INSERT INTO event_budget_items (event_id, label, direction, planned_amount) VALUES (?, ?, ?, ?)')
            ->execute([$eventId, $label, $direction, $amount]);
    }

    public function updateBudgetItem(int $eventId, int $itemId, array $input): void
    {
        [$label, $direction, $amount] = $this->budgetValues($input);
        $this->pdo->prepare('UPDATE event_budget_items SET label = ?, direction = ?, planned_amount = ? WHERE id = ? AND event_id = ?')
            ->execute([$label, $direction, $amount, $itemId, $eventId]);
    }

    public function removeBudgetItem(int $eventId, int $itemId): void
    {
        $this->pdo->prepare('DELETE FROM event_budget_items WHERE id = ? AND event_id = ?')->execute([$itemId, $eventId]);
    }

    public function addTask(int $eventId, array $input): void
    {
        [$title, $member, $person, $offset] = $this->taskValues($input);
        $this->pdo->prepare('INSERT INTO event_tasks (event_id, title, member_id, person_name, due_offset_days) VALUES (?, ?, ?, ?, ?)')
            ->execute([$eventId, $title, $member, $person, $offset]);
    }

    /** Der Erledigt-Stand bleibt beim Bearbeiten unveraendert. */
    public function updateTask(int $eventId, int $taskId, array $input): void
    {
        [$title, $member, $person, $offset] = $this->taskValues($input);
        $this->pdo->prepare('UPDATE event_tasks SET title = ?, member_id = ?, person_name = ?, due_offset_days = ? WHERE id = ? AND event_id = ?')
            ->execute([$title, $member, $person, $offset, $taskId, $eventId]);
    }

    public function toggleTask(int $eventId, int $taskId): void
    {
        $this->pdo->prepare('UPDATE event_tasks SET done = NOT done WHERE id = ? AND event_id = ?')->execute([$taskId, $eventId]);
    }

    public function removeTask(int $eventId, int $taskId): void
    {
        $this->pdo->prepare('DELETE FROM event_tasks WHERE id = ? AND event_id = ?')->execute([$taskId, $eventId]);
    }

    public function budgetItems(int $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, label, direction, planned_amount::text AS planned_amount FROM event_budget_items WHERE event_id = ? ORDER BY id');
        $stmt->execute([$eventId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> Aufgaben mit errechnetem Faelligkeitsdatum (bei Vorlagen null) */
    public function tasks(array $event): array
    {
        $stmt = $this->pdo->prepare('SELECT t.id, t.title, t.member_id, t.person_name, t.due_offset_days, t.done, COALESCE(m.name, t.person_name) AS person
            FROM event_tasks t LEFT JOIN members m ON m.id = t.member_id WHERE t.event_id = ?
            ORDER BY t.due_offset_days NULLS LAST, t.id');
        $stmt->execute([$event['id']]);
        return array_map(static function (array $task) use ($event): array {
            $task['due_on'] = $task['due_offset_days'] === null || $event['starts_on'] === null ? null
                : (new \DateTimeImmutable($event['starts_on']))->modify(sprintf('%+d days', (int) $task['due_offset_days']))->format('Y-m-d');
            return $task;
        }, $stmt->fetchAll());
    }

    /** Plan aus den Budgetposten, Ist aus den aktiven zugeordneten Buchungen (ohne Umbuchungen). */
    public function totals(int $eventId): array
    {
        $stmt = $this->pdo->prepare("SELECT
            (SELECT COALESCE(sum(planned_amount), 0) FROM event_budget_items WHERE event_id = :id AND direction = 'in')::text AS planned_in,
            (SELECT COALESCE(sum(planned_amount), 0) FROM event_budget_items WHERE event_id = :id AND direction = 'out')::text AS planned_out,
            (SELECT COALESCE(sum(amount), 0) FROM bookings WHERE event_id = :id AND status = 'active' AND kind <> 'transfer' AND direction = 'in')::text AS actual_in,
            (SELECT COALESCE(sum(amount), 0) FROM bookings WHERE event_id = :id AND status = 'active' AND kind <> 'transfer' AND direction = 'out')::text AS actual_out");
        $stmt->execute(['id' => $eventId]);
        $row = $stmt->fetch();
        $row['planned_balance'] = Money::fromCents(Money::toCents($row['planned_in']) - Money::toCents($row['planned_out']));
        $row['actual_balance'] = Money::fromCents(Money::toCents($row['actual_in']) - Money::toCents($row['actual_out']));
        return $row;
    }

    /** @return array{0:string,1:string,2:string} */
    private function budgetValues(array $input): array
    {
        $data = Fields::read($input, ['label' => 200], ['label']);
        $direction = $input['direction'] ?? '';
        if (!in_array($direction, ['in', 'out'], true)) { throw new ValidationError(['direction' => 'foundation.invalid']); }
        try {
            $amount = Money::parse(is_string($input['planned_amount'] ?? null) ? $input['planned_amount'] : '');
        } catch (InvalidAmount) {
            throw new ValidationError(['planned_amount' => 'validation.booking.amount']);
        }
        return [$data['label'], $direction, $amount];
    }

    /** @return array{0:string,1:?int,2:string,3:?int} */
    private function taskValues(array $input): array
    {
        $data = Fields::read($input, ['title' => 200, 'person_name' => 200], ['title']);
        $member = is_string($input['member_id'] ?? null) && $input['member_id'] !== '' ? Fields::version($input['member_id']) : null;
        if ($member === 0) { throw new ValidationError(['member_id' => 'foundation.invalid']); }
        if ($member !== null) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM members WHERE id = ? AND anonymized_at IS NULL');
            $stmt->execute([$member]);
            if ($stmt->fetchColumn() === false) { throw new ValidationError(['member_id' => 'foundation.invalid']); }
        }
        $raw = is_string($input['due_offset_days'] ?? null) ? trim($input['due_offset_days']) : '';
        if ($raw !== '' && (preg_match('/^-?\d{1,4}\z/', $raw) !== 1 || abs((int) $raw) > 3650)) {
            throw new ValidationError(['due_offset_days' => 'foundation.invalid']);
        }
        return [$data['title'], $member, $member === null ? $data['person_name'] : '', $raw === '' ? null : (int) $raw];
    }

    /** @return array{0:?string,1:?string} */
    private function dates(string $kind, array $input): array
    {
        if ($kind === 'template') { return [null, null]; }
        $starts = is_string($input['starts_on'] ?? null) ? trim($input['starts_on']) : '';
        $ends = is_string($input['ends_on'] ?? null) ? trim($input['ends_on']) : '';
        $errors = [];
        if (!DateInput::valid($starts)) { $errors['starts_on'] = 'validation.date.invalid'; }
        if ($ends !== '' && (!DateInput::valid($ends) || $ends < $starts)) { $errors['ends_on'] = 'validation.date.invalid'; }
        if ($errors !== []) { throw new ValidationError($errors); }
        return [$starts, $ends === '' ? null : $ends];
    }
}
