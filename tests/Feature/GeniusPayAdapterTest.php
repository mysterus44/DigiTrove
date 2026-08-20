<?php

declare(strict_types=1);

use App\Contracts\Payments\NormalizedPaymentStatus;
use App\Contracts\Payments\PaymentConfirmationProvider;
use App\Contracts\Payments\PaymentProvider;
use App\Contracts\Payments\ProviderInitiationRequest;
use App\Contracts\Payments\ProviderPaymentVerificationRequest;
use App\Contracts\Payments\ProviderWebhookEnvelope;
use App\Payments\GeniusPay\GeniusPayProvider;
use App\Payments\PaymentProviderFactory;
use App\Services\Payments\PaymentConfirmationException;
use App\Services\Payments\PaymentConfirmationRefusalReason as Reason;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Genius Pay — adapter and fail-closed binding
|--------------------------------------------------------------------------
|
| No database. Every outbound call is faked and stray requests are forbidden, so a
| refusal that claims to happen "before any HTTP request" is PROVEN rather than asserted:
| a leaked call fails the test with a different error.
|
| The credentials here are test doubles. They carry no `_sandbox_`/`_live_` marker except
| where a test is specifically exercising the environment cross-check.
|
*/

const GENIUSPAY_WEBHOOK_SECRET = 'unit-test-geniuspay-webhook-secret';

const GENIUSPAY_BASE_URL = 'https://pay.genius.ci/api/v1/merchant';

const GENIUSPAY_REFERENCE = 'MTX-A1B2C3D4E5';

function gpConfig(array $overrides = []): array
{
    return array_merge([
        'environment' => 'sandbox',
        'api_key' => 'test-api-key',
        'api_secret' => 'test-api-secret',
        'webhook_secret' => GENIUSPAY_WEBHOOK_SECRET,
        'base_url' => GENIUSPAY_BASE_URL,
        'connect_timeout' => 5,
        'timeout' => 15,
        'webhook_tolerance_seconds' => 300,
        'require_https' => true,
    ], $overrides);
}

function gpProvider(array $overrides = []): GeniusPayProvider
{
    return new GeniusPayProvider(gpConfig($overrides));
}

function gpInitiationRequest(string $currency = 'XOF'): ProviderInitiationRequest
{
    return new ProviderInitiationRequest(
        paymentPublicId: (string) Str::uuid(),
        orderPublicId: (string) Str::uuid(),
        amountMinor: 15_000,
        currency: $currency,
        customerEmail: 'buyer@example.test',
    );
}

