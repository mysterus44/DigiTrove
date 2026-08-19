<?php

namespace App\Providers;

use App\Contracts\Payments\PaymentConfirmationProvider;
use App\Contracts\Payments\PaymentProvider;
use App\Events\OrderPaid;
use App\Events\RefundSucceeded;
use App\Listeners\QueueAffiliateRefundReversal;
use App\Listeners\QueueCrmOrderAttribution;
use App\Listeners\QueueSecureDelivery;
use App\Listeners\ResolveAffiliateAttribution;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFile;
use App\Payments\PaymentProviderFactory;
use App\Policies\AffiliatePolicyGovernancePolicy;
use App\Policies\AnalyticsPolicy;
use App\Policies\CatalogPolicy;
use App\Policies\CrmPolicy;
use App\Support\AnalyticsConfig;
use App\Support\DeliveryConfig;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Fail-closed, lazy payment provider resolution (P3-D4, D-034). The
        // application boots with an empty PAYMENT_DRIVER; a provider is built
        // only when one of these bindings is actually resolved, and only when
        // its configuration is complete — otherwise resolution throws a
        // ProviderConfigurationFailure and no HTTP request is ever made.
        $this->app->bind(
            PaymentProviderFactory::class,
            fn (): PaymentProviderFactory => new PaymentProviderFactory(
                (array) config('payments', []),
            ),
        );

        $this->app->bind(
            PaymentProvider::class,
            fn ($app): PaymentProvider => $app->make(PaymentProviderFactory::class)->make(),
        );

        $this->app->bind(
            PaymentConfirmationProvider::class,
            fn ($app): PaymentConfirmationProvider => $app->make(PaymentProviderFactory::class)->make(),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Product::class, CatalogPolicy::class);
        Gate::policy(Category::class, CatalogPolicy::class);
        Gate::policy(ProductFile::class, CatalogPolicy::class);
        Gate::define('viewGlobalAnalytics', [AnalyticsPolicy::class, 'viewGlobalAnalytics']);
        Gate::define('manageCustomerRelationships', [CrmPolicy::class, 'manageCustomerRelationships']);
        Gate::define('manageAffiliateProgramme', [AffiliatePolicyGovernancePolicy::class, 'manageAffiliateProgramme']);

        RateLimiter::for('analytics-ingestion', function (Request $request): Limit {
            try {
                $secret = AnalyticsConfig::ipHashKey();
                $ip = is_string($request->ip()) ? $request->ip() : '';
                $ipDigest = hash_hmac('sha256', $ip, $secret);
                $visitorDigest = hash('sha256', (string) $request->cookie(AnalyticsConfig::VISITOR_COOKIE));
                $limit = AnalyticsConfig::rateLimitPerMinute();
                $key = $ipDigest.':'.$visitorDigest;
            } catch (RuntimeException) {
                $limit = 1;
                $key = hash('sha256', 'analytics-disabled');
            }

            return Limit::perMinute($limit)
                ->by($key)
                ->response(fn () => response()->noContent());
        });

        RateLimiter::for('download-authorize', function (Request $request): Limit {
            $key = $this->downloadRateLimitKey($request);

            return Limit::perMinute(DeliveryConfig::authorizeRateLimit())
                ->by($key)
                ->response(fn () => response()->json(['message' => 'Download unavailable.'], 429));
        });

        // P6-C redemption. Bounded per IP: a capability is 256 bits, so brute force is
        // not the threat — the limit exists so the endpoint cannot be used to probe
        // which carts exist, and so a leaked link cannot be replayed at volume.
        RateLimiter::for('cart-resume', function (Request $request): Limit {
            return Limit::perMinute(20)
                ->by((string) $request->ip())
                ->response(fn () => response()->view('carts.resume-unavailable', [], 429, [
                    'Cache-Control' => 'private, no-store',
                    'Referrer-Policy' => 'no-referrer',
                    'X-Content-Type-Options' => 'nosniff',
                ]));
        });

        RateLimiter::for('download-file', function (Request $request): Limit {
            $key = $this->downloadRateLimitKey($request);

            return Limit::perMinute(DeliveryConfig::fileRateLimit())
                ->by($key)
                ->response(fn () => response('Download unavailable.', 429, [
                    'Cache-Control' => 'private, no-store',
                    'Referrer-Policy' => 'no-referrer',
                    'X-Content-Type-Options' => 'nosniff',
                ]));
        });

        // P6-D2 affiliate touch capture. Bounded per IP: the endpoints are deliberately
        // mute, so the limit is what keeps them from being walked to discover which codes
        // exist — and it caps how fast one visitor can pile up touches.
        RateLimiter::for('affiliate-touch', function (Request $request): Limit {
            return Limit::perMinute(20)->by((string) $request->ip());
        });

        // Secure delivery pipeline (P4-C0, D-035): a paid order queues an
        // order-id-only delivery job, and only when the pipeline is enabled.
        Event::listen(OrderPaid::class, QueueSecureDelivery::class);
        Event::listen(OrderPaid::class, QueueCrmOrderAttribution::class);

        // Affiliate attribution (P6-D2). Explicit listener, NOT a trigger on `orders`:
        // attribution happens at the paid transition, and no affiliate code runs inside
        // the checkout transaction of orders that have nothing to do with affiliation.
        Event::listen(OrderPaid::class, ResolveAffiliateAttribution::class);

        // Affiliate commission reversal (P6-D3). `RefundSucceeded` is emitted by
        // `RefundCompletionService` after COMMIT; the listener only dispatches a
        // refund-id-only job. ⚠️ Nothing in this repository CALLS that service yet — the
        // real refund trigger (provider port, webhook, admin action) is still to be built,
        // so this chain is testable but dormant. See HANDOFF.
        Event::listen(RefundSucceeded::class, QueueAffiliateRefundReversal::class);
    }

    private function downloadRateLimitKey(Request $request): string
    {
        $secret = config('delivery.audit.ip_hash_key');
        $ip = $request->ip() ?? '';
        $ipHash = is_string($secret) && strlen($secret) >= 32
            ? hash_hmac('sha256', $ip, $secret)
            : hash('sha256', 'delivery-disabled');
        $grantHash = hash('sha256', (string) $request->route('grantPublicId'));

        return $ipHash.':'.$grantHash;
    }
}
