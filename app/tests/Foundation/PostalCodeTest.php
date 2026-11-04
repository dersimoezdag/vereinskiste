<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Foundation;

use PHPUnit\Framework\TestCase;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Ledger\ValidationError;

final class PostalCodeTest extends TestCase
{
    public function test_german_postcodes_require_exactly_five_digits_and_preserve_leading_zeroes(): void
    {
        foreach (['1234', '123456', 'abcde', '123\n4'] as $zip) {
            try {
                Fields::read(['zip' => $zip, 'country' => 'Deutschland'], ['zip' => 20, 'country' => 100]);
                $this->fail('Invalid postcode accepted');
            } catch (ValidationError $error) {
                $this->assertArrayHasKey('zip', $error->errors);
            }
        }
        $this->assertSame('01067', Fields::read(['zip' => '01067'], ['zip' => 20])['zip']);
        $this->assertSame('', Fields::read([], ['zip' => 20])['zip']);
        $this->assertSame('SW1A 1AA', Fields::read(['zip' => 'SW1A 1AA', 'country' => 'UK'], ['zip' => 20, 'country' => 100])['zip']);
    }
}
