<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CinetPayWebhookController;
use App\Http\Controllers\Api\DownloadAuthorizationController;
use App\Http\Controllers\Api\GeniusPayWebhookController;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (P3-D4)
|--------------------------------------------------------------------------
|
| Provider webhook ingress. These routes live in the stateless `api` middleware
| group: no session, no user auth, no CSRF token — the provider authenticates
| itself with an HMAC signature, verified inside the service. CinetPay and GeniusPay
| are wired; no PowerPay route exists.
|
| A route existing is not a provider being active: whichever driver is not selected by
| `PAYMENT_DRIVER` fails closed inside the service, before any provider call.
|
*/

Route::get('/webhooks/payments/cinetpay', [CinetPayWebhookController::class, 'health']);
Route::post('/webhooks/payments/cinetpay', [CinetPayWebhookController::class, 'handle']);

Route::get('/webhooks/payments/geniuspay', [GeniusPayWebhookController::class, 'health']);
Route::post('/webhooks/payments/geniuspay', [GeniusPayWebhookController::class, 'handle']);

Route::post('/downloads/{grantPublicId}/authorize', DownloadAuthorizationController::class)
    ->where('grantPublicId', '[A-Za-z0-9-]+')
    ->middleware([EncryptCookies::class, 'throttle:download-authorize'])
    ->name('downloads.authorize');
