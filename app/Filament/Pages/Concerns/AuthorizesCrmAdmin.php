<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use App\Support\CrmConfig;
use Illuminate\Support\Facades\Gate;

/**
 * Fail-closed CRM admin authorization. Hiding a navigation entry is NOT a control:
 * `canAccess()` is re-evaluated server-side on every direct page hit, and every
 * mutating action re-checks the Gate independently.
 *
 * The Gate itself (P6-A0 CrmPolicy) requires role=admin AND status=active AND not
 * soft-deleted, so staff, customers, suspended, blocked, deleted and guests are all
 * refused without this trait needing to enumerate them.
 */
trait AuthorizesCrmAdmin
{
    public static function canAccess(): bool
    {
        return self::crmFoundationEnabled()
            && static::crmGateExtraCondition()
            && Gate::allows('manageCustomerRelationships');
    }

    /**
     * Extra, gate-specific precondition. Override this in a page rather than overriding
     * `canAccess()` itself.
     *
     * WHY THIS HOOK EXISTS: a trait method is flattened INTO the using class, so a page
     * that overrode `canAccess()` and called `parent::canAccess()` would silently skip
     * this trait and reach `Filament\Pages\Page::canAccess()`, which returns true. That
     * mistake opens the page to every authenticated user and looks correct while doing
     * it. Overriding this hook cannot fail that way.
     */
    protected static function crmGateExtraCondition(): bool
    {
        return true;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    /** Re-checked inside every action, never assumed from page access. */
    protected function authorizeCrmAction(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    private static function crmFoundationEnabled(): bool
    {
        try {
            return CrmConfig::enabled();
        } catch (\Throwable) {
            // An invalid flag must close the door, never open it.
            return false;
        }
    }
}
