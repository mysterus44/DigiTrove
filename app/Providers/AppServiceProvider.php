<?php

namespace App\Providers;

use App\Contracts\Payments\PaymentConfirmationProvider;
use App\Contracts\Payments\PaymentProvider;
use App\Events\OrderPaid;
use App\Listeners\QueueSecureDelivery;
use App\Payments\PaymentProviderFactory;
use App\Support\AnalyticsConfig;
use App\Support\DeliveryConfig;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
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

        // Secure delivery pipeline (P4-C0, D-035): a paid order queues an
        // order-id-only delivery job, and only when the pipeline is enabled.
        Event::listen(OrderPaid::class, QueueSecureDelivery::class);
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
