<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Tests\TestCase;

final class MembersPagesTest extends TestCase
{
    use WebHarness;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->store = new ArraySessionStore();
        $this->app = Kernel::create([
            'pdo' => $this->pdo,
            'session_store' => $this->store,
            'require_https' => false,
            'security_dir' => $this->securityDir(),
        ]);
    }

    public function test_schriftfuehrer_maintains_members_kassenwart_reads_only(): void
    {
        $this->loginAs(['schriftfuehrer']);
        $response = $this->postForm('/members/new', ['name' => 'Erika Muster', 'email' => 'erika@example.org', 'version' => '0']);
        $this->assertSame('/members/1', $response->getHeaderLine('Location'));
        $this->assertStringContainsString('Erika Muster', (string) $this->get('/members')->getBody());
        $this->assertSame(302, $this->postForm('/members/1/join', ['date' => '2026-01-01'])->getStatusCode());
        $this->assertSame(422, $this->postForm('/members/1/join', ['date' => '2026-02-01'])->getStatusCode());
        $this->assertSame(409, $this->postForm('/members/1', ['name' => 'X', 'version' => '99'])->getStatusCode());
        $this->pdo->exec("UPDATE user_roles SET role='kassenwart'");
        $this->assertSame(200, $this->get('/members/1')->getStatusCode());
        $this->assertSame(403, $this->postForm('/members/1', ['name' => 'Y', 'version' => '1'])->getStatusCode());
        $this->assertSame(403, $this->get('/members/new')->getStatusCode());
    }

    public function test_linking_needs_users_manage_and_shows_hint_without_user(): void
    {
        $this->loginAs(['schriftfuehrer']);
        $this->postForm('/members/new', ['name' => 'Erika Muster', 'version' => '0']);
        $body = (string) $this->get('/members/1')->getBody();
        $this->assertStringContainsString('Kein Benutzerzugang', $body);
        $this->assertSame(403, $this->postForm('/members/1/user', ['user_id' => '1'])->getStatusCode());
        $this->pdo->exec("UPDATE user_roles SET role='admin'");
        $this->assertSame('/members/1', $this->postForm('/members/1/user', ['user_id' => '1'])->getHeaderLine('Location'));
        $this->assertSame(1, (int) $this->pdo->query('SELECT member_id FROM users WHERE id = 1')->fetchColumn());
    }

    public function test_csv_exports_every_matching_member_with_safe_cells_and_read_permission(): void
    {
        $this->assertSame(302, $this->get('/members.csv')->getStatusCode());
        $this->loginAs(['schriftfuehrer']);
        $stmt = $this->pdo->prepare('INSERT INTO members(name,data) VALUES (?, ?::jsonb) RETURNING id');
        for ($i = 0; $i < 505; ++$i) {
            $stmt->execute(['Müller ' . $i, json_encode(['first_name' => 'Erika', 'last_name' => 'Müller',
                'house_number' => '12a', 'phone' => '+49 30 123', 'notes' => '=1+1;"Test"' . "\n" . 'zweite Zeile'], JSON_THROW_ON_ERROR)]);
            $id = (int) $stmt->fetchColumn();
            $this->pdo->prepare('INSERT INTO membership_periods(member_id,start_date) VALUES (?, ?)')
                ->execute([$id, '2026-01-01']);
        }
        $stmt->execute(['Andere Person', '{}']);
        $res = $this->get('/members.csv?q=M%C3%BCller&state=current&page=2');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('text/csv; charset=utf-8', $res->getHeaderLine('Content-Type'));
        $this->assertSame('no-store', $res->getHeaderLine('Cache-Control'));
        $stream = fopen('php://memory', 'w+');
        $csv = (string) $res->getBody();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $header = fgetcsv($stream, null, ';', '"', '');
        $this->assertCount(16, $header);
        $rows = [];
        while (($row = fgetcsv($stream, null, ';', '"', '')) !== false) { $rows[] = $row; }
        fclose($stream);
        $this->assertCount(505, $rows);
        $this->assertCount(505, array_unique(array_column($rows, 0)));
        $first = array_combine($header, $rows[0]);
        $this->assertSame('12a', $first['Hausnummer']);
        $this->assertSame("'+49 30 123", $first['Telefon']);
        $this->assertSame("'=1+1;\"Test\"\nzweite Zeile", $first['Notizen']);
        $this->assertSame('Ja', $first['Aktuelle Mitgliedschaft']);
        $this->assertStringNotContainsString('Andere Person', $csv);
        $this->assertStringContainsString('Mitglieder als CSV exportieren', (string) $this->get('/members')->getBody());
        $this->pdo->exec("UPDATE user_roles SET role='kassenwart'");
        $this->assertSame(200, $this->get('/members.csv?state=left')->getStatusCode());
        $this->pdo->exec('DELETE FROM user_roles');
        $this->assertSame(403, $this->get('/members.csv')->getStatusCode());
    }

    public function test_member_menu_entry_depends_on_permission(): void
    {
        $this->loginAs(['schriftfuehrer']);
        $this->assertStringContainsString('href="/members"', (string) $this->get('/')->getBody());
    }
}
