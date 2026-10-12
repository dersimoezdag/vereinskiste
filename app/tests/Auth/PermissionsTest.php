<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Vereinskiste\Auth\Permissions;

final class PermissionsTest extends TestCase
{
    public function test_kassenpruefer_cannot_write_bookings(): void
    {
        $p = Permissions::for(['kassenpruefer']);

        $this->assertContains('bookings.read', $p);
        $this->assertNotContains('bookings.write', $p);
        $this->assertNotContains('members.write', $p);
        $this->assertNotContains('users.manage', $p);
    }

    public function test_user_with_two_roles_gets_union_of_permissions(): void
    {
        $p = Permissions::for(['kassenwart', 'schriftfuehrer']);

        foreach (['bookings.read', 'bookings.write', 'members.read', 'members.write'] as $right) {
            $this->assertContains($right, $p);
        }
        $this->assertNotContains('users.manage', $p);
        $this->assertSame(array_values(array_unique($p)), $p);
    }

    public function test_user_without_roles_has_no_permissions(): void
    {
        $this->assertSame([], Permissions::for([]));
        $this->assertSame([], Permissions::for(['unbekannt']));
    }

    public function test_vorstand_has_every_permission(): void
    {
        $this->assertEqualsCanonicalizing(Permissions::ALL, Permissions::for(['vorstand']));
    }

    public function test_admin_is_a_supported_role_with_every_permission(): void
    {
        $this->assertContains('admin', Permissions::ROLES);
        $this->assertEqualsCanonicalizing(Permissions::ALL, Permissions::for(['admin']));
    }
}
