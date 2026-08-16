<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function restoreAny(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->role === UserRole::Admin
            && $user->status === UserStatus::Active
            && ! $user->trashed();
    }
}
