<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Members;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Members\Declarations;
use Vereinskiste\Tests\TestCase;

final class DeclarationsTest extends TestCase
{
    private PDO $pdo;
    private Declarations $declarations;
    private int $actor;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->actor = (int) $this->pdo->query("INSERT INTO users (email, name, password_hash) VALUES ('v@example.org', 'V', 'x') RETURNING id")->fetchColumn();
        $this->declarations = new Declarations($this->pdo);
    }

    public function test_create_appends_and_change_creates_new_version(): void
    {
        $id = $this->declarations->save(null, ['title' => 'Beitrag', 'content' => '<p>Ich zahle <script>x</script>den Beitrag.</p>', 'kind' => 'confirmation', 'signature_required' => '1'], $this->actor);
        $created = $this->declarations->find($id);
        $this->assertSame(5, $created['position']);
        $this->assertSame(1, $created['version']);
        $this->assertSame('<p>Ich zahle den Beitrag.</p>', $created['content']);
        $this->assertTrue($created['signature_required']);

        $this->declarations->save($id, ['title' => 'Beitrag', 'content' => '<p>Ich zahle den Beitrag.</p>', 'kind' => 'confirmation', 'signature_required' => '1'], $this->actor);
        $this->assertSame(1, $this->declarations->find($id)['version'], 'unchanged input keeps version');

        $this->declarations->save($id, ['title' => 'Beitrag', 'content' => '<p>Ich zahle den Beitrag.</p>', 'kind' => 'consent'], $this->actor);
        $changed = $this->declarations->find($id);
        $this->assertSame(2, $changed['version']);
        $this->assertSame('consent', $changed['kind']);
        $this->assertFalse($changed['signature_required']);
        $this->assertSame(2, (int) $this->pdo->query("SELECT count(*) FROM application_declaration_versions WHERE declaration_id = $id")->fetchColumn());
        $this->assertSame(2, (int) $this->pdo->query("SELECT count(*) FROM audit_log WHERE action LIKE 'application_declaration_%'")->fetchColumn());
    }

    public function test_invalid_input_is_rejected(): void
    {
        try {
            $this->declarations->save(null, ['title' => '', 'content' => str_repeat('a', 20001), 'kind' => 'other'], $this->actor);
            $this->fail('expected ValidationError');
        } catch (ValidationError $e) {
            $this->assertSame(['title', 'content', 'kind'], array_keys($e->errors));
        }
    }

    public function test_move_and_deactivate_change_active_order_and_fingerprint(): void
    {
        $before = $this->declarations->fingerprint();
        $ids = array_column($this->declarations->active(), 'id');
        $this->declarations->move($ids[1], 'up', $this->actor);
        $this->assertSame([$ids[1], $ids[0], $ids[2], $ids[3]], array_column($this->declarations->active(), 'id'));
        $this->declarations->move($ids[1], 'up', $this->actor); // erste Position bleibt
        $this->assertSame($ids[1], $this->declarations->active()[0]['id']);
        $this->declarations->setActive($ids[3], false, $this->actor);
        $this->assertCount(3, $this->declarations->active());
        $this->assertCount(4, $this->declarations->all());
        $this->assertNotSame($before, $this->declarations->fingerprint());
    }

    public function test_render_replaces_club_placeholder_safely(): void
    {
        $this->assertSame('<p>Aufnahme in den A &amp; B e.V.</p>', Declarations::render('<p>Aufnahme in den {verein}</p>', 'A & B e.V.', true));
        $this->assertSame('Antrag A & B e.V.', Declarations::render('Antrag {verein}', 'A & B e.V.', false));
    }
}
