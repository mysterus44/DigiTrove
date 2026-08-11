<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;

/**
 * P6-D1 (D-058). Programme governance decides what every future commission will be worth,
 * so it is reserved to an administrator who is active and not soft-deleted — the same
 * shape as `CrmPolicy`, and deliberately NOT a new `users.role` (D-014).
 */
final class AffiliatePolicyGovernancePolicy
{
    public function manageAffiliateProgramme(User $user): bool
    {
        return $user->role === UserRole::Admin
            && $user->status === UserStatus::Active
            && ! $user->trashed();
    }
}
