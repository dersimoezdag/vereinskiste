<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Members;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Members\MemberRepository;
use Vereinskiste\Members\MembershipPeriods;
use Vereinskiste\Tests\TestCase;

final class MembershipPeriodsTest extends TestCase
{
    private PDO $db;
    private int $member;

    protected function setUp(): void
    {
        $this->db = $this->pdo();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->db->exec("INSERT INTO users(name,email,password_hash) VALUES ('Admin','admin@example.org','hash')");
        $this->member = (new MemberRepository($this->db))->save(null, ['name' => 'Erika Muster'], 0, 1);
    }

    public function test_cutoff_day_is_inclusive_on_both_ends(): void
    {
        $periods = new MembershipPeriods($this->db);
        $periods->join($this->member, '2026-01-10', 1);
        $periods->leave($this->member, '2026-06-30', 'Umzug', 1);
        $this->assertFalse($periods->isMemberOn($this->member, '2026-01-09'));
        $this->assertTrue($periods->isMemberOn($this->member, '2026-01-10'));
        $this->assertTrue($periods->isMemberOn($this->member, '2026-06-30'));
        $this->assertFalse($periods->isMemberOn($this->member, '2026-07-01'));
    }

    public function test_rejoin_starts_new_period_and_keeps_history(): void
    {
        $periods = new MembershipPeriods($this->db);
        $periods->join($this->member, '2026-01-01', 1);
        $periods->leave($this->member, '2026-03-31', '', 1);
        $periods->join($this->member, '2026-04-01', 1);
        $this->assertSame(2, (int) $this->db->query('SELECT count(*) FROM membership_periods')->fetchColumn());
        $this->assertTrue($periods->isMemberOn($this->member, '2026-05-01'));
    }

    public function test_rejects_double_join_overlap_and_invalid_leave(): void
    {
        $periods = new MembershipPeriods($this->db);
        $periods->join($this->member, '2026-01-01', 1);
        $this->assertDateError(fn () => $periods->join($this->member, '2026-02-01', 1), 'members.period.already_member');
        $this->assertDateError(fn () => $periods->leave($this->member, '2025-12-31', '', 1), 'members.period.before_start');
        $periods->leave($this->member, '2026-03-31', '', 1);
        $this->assertDateError(fn () => $periods->join($this->member, '2026-03-31', 1), 'members.period.overlap');
        $this->assertDateError(fn () => $periods->leave($this->member, '2026-04-30', '', 1), 'members.period.not_member');
        $this->assertDateError(fn () => $periods->join($this->member, '2026-02-30', 1), 'foundation.invalid');
    }

    private function assertDateError(callable $call, string $key): void
    {
        try {
            $call();
            $this->fail('Fehler ' . $key . ' erwartet');
        } catch (ValidationError $e) {
            $this->assertSame($key, $e->errors['date']);
        }
    }
}
