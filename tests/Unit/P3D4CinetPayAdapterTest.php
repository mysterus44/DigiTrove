<?php

declare(strict_types=1);

use App\Contracts\Payments\NormalizedPaymentStatus;
use App\Contracts\Payments\ProviderInitiationRequest;
use App\Contracts\Payments\ProviderPaymentVerificationRequest;
use App\Contracts\Payments\ProviderWebhookEnvelope;
use App\Payments\CinetPay\CinetPayProvider;
use App\Services\Payments\PaymentConfirmationException;
use App\Services\Payments\PaymentConfirmationRefusalReason as Reason;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// The adapter uses the Http facade, so the container must be booted. No DB is
// touched (no RefreshDatabase): these are pure adapter unit tests.
uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| P3-D4 — CinetPay adapter (D-034)
|--------------------------------------------------------------------------
|
| Pure unit tests: NO database, NO real network. Every HTTP interaction is
| faked and stray requests are forbidden, so the CI never reaches CinetPay.
| The adapter is the ONLY place that knows CinetPay's endpoints, JSON shape,
| status labels and HMAC field order.
|
*/

const CINETPAY_TEST_CONFIG = [
    'api_key' => 'test-api-key',
    'site_id' => '5872868',
    'secret_key' => 'test-secret-key',
    'init_url' => 'https://api-checkout.cinetpay.com/v2/payment',
    'check_url' => 'https://api-checkout.cinetpay.com/v2/payment/check',
    'channels' => 'MOBILE_MONEY',
    'lang' => 'fr',
    'timeout' => 8,
    'connect_timeout' => 4,
    'require_https' => true,
];

function cinetPay(array $overrides = []): CinetPayProvider
{
    return new CinetPayProvider([...CINETPAY_TEST_CONFIG, ...$overrides]);
}

function cinetPayInitRequest(): ProviderInitiationRequest
{
    return new ProviderInitiationRequest(
        paymentPublicId: '11111111-1111-4111-8111-111111111111',
        orderPublicId: '22222222-2222-4222-8222-222222222222',
        amountMinor: 15000,
        currency: 'XOF',
        customerEmail: 'buyer@example.com',
    );
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('names itself cinetpay', function (): void {
    expect(cinetPay()->name())->toBe('cinetpay');
});

// ── Initiation ───────────────────────────────────────────────────────────

it('initiates against the exact CinetPay endpoint with server snapshots only', function (): void {
    Http::fake([
        'api-checkout.cinetpay.com/v2/payment' => Http::response([
            'code' => '201',
            'message' => 'CREATED',
            'data' => [
                'payment_token' => 'tok_ABC123',
                'payment_url' => 'https://checkout.cinetpay.com/pay/tok_ABC123',
            ],
        ], 200),
    ]);

    $result = cinetPay()->initiate(cinetPayInitRequest());

    expect($result->providerPaymentReference)->toBe('tok_ABC123')
        ->and($result->clientInstructions['payment_url'])->toBe('https://checkout.cinetpay.com/pay/tok_ABC123');

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->url() === 'https://api-checkout.cinetpay.com/v2/payment'
            && $request->method() === 'POST'
            && $body['transaction_id'] === '11111111-1111-4111-8111-111111111111'
            && (int) $body['amount'] === 15000
            && $body['currency'] === 'XOF'
            && $body['apikey'] === 'test-api-key'
            && $body['site_id'] === '5872868'
            && ! array_key_exists('idempotency_key', $body)
            && ! array_key_exists('idempotency_key_hash', $body);
    });
});

it('refuses an initiation response whose payment_url is not https', function (): void {
    Http::fake([
        '*' => Http::response([
            'code' => '201',
            'data' => ['payment_token' => 'tok', 'payment_url' => 'http://checkout.cinetpay.com/pay/tok'],
        ], 200),
    ]);

    expect(fn () => cinetPay()->initiate(cinetPayInitRequest()))
        ->toThrow(PaymentConfirmationException::class);
});

it('refuses an initiation response without a non-empty payment_token', function (): void {
    Http::fake([
        '*' => Http::response(['code' => '201', 'data' => ['payment_url' => 'https://x/y']], 200),
    ]);

    expect(fn () => cinetPay()->initiate(cinetPayInitRequest()))
        ->toThrow(PaymentConfirmationException::class);
});

// ── Verification (counter-call) ──────────────────────────────────────────

function cinetPayVerify(): ProviderPaymentVerificationRequest
{
    return new ProviderPaymentVerificationRequest(providerTransactionId: '11111111-1111-4111-8111-111111111111');
}

it('verifies against the exact check endpoint with credentials only in the request', function (): void {
    Http::fake([
        'api-checkout.cinetpay.com/v2/payment/check' => Http::response([
            'code' => '00',
            'message' => 'SUCCES',
            'data' => [
                'status' => 'ACCEPTED',
                'amount' => '15000',
                'currency' => 'XOF',
                'payment_method' => 'OM',
                'operator_id' => 'op_777',
            ],
        ], 200),
    ]);

    $result = cinetPay()->verifyPayment(cinetPayVerify());

    expect($result->normalizedStatus)->toBe(NormalizedPaymentStatus::Succeeded)
        ->and($result->amountMinor)->toBe(15000)
        ->and($result->currency)->toBe('XOF')
        ->and($result->provider)->toBe('cinetpay')
        ->and($result->providerPaymentReference)->toBe('op_777');

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->url() === 'https://api-checkout.cinetpay.com/v2/payment/check'
            && $body['transaction_id'] === '11111111-1111-4111-8111-111111111111'
            && $body['site_id'] === '5872868'
            && $body['apikey'] === 'test-api-key';
    });
});

