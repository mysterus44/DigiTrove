<?php

namespace App\Filament\Widgets\Concerns;

use App\Support\AnalyticsDashboardConfig;
use Illuminate\Support\Facades\Gate;

trait AuthorizesAnalyticsDashboard
{
    public static function canView(): bool
    {
        return AnalyticsDashboardConfig::available()
            && Gate::allows('viewGlobalAnalytics');
    }
}
