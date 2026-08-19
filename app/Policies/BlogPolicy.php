<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * P7 editorial authorization (D-070), mirroring `CatalogPolicy`.
 *
 * ⚠️ THIS FILE EXISTS BECAUSE POLICIES ARE REGISTERED PER MODEL. `Gate::policy()` binds one
 * policy to one class, so `Article`, `ArticleCategory` and `Redirect` inherited NOTHING from
 * the catalogue: without this, Filament's default would have let any AUTHENTICATED user —
 * `staff` and `customer` included — write the blog and create 301s pointing anywhere.
 *
 * A separate class rather than reusing `CatalogPolicy`: the rule is identical today, but a
 * policy named for the catalogue silently governing editorial content is the kind of coupling
 * that makes a future divergence dangerous to discover.
 */
final class BlogPolicy
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

    /**
     * Never, for anyone — the same answer `CatalogPolicy` gives.
     *
     * Destroying an article FREES ITS SLUG, and a later article inheriting that URL would
     * inherit the SEO and the backlinks of a different page. Soft delete already removes it
     * from the site while keeping the slug reserved, which is what an editor actually wants.
     */
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
