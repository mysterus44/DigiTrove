<?php

use App\Http\Controllers\AnalyticsConsentController;
use App\Http\Controllers\AnalyticsEventController;
use App\Http\Controllers\CartResumeController;
use App\Http\Controllers\DownloadFileController;
use App\Http\Controllers\DownloadLandingController;
use App\Http\Controllers\Storefront\AffiliateTouchController;
use App\Http\Controllers\Storefront\BlogController;
use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CatalogController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\ProductController;
use App\Http\Controllers\Storefront\SitemapController;
use App\Http\Middleware\EnsureAnalyticsSameOrigin;
use App\Http\Middleware\RequireCurrentAnalyticsConsent;
use Illuminate\Support\Facades\Route;

Route::get('/', CatalogController::class)->name('storefront.home');
Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/products/{product:slug}', [ProductController::class, 'show'])->name('products.show');

// P7 blog. URLs by slug, never by id: a slug is stable, readable and carries a keyword,
// while an id leaks the publication order and can never be part of a search result.
// `/blog/categorie/{slug}` sits under its own prefix so a category can never collide with
// an article slug.
Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/categorie/{articleCategory:slug}', [BlogController::class, 'category'])->name('blog.category');
Route::get('/blog/{article:slug}', [BlogController::class, 'show'])->name('blog.show');

// Served from the database on every request rather than written to a file: a stale
// sitemap.xml on disk is a slow lie, and this one is cheap.
Route::get('/sitemap.xml', [SitemapController::class, 'sitemap'])->name('seo.sitemap');
Route::get('/robots.txt', [SitemapController::class, 'robots'])->name('seo.robots');

// P6-D2 affiliate touch capture. Both are throttled like every other public write
// surface (`analytics-ingestion`, `cart-resume`, `download-file`): the endpoints answer
// identically whatever the outcome, so the limit is what stops them being used to
// enumerate codes at volume. The `{code}` pattern mirrors `affiliate_codes_format_check`.
// `POST /affiliate/code` is the semantic pair of `GET /r/{code}`: it records a touch and
// never reads or writes a cart, so it deliberately stays OUT of the `/cart` namespace a
// P6-C contract guards — a guard that has to carry an exception says less than one that
// does not.
Route::get('/r/{code}', [AffiliateTouchController::class, 'resolveLink'])
    ->where('code', '[A-Za-z0-9]{4,32}')
    ->middleware('throttle:affiliate-touch')
    ->name('affiliate.link');
Route::post('/affiliate/code', [AffiliateTouchController::class, 'storeCode'])
    ->middleware('throttle:affiliate-touch')
    ->name('affiliate.code.store');

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
