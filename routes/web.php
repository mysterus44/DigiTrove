<?php

use App\Http\Controllers\AnalyticsConsentController;
use App\Http\Controllers\AnalyticsEventController;
use App\Http\Controllers\CartResumeController;
use App\Http\Controllers\DownloadFileController;
use App\Http\Controllers\DownloadLandingController;
use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CatalogController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\ProductController;
use App\Http\Middleware\EnsureAnalyticsSameOrigin;
use App\Http\Middleware\RequireCurrentAnalyticsConsent;
use Illuminate\Support\Facades\Route;

Route::get('/', CatalogController::class)->name('storefront.home');
Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/products/{product:slug}', [ProductController::class, 'show'])->name('products.show');

// Guest cart. GET never mutates; the two mutations are POST/DELETE and therefore carry
// the normal web CSRF boundary. No route exposes a cart id, a `public_id` or a secret:
// ownership is proved by the session's visitor, not by anything in the URL.
Route::get('/cart', [CartController::class, 'show'])->name('cart.show');
Route::post('/cart/items/{slug}', [CartController::class, 'store'])->name('cart.items.store');
Route::delete('/cart/items/{slug}', [CartController::class, 'destroy'])->name('cart.items.destroy');

// Guest checkout. `/checkout/return` is the STATIC provider return URL and carries no
// parameter, so it resolves the order from the session and forwards. Neither it nor the
// status page runs any confirmation logic: only the signed webhook can move an order to
// paid (D-034). `order_number` never appears in a path — it is human-readable by design.
Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout.show');
Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
Route::get('/checkout/return', [CheckoutController::class, 'providerReturn'])->name('checkout.return');
Route::get('/checkout/{order}/status', [CheckoutController::class, 'status'])->name('checkout.status');

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

// P6-C cart resume. The bootstrap GET receives NO secret — the capability stays in the
// URI fragment, which the browser never puts in the request line nor in `Referer`. It
// reaches the server exactly once, in the POST body below.
Route::get('/cart/resume/{cartPublicId}', [CartResumeController::class, 'show'])
    ->where('cartPublicId', '[A-Za-z0-9-]+')
    ->name('cart.resume.show');

Route::post('/cart/resume', [CartResumeController::class, 'redeem'])
    ->middleware('throttle:cart-resume')
    ->name('cart.resume.redeem');

Route::get('/cart/resumed', [CartResumeController::class, 'resumed'])
    ->name('cart.resume.done');

Route::get('/downloads/{grantPublicId}', DownloadLandingController::class)
    ->where('grantPublicId', '[A-Za-z0-9-]+')
    ->name('downloads.exchange');

Route::match(['GET', 'HEAD'], '/downloads/{grantPublicId}/file', DownloadFileController::class)
    ->where('grantPublicId', '[A-Za-z0-9-]+')
    ->middleware('throttle:download-file')
    ->name('downloads.file');
