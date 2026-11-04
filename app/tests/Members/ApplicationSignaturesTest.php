<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Members;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Members\ApplicationRepository;
use Vereinskiste\Members\ApplicationSignatures;
use Vereinskiste\Members\Declarations;
use Vereinskiste\Tests\Support\ApplicationInput;
use Vereinskiste\Tests\TestCase;

final class ApplicationSignaturesTest extends TestCase
{
    private PDO $pdo;
    private ApplicationRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->pdo->exec("INSERT INTO users (email, name, password_hash) VALUES ('v@example.org', 'V', 'x')");
        $this->pdo->exec("INSERT INTO settings (key, value) VALUES ('club_name', 'FSI Jura e.V.') ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value");
        $this->repo = new ApplicationRepository($this->pdo);
    }

    /** @return array<string,mixed> */
    private function person(): array
    {
        return ['first_name' => 'Erika', 'last_name' => 'Muster', 'email' => 'erika@example.org'];
    }

    public function test_submit_stores_snapshots_signatures_and_consents(): void
    {
        $invite = $this->repo->createInvite(1, 'Test', 7);
        $id = $this->repo->submit($invite['token'], $this->person() + ApplicationInput::declarations($this->pdo, 'yes'));

        $rows = (new ApplicationSignatures($this->pdo))->forApplication($id);
        $this->assertCount(4, $rows);
        $this->assertSame('Antrag auf Mitgliedschaft', $rows[0]['snapshot']['title']);
        $this->assertStringContainsString('Aufnahme in den FSI Jura e.V.', $rows[0]['snapshot']['content']);
        $this->assertSame([true, true, null, true], array_column($rows, 'granted'));
        $this->assertSame([true, false, true, true], array_column($rows, 'has_signature'));
        $this->assertSame(['Erlangen', '', 'Erlangen', 'Erlangen'], array_column($rows, 'signed_place'), 'Ort nur bei Unterschriften');
        // jsonb sortiert Schluessel um; der Hash gilt fuer die beim Absenden verwendete Reihenfolge.
        $snapshot = array_intersect_key($rows[0]['snapshot'], array_flip(['title', 'content', 'kind', 'version']));
        $snapshot = ['title' => $snapshot['title'], 'content' => $snapshot['content'], 'kind' => $snapshot['kind'], 'version' => $snapshot['version']];
        $this->assertSame(hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), $rows[0]['snapshot_hash']);
        $png = (new ApplicationSignatures($this->pdo))->png($id, $rows[0]['id']);
        $this->assertSame(hash('sha256', (string) $png), $rows[0]['signature_sha256']);

        $kinds = $this->pdo->query("SELECT kind, granted, declaration_id IS NOT NULL AS linked FROM member_consents WHERE application_id = $id ORDER BY id")->fetchAll();
        $this->assertSame(3, count($kinds), 'confirmation + 2 consents, notice not recorded');
        $this->assertSame(['declaration'], array_values(array_unique(array_column($kinds, 'kind'))));
    }

    public function test_missing_answers_signatures_and_place_are_reported_per_declaration(): void
    {
        $invite = $this->repo->createInvite(1, 'Test', 7);
        $ids = array_column((new Declarations($this->pdo))->active(), 'id');
        $input = $this->person() + ApplicationInput::declarations($this->pdo);
        unset($input['declaration'][(string) $ids[0]], $input['declaration'][(string) $ids[1]]);
        $input['signature'][(string) $ids[2]] = '';
        $input['signature'][(string) $ids[3]] = ApplicationInput::signature(600, 160, false);
        $input['signed_place'][(string) $ids[0]] = ' ';
        $input['signed_place'][(string) $ids[3]] = str_repeat('x', 101);
        try {
            $this->repo->submit($invite['token'], $input);
            $this->fail('expected ValidationError');
        } catch (ValidationError $e) {
            $this->assertArrayNotHasKey('declaration_' . $ids[0], $e->errors, 'Unterschrift ersetzt die Checkbox');
            $this->assertSame('declarations.error.choose', $e->errors['declaration_' . $ids[1]]);
            $this->assertSame('declarations.error.signature_required', $e->errors['signature_' . $ids[2]]);
            $this->assertSame('declarations.error.signature_invalid', $e->errors['signature_' . $ids[3]]);
            $this->assertSame('foundation.required', $e->errors['place_' . $ids[0]]);
            $this->assertSame('foundation.invalid', $e->errors['place_' . $ids[3]]);
            $this->assertArrayNotHasKey('place_' . $ids[1], $e->errors, 'ohne Unterschrift kein Ort');
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM membership_applications')->fetchColumn());
    }

    public function test_confirmation_without_signature_still_needs_the_checkbox(): void
    {
        $id = (new Declarations($this->pdo))->active()[0]['id'];
        (new Declarations($this->pdo))->save($id, ['title' => 'Antrag', 'content' => '<p>Ich beantrage die Aufnahme.</p>', 'kind' => 'confirmation'], 1);
        $invite = $this->repo->createInvite(1, 'Test', 7);
        $input = $this->person() + ApplicationInput::declarations($this->pdo);
        $this->assertSame('1', $input['declaration'][(string) $id]);
        unset($input['declaration'][(string) $id]);
        try {
            $this->repo->submit($invite['token'], $input);
            $this->fail('expected ValidationError');
        } catch (ValidationError $e) {
            $this->assertSame('declarations.error.confirm', $e->errors['declaration_' . $id]);
        }
    }

    public function test_without_active_declarations_no_application_is_possible(): void
    {
        $this->pdo->exec('UPDATE application_declarations SET active = false');
        $invite = $this->repo->createInvite(1, 'Test', 7);
        try {
            $this->repo->submit($invite['token'], $this->person());
            $this->fail('expected ValidationError');
        } catch (ValidationError $e) {
            $this->assertSame('declarations.error.none', $e->errors['declarations']);
        }
    }

    public function test_place_is_optional_without_signatures(): void
    {
        $this->pdo->exec('UPDATE application_declarations SET active = false WHERE position <> 2');
        $invite = $this->repo->createInvite(1, 'Test', 7);
        $input = $this->person() + ApplicationInput::declarations($this->pdo);
        $this->assertSame([], $input['signed_place']);
        $this->assertGreaterThan(0, $this->repo->submit($invite['token'], $input));
    }

    public function test_changed_declaration_invalidates_form_fingerprint(): void
    {
        $consents = new \Vereinskiste\Privacy\Consents($this->pdo);
        $before = $consents->fingerprint();
        $id = (new Declarations($this->pdo))->active()[0]['id'];
        (new Declarations($this->pdo))->save($id, ['title' => 'Neu', 'content' => '<p>Neu</p>', 'kind' => 'confirmation', 'signature_required' => '1'], 1);
        $this->assertNotSame($before, $consents->fingerprint());
    }
}
