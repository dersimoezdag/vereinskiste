<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Privacy;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Members\ApplicationRepository;
use Vereinskiste\Privacy\ApplicationPrivacy;
use Vereinskiste\Privacy\MemberPrivacy;
use Vereinskiste\Tests\Support\ApplicationInput;
use Vereinskiste\Tests\TestCase;

final class ApplicationSignaturePrivacyTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->pdo->exec("INSERT INTO users (email, name, password_hash) VALUES ('v@example.org', 'V', 'x')");
    }

    private function application(): int
    {
        $repo = new ApplicationRepository($this->pdo);
        $invite = $repo->createInvite(1, 'Test', 7);
        return $repo->submit($invite['token'], ['first_name' => 'Erika', 'last_name' => 'Muster', 'email' => 'e@example.org'] + ApplicationInput::defaults($this->pdo));
    }

    public function test_redaction_removes_signatures_and_snapshots(): void
    {
        $id = $this->application();
        Transaction::run($this->pdo, fn() => (new ApplicationPrivacy($this->pdo))->redact($id, 'Anonymisierter Antrag #' . $id));
        $row = $this->pdo->query("SELECT count(*) FILTER (WHERE signature_png IS NOT NULL) AS png, count(*) FILTER (WHERE snapshot <> '{}'::jsonb) AS snap,
            count(*) FILTER (WHERE signed_place <> '') AS place FROM application_signatures WHERE application_id = $id")->fetch();
        $this->assertSame([0, 0, 0], [(int) $row['png'], (int) $row['snap'], (int) $row['place']]);
    }

    public function test_disclosure_contains_signatures_of_member(): void
    {
        $id = $this->application();
        $member = (new ApplicationRepository($this->pdo))->approve($id, 1);
        $data = (new MemberPrivacy($this->pdo))->disclosure($member);
        $this->assertCount(4, $data['signatures']);
        $this->assertSame('Antrag auf Mitgliedschaft', $data['signatures'][0]['snapshot']['title']);
        $this->assertNotSame('', base64_decode((string) $data['signatures'][0]['signature_png'], true));
    }
}
