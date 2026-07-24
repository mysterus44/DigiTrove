<?php

namespace App\Providers;

use App\Contracts\Payments\PaymentConfirmationProvider;
use App\Contracts\Payments\PaymentProvider;
use App\Events\OrderPaid;
use App\Listeners\QueueSecureDelivery;
use App\Payments\PaymentProviderFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

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
        // Secure delivery pipeline (P4-C0, D-035): a paid order queues an
        // order-id-only delivery job, and only when the pipeline is enabled.
        Event::listen(OrderPaid::class, QueueSecureDelivery::class);
    }
}
