<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Ledger;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vereinskiste\Ledger\DateInput;

final class DateInputTest extends TestCase
{
    public function test_accepts_real_dates_within_1900_to_2999(): void
    {
        $this->assertTrue(DateInput::valid('2026-03-05'));
        $this->assertTrue(DateInput::valid('2024-02-29'));
        $this->assertTrue(DateInput::valid('1900-01-01'));
        $this->assertTrue(DateInput::valid('2999-12-31'));
    }

    /** @return array<string,array{mixed}> */
    public static function invalidProvider(): array
    {
        return [
            'year zero' => ['0000-01-01'], 'before 1900' => ['1899-12-31'], 'after 2999' => ['3000-01-01'],
            'five digit year' => ['12026-01-01'], 'feb 30' => ['2026-02-30'], 'no leap year' => ['2025-02-29'],
            'german format' => ['05.03.2026'], 'trailing newline' => ["2026-03-05\n"], 'empty' => [''],
            'garbage' => ['kaputt'], 'not a string' => [20260305], 'array' => [['2026-03-05']],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function test_rejects_impossible_or_out_of_range_dates(mixed $value): void
    {
        $this->assertFalse(DateInput::valid($value));
    }
}
