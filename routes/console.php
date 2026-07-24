<?php

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
