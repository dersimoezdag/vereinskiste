<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Club;

use PDO;
use Vereinskiste\Club\ClubTimezone;
use Vereinskiste\Club\Profile;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Tests\TestCase;

final class ClubTimezoneTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->pdo->exec("INSERT INTO users (email, name, password_hash) VALUES ('v@example.org', 'V', 'x')");
    }

    protected function tearDown(): void
    {
        date_default_timezone_set('UTC');
    }

    public function test_default_is_berlin_for_php_and_database(): void
    {
        $this->assertSame('Europe/Berlin', ClubTimezone::apply($this->pdo));
        $this->assertSame('04.11.2026 00:30', (new \DateTimeImmutable('2026-11-03T23:30:00Z'))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d.m.Y H:i'));
        $this->assertSame('04.11.2026 00:30', $this->pdo->query("SELECT to_char(timestamptz '2026-11-03 23:30:00+00', 'DD.MM.YYYY HH24:MI')")->fetchColumn());
    }

    public function test_profile_timezone_is_validated_and_applied(): void
    {
        $profile = new Profile($this->pdo);
        $input = ['name' => 'Verein', 'locale' => 'de', 'year_start' => '01-01', 'timezone' => 'Mars/Olympus'];
        try {
            $profile->save($input, $profile->get()['version'], 1, 'Test');
            $this->fail('expected ValidationError');
        } catch (ValidationError $e) {
            $this->assertSame('foundation.invalid', $e->errors['timezone']);
        }
        $profile->save(['timezone' => 'America/New_York'] + $input, $profile->get()['version'], 1, 'Test');
        $this->assertSame('America/New_York', ClubTimezone::apply($this->pdo));
        $this->assertSame('03.11.2026 18:30', $this->pdo->query("SELECT to_char(timestamptz '2026-11-03 23:30:00+00', 'DD.MM.YYYY HH24:MI')")->fetchColumn());
    }
}
