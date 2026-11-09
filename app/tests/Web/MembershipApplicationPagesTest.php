<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use Vereinskiste\Tests\Support\ApplicationInput;
use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Club\Profile;
use Vereinskiste\Members\ApplicationRepository;
use Vereinskiste\Tests\Receipts\ReceiptFiles;
use Vereinskiste\Tests\TestCase;

final class MembershipApplicationPagesTest extends TestCase
{
    use WebHarness;
    use ReceiptFiles;

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
            'documents_dir' => $this->scratchDir() . '/documents',
        ]);
        $this->webUser('admin@example.org', ['admin']);
    }

    protected function tearDown(): void
    {
        $this->cleanScratch();
    }

    public function test_address_fields_are_required_and_server_errors_preserve_input(): void
    {
        $invite = (new ApplicationRepository($this->pdo))->createInvite(1, 'Anschrift', 7);
        $path = '/antrag/' . $invite['token'];
        $html = (string) $this->get($path)->getBody();
        $document = new \DOMDocument();
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        foreach (['address', 'house_number', 'zip', 'city', 'country'] as $field) {
            $this->assertSame(1, $xpath->query('//input[@name="' . $field . '" and @required]')->length);
        }
        $this->assertSame('Deutschland', $xpath->query('//input[@name="country"]')->item(0)->getAttribute('value'));
        $response = $this->postForm($path, ['first_name' => 'Erika', 'last_name' => 'Muster', 'email' => 'erika@example.org',
            'address' => '', 'house_number' => '', 'zip' => '', 'city' => 'Erlangen', 'country' => 'Deutschland']);
        $this->assertSame(422, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('data-step-error', $body);
        $this->assertStringContainsString('id="antrag-address-error"', $body);
        $this->assertStringContainsString('id="antrag-house-number-error"', $body);
        $this->assertStringContainsString('name="city" value="Erlangen"', $body);
    }

    public function test_test_link_validates_without_creating_records_and_is_reusable(): void
    {
        $this->loginAs(['admin']);
        $response = $this->postForm('/members/invites/new', ['is_test' => '1']);
        $invite = $this->pdo->query('SELECT * FROM membership_application_invites ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertTrue($invite['is_test']);
        $this->assertSame('/members/invites?invite=' . $invite['id'], $response->getHeaderLine('Location'));
        $path = '/antrag/' . $invite['token'];
        $this->assertStringContainsString('Testdurchlauf', (string) $this->get($path)->getBody());
        $this->assertSame(422, $this->postForm($path, ['email' => 'invalid'])->getStatusCode());
        $tables = ['membership_applications', 'members', 'mail_outbox'];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = (int) $this->pdo->query('SELECT count(*) FROM ' . $table)->fetchColumn();
        }
        $input = [
            'name' => 'Test Person', 'email' => 'test@example.org',
            'account_holder' => 'Test Person', 'iban' => 'DE89370400440532013000',
            'sepa_mandate_consent' => '1', ...ApplicationInput::defaults($this->pdo),
        ];
        for ($run = 0; $run < 2; $run++) {
            $response = $this->postForm($path, $input);
            $this->assertSame('/antrag/danke?test=1', $response->getHeaderLine('Location'));
            $this->assertSame(200, $this->get($path)->getStatusCode());
        }
        $this->assertStringContainsString('Testdurchlauf erfolgreich', (string) $this->get('/antrag/danke?test=1')->getBody());
        foreach ($counts as $table => $count) {
            $this->assertSame($count, (int) $this->pdo->query('SELECT count(*) FROM ' . $table)->fetchColumn(), $table);
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT used_count FROM membership_application_invites WHERE id = ' . (int) $invite['id'])->fetchColumn());
    }

    public function test_public_application_with_password_and_sepa(): void
    {
        $this->loginAs(['admin']);

        $settings = new \Vereinskiste\Members\ApplicationSettings($this->pdo);
        $settings->save(true, $settings->get()['version'], (int) $this->pdo->query("SELECT id FROM users WHERE email='admin@example.org'")->fetchColumn());

        // 1. Create password-protected invite with SEPA
        $res = $this->postForm('/members/invites/new', [
            'title' => 'Familie Neumayer',
            'valid_days' => '14',
            'password' => 'Verein2026',
        ]);
        $invite = $this->pdo->query("SELECT id, token FROM membership_application_invites WHERE title = 'Familie Neumayer'")->fetch();
        $token = (string) $invite['token'];
        $this->assertNotEmpty($token);
        $this->assertSame('/members/invites?invite=' . $invite['id'], $res->getHeaderLine('Location'));
        $this->assertStringContainsString('data-copy-path="/antrag/' . $token . '" data-copied-label="Kopiert!" data-copy-auto',
            (string) $this->get($res->getHeaderLine('Location'))->getBody());

        // 2. Open public form -> asks for password
        $res = $this->get('/antrag/' . $token);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('Passwortgeschützter Mitgliedsantrag', (string) $res->getBody());

        // 3. Unlock with wrong password -> 422
        $res = $this->postForm('/antrag/' . $token . '/unlock', ['password' => 'Falsch']);
        $this->assertSame(422, $res->getStatusCode());

        // 4. Unlock with correct password -> redirects to form
        $res = $this->postForm('/antrag/' . $token . '/unlock', ['password' => 'Verein2026']);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/antrag/' . $token, $res->getHeaderLine('Location'));

        // Form is now visible with SEPA fields
        $body = (string) $this->get('/antrag/' . $token)->getBody();
        $this->assertStringContainsString('Familie Neumayer', $body);
        $this->assertStringContainsString('Bankverbindung', $body);
        $this->assertStringContainsString('SEPA-Lastschriftmandat', $body);

        // 5. Submit form
        $res = $this->postForm('/antrag/' . $token, [
            ...ApplicationInput::defaults($this->pdo),
            'name' => 'Klaus Neumayer',
            'email' => 'klaus@example.org',
            'address' => 'Hauptstraße',
            'house_number' => '1',
            'zip' => '80331',
            'city' => 'München',
            'birth_date' => '1990-05-15',
            'account_holder' => 'Klaus Neumayer',
            'iban' => 'DE89370400440532013000',
            'sepa_mandate_consent' => '1',
        ]);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/antrag/danke', $res->getHeaderLine('Location'));

        // Thank you page
        $thanks = $this->get('/antrag/danke');
        $this->assertSame(200, $thanks->getStatusCode());
        $this->assertStringContainsString('data-confetti', (string) $thanks->getBody());
        $this->assertStringContainsString('confetti.js', (string) $thanks->getBody());

        // 6. Token is one-time: opening again shows link used
        $res = $this->get('/antrag/' . $token);
        $this->assertSame(410, $res->getStatusCode());
        $this->assertStringContainsString('Die maximale Anzahl an Mitgliedsanträgen', (string) $res->getBody());

        // 7. Admin sees application and approves it
        $appId = (int) $this->pdo->query("SELECT id FROM membership_applications WHERE email = 'klaus@example.org'")->fetchColumn();
        $res = $this->get('/members/applications');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('Klaus Neumayer', (string) $res->getBody());

        // Approve
        $res = $this->postForm("/members/applications/{$appId}/approve", []);
        $this->assertSame(302, $res->getStatusCode());
        $memberId = (int) $this->pdo->query("SELECT id FROM members WHERE name = 'Klaus Neumayer'")->fetchColumn();
        $this->assertSame("/members/{$memberId}", $res->getHeaderLine('Location'));

        // Member is active
        $memberBody = (string) $this->get("/members/{$memberId}")->getBody();
        $this->assertStringContainsString('Aktiv', $memberBody);
        $this->assertStringContainsString('München', $memberBody);
    }

    public function test_honeypot_discards_bot_submissions(): void
    {
        $repo = new ApplicationRepository($this->pdo);
        $invite = $repo->createInvite(1, 'Bot Test', 7, null, false);

        $res = $this->postForm('/antrag/' . $invite['token'], [
            'name' => 'Spam Bot',
            'email' => 'bot@example.org',
            'website' => 'http://spam.com',
            ...ApplicationInput::defaults($this->pdo),
        ]);
        $this->assertSame('/antrag/danke', $res->getHeaderLine('Location'));

        // Nothing was written to database
        $this->assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM membership_applications WHERE email = 'bot@example.org'")->fetchColumn());
    }

    public function test_reject_application_records_reason(): void
    {
        $repo = new ApplicationRepository($this->pdo);
        $invite = $repo->createInvite(1, 'Test', 7, null, false);
        $appId = $repo->submit($invite['token'], [
            'name' => 'Antragsteller',
            'email' => 'test@example.org',
            ...ApplicationInput::defaults($this->pdo),
        ]);

        $this->loginAs(['admin']);
        $res = $this->postForm("/members/applications/{$appId}/reject", ['reason' => 'Leider voll']);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/members/applications', $res->getHeaderLine('Location'));

        $status = $this->pdo->query("SELECT status FROM membership_applications WHERE id = {$appId}")->fetchColumn();
        $this->assertSame('rejected', $status);
    }

    public function test_form_renders_one_step_per_active_declaration(): void
    {
        $invite = (new ApplicationRepository($this->pdo))->createInvite(1, 'Wintersemester', 7, null, true);
        $html = (string) $this->get('/antrag/' . $invite['token'])->getBody();
        $this->assertStringContainsString('data-application-stepper', $html);
        $this->assertSame(1 + 1 + 4 + 1, substr_count($html, 'data-step '), 'person, SEPA, 4 declarations, finish');
        $this->assertSame(3, substr_count($html, 'data-signature-pad'));
        $this->assertStringContainsString('Ich willige nicht ein', $html);
        $this->assertSame(3, substr_count($html, 'name="signed_place['), 'Ort und Datum bei jeder Unterschrift');
        $this->assertStringNotContainsString('System-E-Mail-Versand', $html);
        $this->assertStringNotContainsString('JJJJ-MM-TT', $html);
        $this->assertStringContainsString('max-w-3xl', $html);
        $this->assertSame(1, substr_count($html, 'brand-logo-light'), 'only the page logo, none in the card');
    }

    public function test_server_errors_keep_answers_and_signatures_and_mark_step(): void
    {
        $invite = (new ApplicationRepository($this->pdo))->createInvite(1, 'Test', 7);
        $input = ['first_name' => 'Erika', 'last_name' => 'Muster', 'email' => 'kaputt'] + ApplicationInput::defaults($this->pdo, 'yes');
        $res = $this->postForm('/antrag/' . $invite['token'], $input);
        $this->assertSame(422, $res->getStatusCode());
        $html = (string) $res->getBody();
        $first = array_key_first($input['signature']);
        $this->assertStringContainsString('value="' . $input['signature'][$first] . '"', $html);
        $this->assertMatchesRegularExpression('/id="schritt-1"[^>]*data-step-error/', $html);
        $this->assertStringContainsString('value="yes"' . "\n" . '               checked', $html);
    }

    public function test_membership_application_embeds_club_logo_and_dark_mode(): void
    {
        $invite = (new ApplicationRepository($this->pdo))->createInvite(1, 'Wintersemester', 7);

        // Club logo and dark logo
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 50 50"><rect width="50" height="50" fill="blue"/></svg>';
        $path = $this->tmpFile($svg, 'club.svg');
        $store = new \Vereinskiste\Files\DocumentStore($this->pdo, ['documents' => $this->scratchDir() . '/documents']);
        $id1 = $store->addImage($path, 'club.svg', 1);

        $darkSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 50 50"><rect width="50" height="50" fill="white"/></svg>';
        $pathDark = $this->tmpFile($darkSvg, 'club-dark.svg');
        $id2 = $store->addImage($pathDark, 'club-dark.svg', 1);

        $profile = new Profile($this->pdo);
        $version = $profile->get()['version'];
        $profile->setLogo($id1, $version, 1, 'Logo');
        $profile->setLogo($id2, $version + 1, 1, 'Dark Logo', true);

        $html = (string) $this->get('/antrag/' . $invite['token'])->getBody();
        $this->assertStringContainsString('src="/club/logo"', $html);
        $this->assertStringContainsString('src="/club/logo-dark"', $html);
        $this->assertStringContainsString('club-logo-light has-dark', $html);
        $this->assertStringContainsString('club-logo-dark', $html);
    }
}