it('maps every documented CinetPay status to a normalized status', function (string $cinetpayStatus, NormalizedPaymentStatus $expected): void {
    Http::fake([
        '*' => Http::response([
            'code' => '00',
            'data' => ['status' => $cinetpayStatus, 'amount' => '15000', 'currency' => 'XOF'],
        ], 200),
    ]);

    expect(cinetPay()->verifyPayment(cinetPayVerify())->normalizedStatus)->toBe($expected);
})->with([
    ['ACCEPTED', NormalizedPaymentStatus::Succeeded],
    ['WAITING_FOR_CUSTOMER', NormalizedPaymentStatus::Processing],
    ['PENDING', NormalizedPaymentStatus::Processing],
    ['REFUSED', NormalizedPaymentStatus::Failed],
    ['CANCELLED', NormalizedPaymentStatus::Cancelled],
    ['WHATEVER_UNSEEN', NormalizedPaymentStatus::Unknown],
]);

it('refuses a non-integer provider amount', function (string $amount): void {
    Http::fake([
        '*' => Http::response([
            'code' => '00',
            'data' => ['status' => 'ACCEPTED', 'amount' => $amount, 'currency' => 'XOF'],
        ], 200),
    ]);

    expect(fn () => cinetPay()->verifyPayment(cinetPayVerify()))
        ->toThrow(PaymentConfirmationException::class);
})->with([['150.00'], ['1.5e3'], ['15 000'], ['15,000'], ['abc'], ['']]);

it('sanitizes a malformed JSON verification response into a protocol failure', function (): void {
    Http::fake(['*' => Http::response('<html>not json</html>', 200)]);

    try {
        cinetPay()->verifyPayment(cinetPayVerify());
        $this->fail('expected a protocol failure');
    } catch (PaymentConfirmationException $e) {
        expect($e->reason)->toBe(Reason::ProviderProtocolFailure);
    }
});

it('sanitizes a network timeout into a provider-unavailable failure', function (): void {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    try {
        cinetPay()->verifyPayment(cinetPayVerify());
        $this->fail('expected a provider-unavailable failure');
    } catch (PaymentConfirmationException $e) {
        expect($e->reason)->toBe(Reason::ProviderUnavailable);
    }
});

it('never leaks the api key or secret in a sanitized failure', function (): void {
    Http::fake(['*' => Http::response('boom', 500)]);

    try {
        cinetPay()->verifyPayment(cinetPayVerify());
        $this->fail('expected a failure');
    } catch (PaymentConfirmationException $e) {
        expect($e->getMessage())
            ->not->toContain('test-api-key')
            ->not->toContain('test-secret-key');
    }
});

// ── HMAC webhook signature ───────────────────────────────────────────────

/**
 * Build the CinetPay HMAC string in the official field order, then sign it
 * with the test secret. Mirrors the adapter but is written independently so
 * the test proves the exact vector rather than the implementation.
 */
function cinetPaySignedParams(array $overrides = []): array
{
    $params = [
        'cpm_site_id' => '5872868',
        'cpm_trans_id' => '11111111-1111-4111-8111-111111111111',
        'cpm_trans_date' => '2026-07-23 10:00:00',
        'cpm_amount' => '15000',
        'cpm_currency' => 'XOF',
        'signature' => 'sig-token',
        'payment_method' => 'OM',
        'cel_phone_num' => '07000000',
        'cpm_phone_prefixe' => '225',
        'cpm_language' => 'fr',
        'cpm_version' => 'V4',
        'cpm_payment_config' => 'SINGLE',
        'cpm_page_action' => 'PAYMENT',
        'cpm_custom' => '',
        'cpm_designation' => 'order',
        'cpm_error_message' => '',
        ...$overrides,
    ];

    $ordered = [
        'cpm_site_id', 'cpm_trans_id', 'cpm_trans_date', 'cpm_amount', 'cpm_currency',
        'signature', 'payment_method', 'cel_phone_num', 'cpm_phone_prefixe', 'cpm_language',
        'cpm_version', 'cpm_payment_config', 'cpm_page_action', 'cpm_custom', 'cpm_designation',
        'cpm_error_message',
    ];

    $concatenated = '';
    foreach ($ordered as $key) {
        $concatenated .= $params[$key];
    }

    $params['__token'] = hash_hmac('sha256', $concatenated, 'test-secret-key');

    return $params;
}

it('accepts a webhook whose x-token matches the official field order', function (): void {
    $params = cinetPaySignedParams();
    $envelope = new ProviderWebhookEnvelope(
        headers: ['x-token' => $params['__token']],
        params: $params,
    );

    expect(cinetPay()->verifyWebhookSignature($envelope))->toBeTrue();
});

it('rejects a webhook whose signed body was tampered', function (): void {
    $params = cinetPaySignedParams();
    $envelope = new ProviderWebhookEnvelope(
        headers: ['x-token' => $params['__token']],
        params: [...$params, 'cpm_amount' => '99999'],
    );

    expect(cinetPay()->verifyWebhookSignature($envelope))->toBeFalse();
});

it('rejects a webhook without an x-token', function (): void {
    $params = cinetPaySignedParams();
    $envelope = new ProviderWebhookEnvelope(headers: [], params: $params);

    expect(cinetPay()->verifyWebhookSignature($envelope))->toBeFalse();
});
