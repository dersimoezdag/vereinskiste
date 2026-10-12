<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Vereinskiste\Auth\PasswordPolicy;

final class PasswordPolicyTest extends TestCase
{
    public function test_bcrypt_byte_limit_includes_multibyte_passwords(): void
    {
        $this->assertNull(PasswordPolicy::check(str_repeat('a', 72), '', ''));
        $this->assertSame('validation.password.too_many_bytes', PasswordPolicy::check(str_repeat('a', 73), '', ''));
        $this->assertNull(PasswordPolicy::check(str_repeat('ä', 36), '', ''));
        $this->assertSame('validation.password.too_many_bytes', PasswordPolicy::check(str_repeat('ä', 37), '', ''));
    }
}
