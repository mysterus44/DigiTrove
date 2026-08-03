<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;

final class CrmPolicy
{
    public function manageCustomerRelationships(User $user): bool
    {
        return $user->role === UserRole::Admin
            && $user->status === UserStatus::Active
            && ! $user->trashed();
    }
}
