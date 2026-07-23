<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CinetPayWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (P3-D4)
|--------------------------------------------------------------------------
|
| Provider webhook ingress. These routes live in the stateless `api` middleware
| group: no session, no user auth, no CSRF token — the provider authenticates
| itself with an HMAC signature, verified inside the service. Only CinetPay is
| wired; no PowerPay route exists.
|
*/

Route::get('/webhooks/payments/cinetpay', [CinetPayWebhookController::class, 'health']);
Route::post('/webhooks/payments/cinetpay', [CinetPayWebhookController::class, 'handle']);
