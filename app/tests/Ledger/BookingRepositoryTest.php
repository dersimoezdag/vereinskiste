<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Ledger;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\BookingRepository;
use Vereinskiste\Tests\TestCase;

final class BookingRepositoryTest extends TestCase
{
    private PDO $pdo;
    private BookingRepository $repo;
    private int $userId;
    private int $account;
    private int $account2;
    private int $catA;
    private int $catB;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->repo = new BookingRepository($this->pdo);
        $this->userId = (int) $this->pdo->query(
            "INSERT INTO users (email, name, password_hash) VALUES ('k@example.org', 'K', 'x') RETURNING id"
        )->fetchColumn();
        $this->account = (int) $this->pdo->query(
            "INSERT INTO accounts (name, type, opening_date) VALUES ('Bar', 'cash', '2026-01-01') RETURNING id"
        )->fetchColumn();
        $this->account2 = (int) $this->pdo->query(
            "INSERT INTO accounts (name, type, opening_date) VALUES ('Giro', 'bank', '2026-01-01') RETURNING id"
        )->fetchColumn();
        $this->catA = (int) $this->pdo->query("INSERT INTO categories (name) VALUES ('Beitraege') RETURNING id")->fetchColumn();
        $this->catB = (int) $this->pdo->query("INSERT INTO categories (name) VALUES ('Miete') RETURNING id")->fetchColumn();
    }

    private function add(
        string $date,
        ?int $category,
        string $description = 'x',
        string $direction = 'in',
        ?int $account = null,
        ?string $counterparty = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO bookings (account_id, booking_date, amount, direction, category_id, description, counterparty, created_by)
             VALUES (?, ?, 10, ?, ?, ?, ?, ?) RETURNING id'
        );
        $stmt->execute([$account ?? $this->account, $date, $direction, $category, $description, $counterparty, $this->userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string,mixed> $filter
     * @return int[]
     */
    private function ids(array $filter): array
    {
        return array_map(static fn (array $r): int => $r['id'], $this->repo->search($filter, 50, 0));
    }

    public function test_list_filters_by_period_and_category(): void
    {
        $a = $this->add('2026-01-10', $this->catA);
        $b = $this->add('2026-02-10', $this->catB);
        $c = $this->add('2026-03-10', $this->catA);

        $this->assertSame([$c, $b, $a], $this->ids([]));
        $this->assertSame([$b, $a], $this->ids(['to' => '2026-02-28']));
        $this->assertSame([$c, $b], $this->ids(['from' => '2026-02-10']));
        $this->assertSame([$b], $this->ids(['from' => '2026-02-01', 'to' => '2026-02-28']));
        $this->assertSame([$c, $a], $this->ids(['category_id' => $this->catA]));
        $this->assertSame([$c], $this->ids(['category_id' => $this->catA, 'from' => '2026-02-01']));
        $this->assertSame(2, $this->repo->count(['category_id' => $this->catA]));
    }

    public function test_list_filters_by_account_direction_kind_status(): void
    {
        $a = $this->add('2026-01-10', null, 'x', 'in', $this->account);
        $b = $this->add('2026-01-11', null, 'x', 'out', $this->account2);
        $this->pdo->exec("UPDATE bookings SET status = 'cancelled' WHERE id = $a");
        $this->pdo->exec("UPDATE bookings SET kind = 'correction' WHERE id = $b");

        $this->assertSame([$b], $this->ids(['account_id' => $this->account2]));
        $this->assertSame([$b], $this->ids(['direction' => 'out']));
        $this->assertSame([$b], $this->ids(['kind' => 'correction']));
        $this->assertSame([$a], $this->ids(['status' => 'cancelled']));
    }

    public function test_search_q_is_case_insensitive_and_escapes_wildcards(): void
    {
        $a = $this->add('2026-01-10', null, 'Raummiete Januar');
        $b = $this->add('2026-01-11', null, '100% Spende');
        $c = $this->add('2026-01-12', null, 'Sonstiges', 'in', null, 'Baeckerei_Mueller');

        $this->assertSame([$a], $this->ids(['q' => 'RAUMMIETE']));
        $this->assertSame([$b], $this->ids(['q' => '100%']));
        $this->assertSame([], $this->ids(['q' => '%%%'])); // Wildcard wird nicht als Muster gedeutet
        $this->assertSame([$c], $this->ids(['q' => 'ckerei_mue']));
        $this->add('2026-01-13', null, 'Sonstiges', 'in', null, 'Baeckerei-Mueller');
        $this->assertSame([$c], $this->ids(['q' => 'ckerei_mue'])); // "_" ist kein Einzelzeichen-Wildcard
    }

    public function test_changed_flag_comes_from_history_rows(): void
    {
        $a = $this->add('2026-01-10', null);
        $b = $this->add('2026-01-11', null);
        $this->pdo->prepare(
            "INSERT INTO booking_changes (booking_id, changed_by, changes, reason) VALUES (?, ?, '{}'::jsonb, 'Tippfehler')"
        )->execute([$a, $this->userId]);

        $rows = array_column($this->repo->search([], 50, 0), null, 'id');
        $this->assertTrue($rows[$a]['changed']);
        $this->assertFalse($rows[$b]['changed']);
        $this->assertTrue($this->repo->find($a)['changed']);
    }

    public function test_pagination_and_stable_order_for_same_date(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->add('2026-01-10', null);
        }
        $this->assertSame([$ids[4], $ids[3]], array_column($this->repo->search([], 2, 0), 'id'));
        $this->assertSame([$ids[2], $ids[1]], array_column($this->repo->search([], 2, 2), 'id'));
        $this->assertSame(5, $this->repo->count([]));
    }

    public function test_find_returns_null_for_unknown_id(): void
    {
        $this->assertNull($this->repo->find(12345));
    }
}
