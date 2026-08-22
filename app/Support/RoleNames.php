<?php

namespace App\Support;

final class RoleNames
{
    public const APPLICANT = 'ROLE_APPLICANT';
    public const PROVIDER = 'ROLE_PROVIDER';
    public const ADMIN = 'ROLE_ADMIN';

    public const ALL = [self::APPLICANT, self::PROVIDER, self::ADMIN];

    private function __construct()
    {
    }

    public static function displayLabel(?string $roleName): string
    {
        return match ($roleName) {
            self::APPLICANT => 'Student',
            self::PROVIDER => 'Provider',
            self::ADMIN => 'Admin',
            null => 'User',
            default => strtolower(str_replace('ROLE_', '', $roleName)),
        };
    }

    /** Where a freshly authenticated user lands. */
    public static function dashboardUrl(?string $roleName): string
    {
        return match ($roleName) {
            self::ADMIN => '/admin/dashboard',
            self::PROVIDER => '/provider/dashboard',
            self::APPLICANT => '/applicant/dashboard',
            default => '/',
        };
    }
}
