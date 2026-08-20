<?php

use App\Support\AffiliateConfig;
use App\Support\AnalyticsOperationsConfig;
use App\Support\CartReminderConfig;
use App\Support\CrmConfig;
use App\Support\WebhookReconciliationConfig;
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

if (CrmConfig::segmentRebuildProcessingEnabled()) {
    $crmSegmentSchedule = Schedule::command('crm:sweep-segment-generations')->everyFiveMinutes();
    $crmSegmentSchedule->withoutOverlapping();

    if (in_array(config('cache.default'), ['redis', 'memcached', 'database', 'dynamodb'], true)) {
        $crmSegmentSchedule->onOneServer();
    }
}

// H1 (dette #6) — OFF by default, gated on its own flag. The command is dry-run unless
// `--execute` is given, so the schedule passes it explicitly: a schedule that ran in
// dry-run for ever would look healthy while closing out nothing at all.
//
// ⚠️ ACTIVATION PREREQUISITE, not a build-order constraint: this raises `Log::critical`,
// and lot 4 is what makes a `critical` actually visible (rotation, level). Turning
// WEBHOOK_RECONCILIATION_ENABLED on before that means alerting into a void.
if (WebhookReconciliationConfig::enabled()) {
    $webhookReconciliation = Schedule::command('payments:reconcile-webhooks --execute')->everyFifteenMinutes();
    $webhookReconciliation->withoutOverlapping();

    if (in_array(config('cache.default'), ['redis', 'memcached', 'database', 'dynamodb'], true)) {
        $webhookReconciliation->onOneServer();
    }
}

// P6-B1 — both schedules are OFF by default and gated on their own flag, so enabling
// export processing never implicitly enables artefact deletion, and vice versa.
if (CrmConfig::exportProcessingEnabled()) {
    $crmExportSweep = Schedule::command('crm:sweep-exports')->everyFiveMinutes();
    $crmExportSweep->withoutOverlapping();

    if (in_array(config('cache.default'), ['redis', 'memcached', 'database', 'dynamodb'], true)) {
        $crmExportSweep->onOneServer();
    }
}

// P6-C — each schedule is gated on its OWN flag, so enabling detection never implicitly
// starts sending, and enabling sending never implicitly starts purging. With the
// repository defaults (all false) nothing at all is scheduled.
if (CartReminderConfig::detectionEnabled()) {
    $cartDetection = Schedule::command('crm:detect-abandoned-carts')->everyFifteenMinutes();
    $cartDetection->withoutOverlapping();

    if (in_array(config('cache.default'), ['redis', 'memcached', 'database', 'dynamodb'], true)) {
        $cartDetection->onOneServer();
    }
}

if (CartReminderConfig::enqueueEnabled()) {
    $cartEnqueue = Schedule::command('crm:enqueue-cart-reminders')->hourly();
    $cartEnqueue->withoutOverlapping();

    if (in_array(config('cache.default'), ['redis', 'memcached', 'database', 'dynamodb'], true)) {
        $cartEnqueue->onOneServer();
    }
}

if (CartReminderConfig::sendEnabled()) {
    $cartSweep = Schedule::command('crm:sweep-cart-reminders')->everyFifteenMinutes();
    $cartSweep->withoutOverlapping();

    if (in_array(config('cache.default'), ['redis', 'memcached', 'database', 'dynamodb'], true)) {
        $cartSweep->onOneServer();
    }
}

if (CartReminderConfig::purgeEnabled()) {
    $cartPurge = Schedule::command('crm:purge-cart-reminders')->daily();
    $cartPurge->withoutOverlapping();

    if (in_array(config('cache.default'), ['redis', 'memcached', 'database', 'dynamodb'], true)) {
        $cartPurge->onOneServer();
    }
}

if (CrmConfig::exportPurgeEnabled()) {
    $crmExportPurge = Schedule::command('crm:purge-expired-exports')->hourly();
    $crmExportPurge->withoutOverlapping();

    if (in_array(config('cache.default'), ['redis', 'memcached', 'database', 'dynamodb'], true)) {
        $crmExportPurge->onOneServer();
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

// P6-D3 payable promotion. Gated by the single affiliate flag — which is OFF by default —
// so the sweep does not exist until the programme is deliberately switched on.
if (AffiliateConfig::governanceEnabled()) {
    $promotionSchedule = Schedule::command('affiliate:promote-commissions')->hourly();
    $promotionSchedule->withoutOverlapping();

    if (in_array(config('cache.default'), ['redis', 'memcached', 'database', 'dynamodb'], true)) {
        $promotionSchedule->onOneServer();
    }
}
