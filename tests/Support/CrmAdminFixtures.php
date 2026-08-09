<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;

/**
 * P6-B0 admin-UI fixtures. Shared as static methods rather than per-file global
 * functions: Pest loads every test file into ONE process, so duplicated global helper
 * names would fatal at boot.
 */
final class CrmAdminFixtures
{
    public static function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);
    }

    public static function staff(): User
    {
        return User::factory()->create(['role' => UserRole::Staff, 'status' => UserStatus::Active]);
    }

    public static function customer(): User
    {
        return User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active]);
    }

    /** The stored, already-normalised address of a contact. */
    public static function emailOf(int $contactId): string
    {
        return (string) SegmentFixtures::owner()
            ->selectOne('SELECT email FROM crm_contacts WHERE id = ?', [$contactId])
            ->email;
    }
}