/** A signed envelope over EXACTLY the bytes given; `$bodyPosted` may differ to prove tampering. */
function gpEnvelope(string $bodySigned, ?string $bodyPosted = null, ?string $timestamp = null): ProviderWebhookEnvelope
{
    $timestamp ??= (string) CarbonImmutable::now()->getTimestamp();

    return new ProviderWebhookEnvelope(
        headers: [
            'x-webhook-signature' => hash_hmac('sha256', $timestamp.'.'.$bodySigned, GENIUSPAY_WEBHOOK_SECRET),
            'x-webhook-timestamp' => $timestamp,
        ],
        params: [],
        rawBody: $bodyPosted ?? $bodySigned,
    );
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('names itself geniuspay', function (): void {
    expect(gpProvider()->name())->toBe('geniuspay');
});

/*
|--------------------------------------------------------------------------
| Initiation
|--------------------------------------------------------------------------
*/

it('initiates against the exact endpoint, with both auth headers and an explicit currency', function (): void {
    Http::fake([
        'pay.genius.ci/api/v1/merchant/payments' => Http::response([
            'id' => (string) Str::uuid(),
            'reference' => GENIUSPAY_REFERENCE,
            'checkout_url' => 'https://pay.genius.ci/checkout/abc',
            'status' => 'pending',
        ], 200),
    ]);

    $result = gpProvider()->initiate(gpInitiationRequest());

    expect($result->providerPaymentReference)->toBe(GENIUSPAY_REFERENCE)
        ->and($result->clientInstructions)->toBe(['payment_url' => 'https://pay.genius.ci/checkout/abc']);

    Http::assertSent(function ($request): bool {
        return $request->url() === GENIUSPAY_BASE_URL.'/payments'
            && $request->method() === 'POST'
            && $request->header('X-API-Key') === ['test-api-key']
            && $request->header('X-API-Secret') === ['test-api-secret']
            // Explicitly present, and an INTEGER in minor units — never a float.
            && $request->data()['currency'] === 'XOF'
            && $request->data()['amount'] === 15_000;
    });
});

it('refuses a non-XOF currency before any network request', function (): void {
    // No Http::fake at all: preventStrayRequests turns any outbound call into a failure.
    try {
        gpProvider()->initiate(gpInitiationRequest('EUR'));
        test()->fail('expected a configuration failure');
    } catch (PaymentConfirmationException $e) {
        expect($e->reason)->toBe(Reason::ProviderConfigurationFailure);
    }
});

it('refuses an initiation response whose checkout url is not https', function (): void {
    Http::fake([
        'pay.genius.ci/*' => Http::response([
            'reference' => GENIUSPAY_REFERENCE,
            'checkout_url' => 'http://pay.genius.ci/checkout/abc',
        ], 200),
    ]);

    expect(fn () => gpProvider()->initiate(gpInitiationRequest()))
        ->toThrow(PaymentConfirmationException::class);
});

it('falls back to payment_url when checkout_url is absent', function (): void {
    Http::fake([
        'pay.genius.ci/*' => Http::response([
            'reference' => GENIUSPAY_REFERENCE,
            'payment_url' => 'https://pay.genius.ci/checkout/xyz',
        ], 200),
    ]);

    expect(gpProvider()->initiate(gpInitiationRequest())->clientInstructions)
        ->toBe(['payment_url' => 'https://pay.genius.ci/checkout/xyz']);
});

it('refuses an initiation response whose reference has a hostile charset', function (string $reference): void {
    Http::fake([
        'pay.genius.ci/*' => Http::response([
            'reference' => $reference,
            'checkout_url' => 'https://pay.genius.ci/checkout/abc',
        ], 200),
    ]);

    expect(fn () => gpProvider()->initiate(gpInitiationRequest()))
        ->toThrow(PaymentConfirmationException::class);
})->with(['../../admin', 'MTX/../x', 'MTX ABC', '', 'MTX.ABC']);

/*
|--------------------------------------------------------------------------
| Verification
|--------------------------------------------------------------------------
*/

function gpFakeVerification(string $status, mixed $amount = 15_000, string $currency = 'XOF'): void
{
    Http::fake([
        'pay.genius.ci/*' => Http::response([
            'data' => [
                'reference' => GENIUSPAY_REFERENCE,
                'status' => $status,
                'amount' => $amount,
                'currency' => $currency,
                'payment_method' => 'orange_money',
            ],
        ], 200),
    ]);
}

it('verifies against the exact reference endpoint with credentials only', function (): void {
    gpFakeVerification('completed');

    $result = gpProvider()->verifyPayment(new ProviderPaymentVerificationRequest(GENIUSPAY_REFERENCE));

    expect($result->normalizedStatus)->toBe(NormalizedPaymentStatus::Succeeded)
        ->and($result->amountMinor)->toBe(15_000)
        ->and($result->currency)->toBe('XOF')
        ->and($result->providerStatus)->toBe('completed');

    Http::assertSent(fn ($request): bool => $request->url() === GENIUSPAY_BASE_URL.'/payments/'.GENIUSPAY_REFERENCE
        && $request->method() === 'GET');
});

it('reads a flat verification body as well as a nested one', function (): void {
    Http::fake([
        'pay.genius.ci/*' => Http::response([
            'reference' => GENIUSPAY_REFERENCE,
            'status' => 'completed',
            'amount' => 15_000,
            'currency' => 'XOF',
        ], 200),
    ]);

    expect(gpProvider()->verifyPayment(new ProviderPaymentVerificationRequest(GENIUSPAY_REFERENCE))->normalizedStatus)
        ->toBe(NormalizedPaymentStatus::Succeeded);
});

it('maps every documented GeniusPay status onto a normalized one', function (string $vendor, NormalizedPaymentStatus $expected): void {
    gpFakeVerification($vendor);

    expect(gpProvider()->verifyPayment(new ProviderPaymentVerificationRequest(GENIUSPAY_REFERENCE))->normalizedStatus)
        ->toBe($expected);
})->with([
    ['pending', NormalizedPaymentStatus::Pending],
    ['processing', NormalizedPaymentStatus::Processing],
    ['completed', NormalizedPaymentStatus::Succeeded],
    ['failed', NormalizedPaymentStatus::Failed],
    ['cancelled', NormalizedPaymentStatus::Cancelled],
    // Terminal and unpaid. `Unknown` would strand the payment at pending for ever.
    ['expired', NormalizedPaymentStatus::Cancelled],
    // Fail-closed on purpose: neither Succeeded nor Failed is true of refunded money.
    ['refunded', NormalizedPaymentStatus::Unknown],
    ['something_new_they_shipped', NormalizedPaymentStatus::Unknown],
]);

it('refuses a non-integer provider amount', function (mixed $amount): void {
    gpFakeVerification('completed', $amount);

    expect(fn () => gpProvider()->verifyPayment(new ProviderPaymentVerificationRequest(GENIUSPAY_REFERENCE)))
        ->toThrow(PaymentConfirmationException::class);
})->with(['150.00', '1.5e3', '15 000', '15,000', 'abc', '', 150.5]);

it('refuses a hostile reference before it can reach a url path', function (string $reference): void {
    // No fake: a request escaping to the network would fail the test instead of passing it.
    expect(fn () => gpProvider()->verifyPayment(new ProviderPaymentVerificationRequest($reference)))
        ->toThrow(PaymentConfirmationException::class);
})->with(['../../../admin', 'MTX/../../x', 'MTX ABC', '', str_repeat('A', 65)]);

it('sanitizes a provider outage into a generic unavailable failure', function (): void {
    Http::fake(['pay.genius.ci/*' => Http::response('', 500)]);

    try {
        gpProvider()->verifyPayment(new ProviderPaymentVerificationRequest(GENIUSPAY_REFERENCE));
        test()->fail('expected a provider failure');
    } catch (PaymentConfirmationException $e) {
        expect($e->reason)->toBe(Reason::ProviderUnavailable);
    }
});

it('never leaks the api key or either secret in a sanitized failure', function (): void {
    Http::fake(['pay.genius.ci/*' => Http::response('not json at all', 200)]);

    try {
        gpProvider()->verifyPayment(new ProviderPaymentVerificationRequest(GENIUSPAY_REFERENCE));
        test()->fail('expected a protocol failure');
    } catch (PaymentConfirmationException $e) {
        expect($e->getMessage())
            ->not->toContain('test-api-key')
            ->not->toContain('test-api-secret')
            ->not->toContain(GENIUSPAY_WEBHOOK_SECRET);
    }
});

/*
|--------------------------------------------------------------------------
| Webhook signature — over the RAW bytes
|--------------------------------------------------------------------------
*/

it('accepts a webhook whose signature covers timestamp, dot and raw body', function (): void {
    $body = '{"id":"evt_1","event":"payment.completed","data":{"reference":"MTX-A1B2C3D4E5"}}';

    expect(gpProvider()->verifyWebhookSignature(gpEnvelope($body)))->toBeTrue();
});

it('rejects a body altered after signing, even by one insignificant space', function (): void {
    $signed = '{"id":"evt_1","event":"payment.completed"}';
    $posted = '{"id":"evt_1", "event":"payment.completed"}';

    // Semantically identical JSON. A signature checked on a re-encoded body would ACCEPT
    // this; checking the raw bytes is what makes it a rejection.
    expect(gpProvider()->verifyWebhookSignature(gpEnvelope($signed, $posted)))->toBeFalse();
});

it('rejects a webhook with no raw body available', function (): void {
    $envelope = new ProviderWebhookEnvelope(
        headers: [
            'x-webhook-signature' => hash_hmac('sha256', '1.{}', GENIUSPAY_WEBHOOK_SECRET),
            'x-webhook-timestamp' => '1',
        ],
        params: [],
        rawBody: null,
    );

    expect(gpProvider()->verifyWebhookSignature($envelope))->toBeFalse();
});

it('rejects a webhook missing either signature header', function (string $missing): void {
    $body = '{"id":"evt_1"}';
    $timestamp = (string) CarbonImmutable::now()->getTimestamp();

    $headers = [
        'x-webhook-signature' => hash_hmac('sha256', $timestamp.'.'.$body, GENIUSPAY_WEBHOOK_SECRET),
        'x-webhook-timestamp' => $timestamp,
    ];
    $headers[$missing] = '';

    expect(gpProvider()->verifyWebhookSignature(new ProviderWebhookEnvelope($headers, [], $body)))->toBeFalse();
})->with(['x-webhook-signature', 'x-webhook-timestamp']);

it('rejects a correctly signed webhook older than the replay window', function (): void {
    $body = '{"id":"evt_1"}';
    $stale = (string) CarbonImmutable::now()->subSeconds(301)->getTimestamp();

    expect(gpProvider()->verifyWebhookSignature(gpEnvelope($body, null, $stale)))->toBeFalse();
});

it('accepts a webhook just inside the replay window, and one from a slightly fast clock', function (int $offset): void {
    $body = '{"id":"evt_1"}';
    $timestamp = (string) CarbonImmutable::now()->addSeconds($offset)->getTimestamp();

    expect(gpProvider()->verifyWebhookSignature(gpEnvelope($body, null, $timestamp)))->toBeTrue();
})->with([-299, -1, 0, 60]);

it('rejects a timestamp it cannot parse rather than coercing it', function (string $timestamp): void {
    expect(gpProvider()->verifyWebhookSignature(gpEnvelope('{"id":"evt_1"}', null, $timestamp)))->toBeFalse();
})->with(['not-a-timestamp', 'NaN', '0x1234']);

it('accepts an ISO-8601 timestamp as documented fallback', function (): void {
    $timestamp = CarbonImmutable::now()->toIso8601String();

    expect(gpProvider()->verifyWebhookSignature(gpEnvelope('{"id":"evt_1"}', null, $timestamp)))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Configuration — fail-closed, and the sandbox/live cross-check
|--------------------------------------------------------------------------
*/

it('refuses an incomplete configuration, one missing key at a time', function (string $key): void {
    expect(fn () => gpProvider([$key => '']))->toThrow(PaymentConfirmationException::class);
})->with(['environment', 'api_key', 'api_secret', 'webhook_secret', 'base_url']);

it('refuses a non-https base url when https is required', function (): void {
    expect(fn () => gpProvider(['base_url' => 'http://pay.genius.ci/api/v1/merchant']))
        ->toThrow(PaymentConfirmationException::class);
});

it('refuses a live credential while the declared environment is sandbox', function (string $key): void {
    try {
        gpProvider([$key => 'sk_live_realmoney']);
        test()->fail('expected a configuration failure');
    } catch (PaymentConfirmationException $e) {
        expect($e->reason)->toBe(Reason::ProviderConfigurationFailure)
            // The refusal must not echo the credential it rejected.
            ->and($e->getMessage())->not->toContain('sk_live_realmoney');
    }
})->with(['api_key', 'api_secret', 'webhook_secret']);

it('refuses a sandbox credential while the declared environment is live', function (): void {
    expect(fn () => gpProvider(['environment' => 'live', 'api_key' => 'pk_sandbox_test']))
        ->toThrow(PaymentConfirmationException::class);
});

it('accepts matching credentials in either environment', function (string $environment, string $prefix): void {
    $provider = gpProvider([
        'environment' => $environment,
        'api_key' => $prefix.'key',
        'api_secret' => $prefix.'secret',
        'webhook_secret' => $prefix.'whsec',
    ]);

    expect($provider->name())->toBe('geniuspay');
})->with([
    ['sandbox', 'pk_sandbox_'],
    ['live', 'pk_live_'],
]);

it('refuses an unrecognised environment rather than assuming sandbox', function (string $environment): void {
    expect(fn () => gpProvider(['environment' => $environment]))
        ->toThrow(PaymentConfirmationException::class);
})->with(['production', 'SANDBOX_', 'test', 'staging']);

/*
|--------------------------------------------------------------------------
| Binding
|--------------------------------------------------------------------------
*/

it('resolves the GeniusPay adapter for both ports when fully configured', function (): void {
    config(['payments.driver' => 'geniuspay', 'payments.geniuspay' => gpConfig()]);

    expect(app(PaymentProvider::class))->toBeInstanceOf(GeniusPayProvider::class)
        ->and(app(PaymentConfirmationProvider::class))->toBeInstanceOf(GeniusPayProvider::class)
        ->and(app(PaymentProviderFactory::class)->make()->name())->toBe('geniuspay');
});

it('refuses an incomplete GeniusPay configuration without any HTTP request', function (): void {
    config(['payments.driver' => 'geniuspay', 'payments.geniuspay' => gpConfig(['api_secret' => ''])]);

    try {
        app(PaymentProvider::class);
        test()->fail('expected a configuration failure');
    } catch (PaymentConfirmationException $e) {
        expect($e->reason)->toBe(Reason::ProviderConfigurationFailure);
    }
});

it('refuses a geniuspay driver with no configuration block at all', function (): void {
    config(['payments.driver' => 'geniuspay', 'payments.geniuspay' => null]);

    expect(fn () => app(PaymentProviderFactory::class)->make())
        ->toThrow(PaymentConfirmationException::class);
});
