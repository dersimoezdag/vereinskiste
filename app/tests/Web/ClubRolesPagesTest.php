<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Members\MemberRepository;
use Vereinskiste\Tests\TestCase;

final class ClubRolesPagesTest extends TestCase
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

    public function test_vorstand_creates_and_manages_club_roles(): void
    {
        $this->loginAs(['admin']);
        $this->assertSame(200, $this->get('/settings/club-roles')->getStatusCode());

        // Create new role
        $res = $this->postForm('/settings/club-roles', ['name' => 'Jugendleiter', 'description' => 'Kümmert sich um die Jugend']);
        $this->assertSame('/settings/club-roles', $res->getHeaderLine('Location'));

        // Duplicate -> 422
        $res = $this->postForm('/settings/club-roles', ['name' => 'jugendleiter']);
        $this->assertSame(422, $res->getStatusCode());

        // Update role
        $id = (int) $this->pdo->query("SELECT id FROM club_roles WHERE name = 'Jugendleiter'")->fetchColumn();
        $res = $this->postForm('/settings/club-roles/' . $id, [
            'name' => 'Jugendleiter',
            'description' => 'Aktualisiert',
            'kind' => 'function',
            'version' => '1',
            'active' => '1',
        ]);
        $this->assertSame('/settings/club-roles', $res->getHeaderLine('Location'));

        // Conflict -> 409
        $res = $this->postForm('/settings/club-roles/' . $id, [
            'name' => 'Jugendleiter',
            'version' => '1',
        ]);
        $this->assertSame(409, $res->getStatusCode());
    }

    public function test_schriftfuehrer_gets_forbidden_on_settings_but_can_assign_roles(): void
    {
        $this->loginAs(['secretary']);
        $this->assertSame(403, $this->get('/settings/club-roles')->getStatusCode());
        $this->assertSame(403, $this->postForm('/settings/club-roles', ['name' => 'X'])->getStatusCode());

        // But Schriftführer can assign and end roles on members
        $mId = (new MemberRepository($this->pdo))->save(null, ['name' => 'Erika Muster'], 0, 1);
        $roleId = (int) $this->pdo->query("SELECT id FROM club_roles WHERE name = 'Ehrenmitglied'")->fetchColumn();

        $res = $this->postForm("/members/{$mId}/club-roles", [
            'role_id' => (string) $roleId,
            'scope' => 'Vereinsarbeit',
        ]);
        $this->assertSame("/members/{$mId}", $res->getHeaderLine('Location'));

        $assignmentId = (int) $this->pdo->query("SELECT id FROM member_club_roles WHERE member_id = {$mId} AND role_id = {$roleId}")->fetchColumn();
        $res = $this->postForm("/members/{$mId}/club-roles/{$assignmentId}/end", ['date' => '2026-10-13']);
        $this->assertSame("/members/{$mId}", $res->getHeaderLine('Location'));
    }

    public function test_single_holder_role_conflict_on_web(): void
    {
        $this->loginAs(['admin']);
        $m1 = (new MemberRepository($this->pdo))->save(null, ['name' => 'Erster Träger'], 0, 1);
        $m2 = (new MemberRepository($this->pdo))->save(null, ['name' => 'Zweiter Träger'], 0, 1);
        $financeRole = (int) $this->pdo->query("SELECT id FROM club_roles WHERE name = 'Finanzvorstand'")->fetchColumn();

        $this->postForm("/members/{$m1}/club-roles", ['role_id' => (string) $financeRole]);
        $res = $this->postForm("/members/{$m2}/club-roles", ['role_id' => (string) $financeRole]);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('bereits vergeben', (string) $res->getBody());
    }

    public function test_settings_tabs_show_club_roles_only_with_permission(): void
    {
        $this->loginAs(['secretary']);
        $this->assertStringNotContainsString('href="/settings/club-roles"', (string) $this->get('/settings/closing')->getBody());

        $this->pdo->exec("UPDATE user_roles SET role='admin'");
        $this->assertStringContainsString('href="/settings/club-roles"', (string) $this->get('/settings/club-roles')->getBody());
    }
}
