<?php
declare(strict_types=1);

namespace Vereinskiste\Events;

use PDO;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Ledger\InvalidAmount;
use Vereinskiste\Ledger\Money;
use Vereinskiste\Ledger\ValidationError;

/**
 * Einkaufs-, Pack- und andere Listen einer Veranstaltung. Geschaetzte Kosten sind eine Hilfe zum Planen
 * und fliessen bewusst nicht ins Budget, damit nichts doppelt zaehlt.
 */
final class EventLists
{
    public function __construct(private PDO $pdo) {}

    /** @return list<array<string,mixed>> Listen mit Eintraegen, Kostensumme und Fortschritt */
    public function forEvent(int $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, title FROM event_lists WHERE event_id = ? ORDER BY id');
        $stmt->execute([$eventId]);
        $lists = $stmt->fetchAll();
        $items = $this->pdo->prepare('SELECT id, label, quantity, estimated_cost::text AS estimated_cost, person_name, done FROM event_list_items WHERE list_id = ? ORDER BY done, id');
        foreach ($lists as &$list) {
            $items->execute([$list['id']]);
            $list['items'] = $items->fetchAll();
            $cents = 0;
            $done = 0;
            foreach ($list['items'] as &$item) {
                $item['done'] = in_array($item['done'], [true, 't', 'true', 1, '1'], true);
                $cents += $item['estimated_cost'] === null ? 0 : Money::toCents($item['estimated_cost']);
                $done += $item['done'] ? 1 : 0;
            }
            unset($item);
            $list['cost'] = Money::fromCents($cents);
            $list['done_count'] = $done;
        }
        unset($list);
        return $lists;
    }

    public function addList(int $eventId, array $input): void
    {
        $data = Fields::read($input, ['list_title' => 100], ['list_title']);
        $this->pdo->prepare('INSERT INTO event_lists (event_id, title) VALUES (?, ?)')->execute([$eventId, $data['list_title']]);
    }

    public function removeList(int $eventId, int $listId): void
    {
        $this->pdo->prepare('DELETE FROM event_lists WHERE id = ? AND event_id = ?')->execute([$listId, $eventId]);
    }

    public function addItem(int $eventId, int $listId, array $input): void
    {
        [$label, $quantity, $cost, $person] = $this->itemValues($input);
        $this->pdo->prepare('INSERT INTO event_list_items (list_id, label, quantity, estimated_cost, person_name)
            SELECT id, ?, ?, ?, ? FROM event_lists WHERE id = ? AND event_id = ?')->execute([$label, $quantity, $cost, $person, $listId, $eventId]);
    }

    public function updateItem(int $eventId, int $itemId, array $input): void
    {
        [$label, $quantity, $cost, $person] = $this->itemValues($input);
        $this->pdo->prepare('UPDATE event_list_items SET label = ?, quantity = ?, estimated_cost = ?, person_name = ?
            WHERE id = ? AND list_id IN (SELECT id FROM event_lists WHERE event_id = ?)')->execute([$label, $quantity, $cost, $person, $itemId, $eventId]);
    }

    public function toggleItem(int $eventId, int $itemId): void
    {
        $this->pdo->prepare('UPDATE event_list_items SET done = NOT done WHERE id = ? AND list_id IN (SELECT id FROM event_lists WHERE event_id = ?)')->execute([$itemId, $eventId]);
    }

    public function removeItem(int $eventId, int $itemId): void
    {
        $this->pdo->prepare('DELETE FROM event_list_items WHERE id = ? AND list_id IN (SELECT id FROM event_lists WHERE event_id = ?)')->execute([$itemId, $eventId]);
    }

    /** Kopiert alle Listen beim Klonen, Haken werden zurueckgesetzt. */
    public function copy(int $sourceId, int $targetId): void
    {
        $lists = $this->pdo->prepare('SELECT id, title FROM event_lists WHERE event_id = ? ORDER BY id');
        $lists->execute([$sourceId]);
        $insert = $this->pdo->prepare('INSERT INTO event_lists (event_id, title) VALUES (?, ?) RETURNING id');
        $items = $this->pdo->prepare('INSERT INTO event_list_items (list_id, label, quantity, estimated_cost, person_name)
            SELECT ?, label, quantity, estimated_cost, person_name FROM event_list_items WHERE list_id = ? ORDER BY id');
        foreach ($lists->fetchAll() as $list) {
            $insert->execute([$targetId, $list['title']]);
            $items->execute([(int) $insert->fetchColumn(), $list['id']]);
        }
    }

    /** @return array{0:string,1:string,2:?string,3:string} */
    private function itemValues(array $input): array
    {
        $data = Fields::read($input, ['label' => 200, 'quantity' => 50, 'person_name' => 200], ['label']);
        $raw = is_string($input['estimated_cost'] ?? null) ? trim($input['estimated_cost']) : '';
        try {
            $cost = $raw === '' ? null : Money::parse($raw);
        } catch (InvalidAmount) {
            throw new ValidationError(['estimated_cost' => 'validation.booking.amount']);
        }
        return [$data['label'], $data['quantity'], $cost, $data['person_name']];
    }
}
