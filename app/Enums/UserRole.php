<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner   = 'owner';
    case Admin   = 'admin';
    case Staff   = 'staff';
    case Support = 'support';
    case Customer = 'customer';

    public function label(): string
    {
        return match($this) {
            self::Owner    => 'Owner',
            self::Admin    => 'Administrator',
            self::Staff    => 'Staff',
            self::Support  => 'Support',
            self::Customer => 'Customer',
        };
    }

    /** True for any internal (non-customer) role. */
    public function isStaff(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Staff, self::Support]);
    }

    /** True for roles that can manage the portal and other users. */
    public function isAdmin(): bool
    {
        return in_array($this, [self::Owner, self::Admin]);
    }

    public static function staffRoles(): array
    {
        return [self::Owner->value, self::Admin->value, self::Staff->value, self::Support->value];
    }

    public static function adminRoles(): array
    {
        return [self::Owner->value, self::Admin->value];
    }
}
