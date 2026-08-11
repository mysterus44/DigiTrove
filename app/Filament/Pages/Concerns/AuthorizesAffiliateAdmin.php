<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use App\Support\AffiliateConfig;
use Illuminate\Support\Facades\Gate;

/**
 * À VALIDER (P6-D1). Écrit hors environnement de test (pas de PHP 8.4 / PostgreSQL
 * disponibles) : à prouver avec `php artisan test` dès qu'un environnement capable existe.
 *
 * Fail-closed affiliate governance authorization, mirroring AuthorizesCrmAdmin. Hiding a
 * navigation entry is NOT a control: `canAccess()` is re-evaluated server-side on every
 * direct page hit, and every mutating action re-checks the Gate independently.
 *
 * The Gate itself (AffiliatePolicyGovernancePolicy) requires role=admin AND status=active
 * AND not soft-deleted, so staff, customers, suspended, deleted and guests are all refused
 * without this trait needing to enumerate them. The config flag is fail-closed too: an
 * unset or malformed flag closes the door rather than opening it.
 */
trait AuthorizesAffiliateAdmin
{
    public static function canAccess(): bool
    {
        return self::affiliateGovernanceEnabled()
            && Gate::allows('manageAffiliateProgramme');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    /** Re-checked inside every action, never assumed from page access. */
    protected function authorizeAffiliateAction(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    private static function affiliateGovernanceEnabled(): bool
    {
        try {
            return AffiliateConfig::governanceEnabled();
        } catch (\Throwable) {
            // An invalid flag must close the door, never open it.
            return false;
        }
    }
}
