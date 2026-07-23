<?php

declare(strict_types=1);

use App\Contracts\Payments\PaymentConfirmationProvider;
use App\Contracts\Payments\PaymentProvider;
use App\Payments\CinetPay\CinetPayProvider;
use App\Payments\PaymentProviderFactory;
use App\Services\Payments\PaymentConfirmationException;
use App\Services\Payments\PaymentConfirmationRefusalReason as Reason;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| P3-D4 — Fail-closed provider binding (D-034)
|--------------------------------------------------------------------------
|
| The application must boot with an empty PAYMENT_DRIVER. A provider is resolved
| only on demand, and only when fully configured; otherwise resolution throws
| ProviderConfigurationFailure and NO HTTP request is ever made. No database.
|
*/

const CINETPAY_COMPLETE = [
    'api_key' => 'k',
    'site_id' => '5872868',
    'secret_key' => 's',
    'init_url' => 'https://api-checkout.cinetpay.com/v2/payment',
    'check_url' => 'https://api-checkout.cinetpay.com/v2/payment/check',
    'channels' => 'MOBILE_MONEY',
    'lang' => 'fr',
    'connect_timeout' => 5,
    'timeout' => 15,
    'require_https' => true,
];

function bindDriver(?string $driver, array $cinetpay = CINETPAY_COMPLETE): void
{
    config(['payments.driver' => $driver, 'payments.cinetpay' => $cinetpay]);
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('resolves nothing and refuses when the driver is empty', function (): void {
    bindDriver(null);

    expect(fn () => app(PaymentProvider::class))
        ->toThrow(PaymentConfirmationException::class);
});

it('refuses an unknown driver fail-closed', function (): void {
    bindDriver('totally-unknown');

    try {
        app(PaymentConfirmationProvider::class);
        $this->fail('expected a configuration failure');
    } catch (PaymentConfirmationException $e) {
        expect($e->reason)->toBe(Reason::ProviderConfigurationFailure);
    }
});

it('refuses PowerPay because no official contract is implemented yet', function (): void {
    bindDriver('powerpay');

    try {
        app(PaymentConfirmationProvider::class);
        $this->fail('expected a configuration failure');
    } catch (PaymentConfirmationException $e) {
        expect($e->reason)->toBe(Reason::ProviderConfigurationFailure);
    }
});

it('refuses an incomplete CinetPay configuration without any HTTP request', function (): void {
    bindDriver('cinetpay', [...CINETPAY_COMPLETE, 'api_key' => '']);

    try {
        app(PaymentProvider::class);
        $this->fail('expected a configuration failure');
    } catch (PaymentConfirmationException $e) {
        expect($e->reason)->toBe(Reason::ProviderConfigurationFailure);
    }
    // preventStrayRequests would have thrown a different error had any call left.
});

it('resolves the CinetPay adapter for both ports when fully configured', function (): void {
    bindDriver('cinetpay');

    expect(app(PaymentProvider::class))->toBeInstanceOf(CinetPayProvider::class)
        ->and(app(PaymentConfirmationProvider::class))->toBeInstanceOf(CinetPayProvider::class)
        ->and(app(PaymentProviderFactory::class)->make()->name())->toBe('cinetpay');
});
