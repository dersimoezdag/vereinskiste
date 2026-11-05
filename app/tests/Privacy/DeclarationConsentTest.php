<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Privacy;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Members\ApplicationRepository;
use Vereinskiste\Members\Declarations;
use Vereinskiste\Privacy\Consents;
use Vereinskiste\Tests\Support\ApplicationInput;
use Vereinskiste\Tests\TestCase;

final class DeclarationConsentTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->pdo->exec("INSERT INTO users (email, name, password_hash) VALUES ('v@example.org', 'V', 'x')");
    }

    public function test_staff_withdraws_photo_consent_from_application(): void
    {
        $repo = new ApplicationRepository($this->pdo);
        $invite = $repo->createInvite(1, 'Test', 7);
        $member = $repo->approve($repo->submit($invite['token'], ['first_name' => 'E', 'last_name' => 'M', 'email' => 'e@example.org'] + ApplicationInput::defaults($this->pdo, 'yes')), 1);
        $photo = (new Declarations($this->pdo))->all()[3];

        (new Consents($this->pdo))->record($member, ['kind' => 'declaration', 'declaration_id' => (string) $photo['id'], 'granted' => '0', 'reason' => 'Widerruf per E-Mail'], 1);
        $latest = (new Consents($this->pdo))->list($member)[0];
        $this->assertSame('declaration', $latest['kind']);
        $this->assertSame($photo['id'], (int) $latest['declaration_id']);
        $this->assertFalse((bool) $latest['granted']);
        $this->assertSame($photo['title'], $latest['snapshot']['title']);

        $this->expectException(ValidationError::class);
        $confirmation = (new Declarations($this->pdo))->all()[0];
        (new Consents($this->pdo))->record($member, ['kind' => 'declaration', 'declaration_id' => (string) $confirmation['id'], 'granted' => '0', 'reason' => 'x'], 1);
    }
}
