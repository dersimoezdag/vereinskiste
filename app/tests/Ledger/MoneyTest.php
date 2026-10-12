<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Ledger;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vereinskiste\Ledger\InvalidAmount;
use Vereinskiste\Ledger\Money;

final class MoneyTest extends TestCase
{
    public function test_parse_accepts_german_comma(): void
    {
        $this->assertSame('12.50', Money::parse('12,5'));
        $this->assertSame('12.50', Money::parse('12.50'));
        $this->assertSame('12.00', Money::parse('12'));
        $this->assertSame('1234.56', Money::parse('1.234,56'));
        $this->assertSame('1234567.89', Money::parse(' 1.234.567,89 '));
        $this->assertSame('0.05', Money::parse('0,05'));
    }

    /** @return array<string,array{string}> */
    public static function invalidProvider(): array
    {
        return [
            'zero' => ['0'], 'zero decimals' => ['0,00'], 'negative' => ['-5'], 'negative comma' => ['-0,01'],
            'three decimals' => ['1,234'], 'three decimals dot' => ['1.234'], 'empty' => [''],
            'letters' => ['abc'], 'english thousands' => ['1,234.56'], 'bad grouping' => ['1.2,3'],
            'two commas' => ['1,2,3'],
            'trailing newline' => ["12\n"], 'trailing newline comma' => ["12,50\n"],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function test_parse_rejects_zero_negative_and_three_decimals(string $input): void
    {
        $this->expectException(InvalidAmount::class);
        Money::parse($input);
    }

    public function test_parse_rejects_above_9999999_99(): void
    {
        $this->assertSame('9999999.99', Money::parse('9.999.999,99'));
        $this->expectException(InvalidAmount::class);
        Money::parse('10.000.000,00');
    }

    public function test_parse_signed_allows_negative_and_zero(): void
    {
        $this->assertSame('-1234.50', Money::parseSigned('-1.234,5'));
        $this->assertSame('0.00', Money::parseSigned('-0'));
        $this->assertSame('0.00', Money::parseSigned('0'));
    }

    public function test_cents_roundtrip(): void
    {
        $this->assertSame(1250, Money::toCents('12.50'));
        $this->assertSame('-0.05', Money::fromCents(-5));
    }
}
