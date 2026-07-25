<?php

use App\Http\Controllers\AnalyticsConsentController;
use App\Http\Controllers\AnalyticsEventController;
use App\Http\Controllers\DownloadFileController;
use App\Http\Controllers\DownloadLandingController;
use App\Http\Middleware\EnsureAnalyticsSameOrigin;
use App\Http\Middleware\RequireCurrentAnalyticsConsent;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/analytics/consent', [AnalyticsConsentController::class, 'show'])
    ->name('analytics.consent.show');
Route::post('/analytics/consent', [AnalyticsConsentController::class, 'store'])
    ->middleware(EnsureAnalyticsSameOrigin::class)
    ->name('analytics.consent.store');
Route::delete('/analytics/consent', [AnalyticsConsentController::class, 'destroy'])
    ->middleware(EnsureAnalyticsSameOrigin::class)
    ->name('analytics.consent.destroy');
Route::post('/analytics/events', AnalyticsEventController::class)
    ->middleware([
        EnsureAnalyticsSameOrigin::class,
        RequireCurrentAnalyticsConsent::class,
        'throttle:analytics-ingestion',
    ])
    ->name('analytics.events.store');

Route::get('/downloads/{grantPublicId}', DownloadLandingController::class)
    ->where('grantPublicId', '[A-Za-z0-9-]+')
    ->name('downloads.exchange');

Route::match(['GET', 'HEAD'], '/downloads/{grantPublicId}/file', DownloadFileController::class)
    ->where('grantPublicId', '[A-Za-z0-9-]+')
    ->middleware('throttle:download-file')
    ->name('downloads.file');
