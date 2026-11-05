<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Members;

use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Members\ApplicationRepository;
use Vereinskiste\Tests\Support\ApplicationInput;
use Vereinskiste\Tests\TestCase;

final class ApplicationAddressTest extends TestCase
{
    public function test_complete_address_is_required_for_real_and_test_applications(): void
    {
        $pdo = $this->pdo();
        (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $pdo->exec("INSERT INTO users(name,email,password_hash) VALUES ('Admin','admin@example.org','hash')");
        $repo = new ApplicationRepository($pdo);
        $valid = ['first_name' => 'Erika', 'last_name' => 'Muster', 'email' => 'erika@example.org']
            + ApplicationInput::defaults($pdo);
        foreach ([false, true] as $test) {
            $invite = $repo->createInvite(1, 'Anschrift', 7, null, false, $test);
            foreach (['address', 'house_number', 'zip', 'city', 'country'] as $field) {
                foreach ([null, '', " \t\n", "\u{00a0}"] as $blank) {
                    $input = $valid;
                    if ($blank === null) { unset($input[$field]); } else { $input[$field] = $blank; }
                    try {
                        $repo->submit($invite['token'], $input);
                        $this->fail('Missing address field accepted: ' . $field);
                    } catch (ValidationError $e) {
                        $this->assertArrayHasKey($field, $e->errors);
                        $this->assertContains($e->errors[$field], ['foundation.required', 'foundation.invalid'], $field);
                    }
                }
            }
            $this->assertSame(0, $repo->findInviteByToken($invite['token'])['used_count']);
        }
        $this->assertSame(0, (int) $pdo->query('SELECT count(*) FROM membership_applications')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('SELECT count(*) FROM mail_outbox')->fetchColumn());
    }
}
