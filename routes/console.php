<?php

use App\Support\AnalyticsOperationsConfig;
use App\Support\CrmConfig;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$deliverySchedules = [
    Schedule::command('downloads:reconcile')->everyTenMinutes(),
    Schedule::command('downloads:detect-abuse')->hourly(),
    Schedule::command('downloads:purge')->daily(),
    Schedule::command('downloads:metrics')->hourly(),
];

foreach ($deliverySchedules as $event) {
    $event->withoutOverlapping();

    // onOneServer requires a shared atomic-lock cache. Local array/file stores
    // deliberately remain single-process only.
    if (in_array(config('cache.default'), ['redis', 'memcached', 'database', 'dynamodb'], true)) {
        $event->onOneServer();
    }
}

if (CrmConfig::orderAttributionProcessingEnabled()) {
    $crmAttributionSchedule = Schedule::command('crm:dispatch-order-attributions')->everyFiveMinutes();
    $crmAttributionSchedule->withoutOverlapping();

    if (in_array(config('cache.default'), ['redis', 'memcached', 'database', 'dynamodb'], true)) {
        $crmAttributionSchedule->onOneServer();
    }
}

if (CrmConfig::commerceRollupRefreshProcessingEnabled()) {
    $crmRollupRefreshSchedule = Schedule::command('crm:sweep-commerce-rollup-refresh')->everyFiveMinutes();
    $crmRollupRefreshSchedule->withoutOverlapping();

    if (in_array(config('cache.default'), ['redis', 'memcached', 'database', 'dynamodb'], true)) {
        $crmRollupRefreshSchedule->onOneServer();
    }
}

if (AnalyticsOperationsConfig::enabled()) {
    $analyticsSchedules = [];

    if (AnalyticsOperationsConfig::rollupsEnabled()) {
        $analyticsSchedules[] = Schedule::command('analytics:rollup')->dailyAt('00:15');
    }

    if (AnalyticsOperationsConfig::partitionsEnabled()) {
        $analyticsSchedules[] = Schedule::command('analytics:partitions:ensure')->dailyAt('00:30');
        $analyticsSchedules[] = Schedule::command('analytics:partitions:audit')->dailyAt('00:45');
    }

    foreach ($analyticsSchedules as $event) {
        $event->withoutOverlapping();

        if (in_array(config('cache.default'), ['redis', 'memcached', 'database', 'dynamodb'], true)) {
            $event->onOneServer();
        }
    }
}
