<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Members;

use PHPUnit\Framework\TestCase;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Members\PersonName;

final class PersonNameTest extends TestCase
{
    public function test_reads_all_four_fields_trimmed_and_builds_the_display_name(): void
    {
        $names = PersonName::read(['salutation' => 'herr', 'title' => ' Prof. Dr. ', 'first_name' => ' Max ', 'last_name' => 'Mustermann']);
        $this->assertSame(['salutation' => 'herr', 'title' => 'Prof. Dr.', 'first_name' => 'Max', 'last_name' => 'Mustermann'], $names);
        $this->assertSame('Prof. Dr. Max Mustermann', PersonName::display($names));
        $this->assertSame('Erika Muster', PersonName::display(['first_name' => 'Erika', 'last_name' => 'Muster']));
    }

    public function test_first_and_last_name_are_required_and_salutation_is_a_whitelist(): void
    {
        foreach ([['first_name' => '', 'last_name' => 'X'], ['first_name' => 'X', 'last_name' => '  ']] as $input) {
            try {
                PersonName::read($input);
                $this->fail('Pflichtfeld');
            } catch (ValidationError $e) {
                $this->assertContains('foundation.required', $e->errors);
            }
        }
        try {
            PersonName::read(['first_name' => 'A', 'last_name' => 'B', 'salutation' => 'graf']);
            $this->fail('Anrede');
        } catch (ValidationError $e) {
            $this->assertArrayHasKey('salutation', $e->errors);
        }
    }

    public function test_legacy_single_name_field_is_split_at_the_last_space(): void
    {
        $this->assertSame(['first_name' => 'Anna Maria', 'last_name' => 'Schmidt'], PersonName::split("  Anna   Maria Schmidt "));
        $this->assertSame(['first_name' => '', 'last_name' => 'Cher'], PersonName::split('Cher'));
        $names = PersonName::read(['name' => 'Max Mustermann']);
        $this->assertSame('Max', $names['first_name']);
        $this->assertSame('Mustermann', $names['last_name']);
        $this->assertSame('Einzel', PersonName::read(['applicant_name' => 'Einzel'])['last_name']);
    }

    public function test_display_name_is_limited_to_200_characters(): void
    {
        $long = PersonName::display(['title' => str_repeat('T', 50), 'first_name' => str_repeat('V', 100), 'last_name' => str_repeat('N', 100)]);
        $this->assertSame(200, mb_strlen($long));
    }
}
