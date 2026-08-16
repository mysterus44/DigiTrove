<?php

declare(strict_types=1);

use App\Contracts\Payments\PaymentProvider;
use App\Contracts\Payments\ProviderInitiationRequest;
use App\Contracts\Payments\ProviderInitiationResult;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Payments\InitiatedPayment;
use App\Services\Payments\PaymentInitiationException;
use App\Services\Payments\PaymentInitiationRefusalReason as Reason;
use App\Services\Payments\PaymentInitiationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithPaymentsDatabase;

uses(InteractsWithPaymentsDatabase::class);

/*
|--------------------------------------------------------------------------
| P3-D3 — Payment Initiation (D-033)
|--------------------------------------------------------------------------
|
| Two-phase initiation on real PostgreSQL under `digitrove_runtime`:
|   1. reserve a `pending` payment attempt in a transaction, COMMIT;
|   2. call the provider OUTSIDE any transaction;
|   3. finalise the provider reference in a second transaction.
|
| These tests run WITHOUT a wrapping transaction (InteractsWithPaymentsDatabase),
| because the service refuses to run inside an ambient transaction: the provider
| must be reachable only at transaction level 0.
|
| No real adapter, no HTTP, no secret. The provider is a deterministic fake.
| No confirmation: the order stays `pending`, nothing downstream is written.
*/

const P3D3_PROVIDER = 'powerpay-sandbox';

/**
 * A deterministic fake provider. It records what it was asked, on which
 * transaction level it ran, and can be told to fail or to return a specific,
 * possibly malformed, result.
 */
function p3d3Provider(array $overrides = []): PaymentProvider
{
    return new class($overrides) implements PaymentProvider
    {
        public int $calls = 0;

        public array $seenPublicIds = [];

        public ?int $transactionLevelDuringCall = null;

        public bool $paymentVisibleDuringCall = false;

        /** @param array<string, mixed> $overrides */
        public function __construct(private array $overrides) {}

        public function name(): string
        {
            return $this->overrides['name'] ?? P3D3_PROVIDER;
        }

        public function initiate(ProviderInitiationRequest $request): ProviderInitiationResult
        {
            $this->calls++;
            $this->seenPublicIds[] = $request->paymentPublicId;
            $this->transactionLevelDuringCall = DB::transactionLevel();
            $this->paymentVisibleDuringCall = Payment::query()
                ->where('public_id', $request->paymentPublicId)->exists();

            // The provider must only ever receive server-side snapshots.
            expect($request->amountMinor)->toBeInt()
                ->and($request->currency)->toMatch('/\A[A-Z]{3}\z/');

            if ($this->overrides['throw'] ?? false) {
                throw new RuntimeException('provider network boom');
            }

            $reference = $this->overrides['reference']
                ?? 'PP-REF-'.substr($request->paymentPublicId, 0, 8);

            return new ProviderInitiationResult(
                providerPaymentReference: $reference,
                providerStatus: $this->overrides['provider_status'] ?? 'initiated',
                providerMethod: $this->overrides['provider_method'] ?? 'mobile_money',
                clientInstructions: ['ussd' => '*123#'],
            );
        }
    };
}

function p3d3Service(?PaymentProvider $provider = null): PaymentInitiationService
{
    return new PaymentInitiationService($provider ?? p3d3Provider());
}

function p3d3Key(string $seed = 'a'): string
{
    return str_pad($seed, 40, $seed === '' ? 'x' : $seed);
}

function p3d3Order(array $attributes = [], ?User $user = null, ?Visitor $visitor = null): Order
{
    // These tests run WITHOUT a wrapping transaction, so an order is COMMITTED
    // immediately and must satisfy the deferred consistency triggers on its own:
    // it needs at least one matching order_item, and a non-pending, non-free
    // order needs the payment its status implies. The setup is therefore wrapped
    // in its own transaction (committed here, BEFORE the service runs at level 0).
    return DB::transaction(function () use ($attributes, $user, $visitor): Order {
        $purchasedProductId = Product::factory()->create()->id;
        $order = Order::factory()->create(array_merge([
            'status' => OrderStatus::Pending,
            'total_minor' => 5_000,
            'subtotal_minor' => 5_000,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'currency' => 'XOF',
            'user_id' => $user?->id,
            'visitor_id' => $visitor === null ? null : $visitor->id,
            'expires_at' => now()->addMinutes(30),
            'placed_at' => now(),
        ], $attributes));

        DB::table('order_items')->insert([
            'order_id' => $order->id,
            'product_id' => null,
            'purchased_product_id' => $purchasedProductId,
            'product_name_snapshot' => 'Pay Product',
            'product_slug_snapshot' => 'pay-product',
            'product_type_snapshot' => 'ebook',
            'unit_price_minor' => $order->total_minor,
            'quantity' => 1,
            'line_subtotal_minor' => $order->total_minor,
            'line_discount_minor' => 0,
            'line_total_minor' => $order->total_minor,
            'currency' => $order->currency,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Satisfy validate_payment_order_consistency for statuses that imply a
        // captured or reviewed payment.
        $captured = [OrderStatus::Paid, OrderStatus::PartiallyRefunded, OrderStatus::Refunded];

        if (in_array($order->status, $captured, true)) {
            p3d3InsertConsistencyPayment($order, 'succeeded');
        } elseif ($order->status === OrderStatus::PaymentReview) {
            p3d3InsertConsistencyPayment($order, 'requires_review');
        }

        return $order;
    });
}

function p3d3InsertConsistencyPayment(Order $order, string $status): void
{
    $row = [
        'public_id' => (string) Str::uuid(),
        'order_id' => $order->id,
        'provider' => 'seed-provider',
        'idempotency_key_hash' => hash('sha256', 'seed-'.$order->id.'-'.$status),
        'attempt_number' => 1,
        'amount_minor' => $order->total_minor,
        'currency' => $order->currency,
        'status' => $status,
        'initiated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    // requires_review has no dedicated timestamp column; succeeded does.
    if ($status === 'succeeded') {
        $row['succeeded_at'] = now();
    }

    DB::table('payments')->insert($row);
}

function p3d3ExpectRefusal(Closure $callback, Reason $reason): void
{
    try {
        $callback();
    } catch (PaymentInitiationException $exception) {
        expect($exception->reason)->toBe($reason);

        return;
    }

    throw new RuntimeException("Expected payment refusal [{$reason->value}] but it succeeded.");
}

function p3d3AssertNoDownstream(Order $order): void
{
    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(DB::table('coupon_redemptions')->count())->toBe(0)
        ->and(DB::table('payment_webhook_events')->count())->toBe(0)
        ->and(DB::table('refunds')->count())->toBe(0)
        ->and(DB::table('download_grants')->count())->toBe(0);
}

// ---------------------------------------------------------------------------
// Environment
// ---------------------------------------------------------------------------

it('runs the payment gate under the restricted runtime role', function () {
    $identity = DB::selectOne('SELECT current_user AS role_name');

    expect($identity->role_name)->toBe('digitrove_runtime');
});

// ---------------------------------------------------------------------------
// Authority and ownership
// ---------------------------------------------------------------------------

it('initiates a payment for the owning user from order snapshots only', function () {
    $user = User::factory()->create();
    $order = p3d3Order(['customer_email' => 'owner@digitrove.test'], user: $user);
    $provider = p3d3Provider();

    $result = p3d3Service($provider)->initiate($user, $order->public_id, p3d3Key());

    expect($result)->toBeInstanceOf(InitiatedPayment::class)
        ->and($result->status)->toBe(PaymentStatus::Pending)
        ->and($result->attemptNumber)->toBe(1)
        ->and($result->amountMinor)->toBe(5_000)
        ->and($result->currency)->toBe('XOF')
        ->and($result->providerPaymentReference)->not->toBeNull();

    $payment = Payment::query()->where('order_id', $order->id)->sole();

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->provider)->toBe(P3D3_PROVIDER)
        ->and($payment->amount_minor)->toBe(5_000)
        ->and($payment->currency)->toBe('XOF')
        ->and($payment->attempt_number)->toBe(1)
        ->and($payment->idempotency_key_hash)->toBe(hash('sha256', p3d3Key()));

    // The provider only ever saw the payment's public id, never the raw key.
    expect($provider->seenPublicIds)->toBe([$payment->public_id]);

    p3d3AssertNoDownstream($order);
});

it('initiates a payment for the owning visitor', function () {
    $visitor = Visitor::factory()->create();
    $order = p3d3Order(['customer_email' => 'guest@digitrove.test'], visitor: $visitor);

    $result = p3d3Service()->initiate($visitor, $order->public_id, p3d3Key('b'));

    expect($result->status)->toBe(PaymentStatus::Pending)
        ->and(Payment::query()->where('order_id', $order->id)->count())->toBe(1);
});

it('refuses an order owned by another actor with a uniform reason', function (string $variant) {
    $owner = User::factory()->create();
    $order = p3d3Order(user: $owner);

    $caller = match ($variant) {
        'other_user' => User::factory()->create(),
        'visitor' => Visitor::factory()->create(),
    };

    p3d3ExpectRefusal(
        fn () => p3d3Service()->initiate($caller, $order->public_id, p3d3Key('c')),
        Reason::OrderUnavailable,
    );

    expect(Payment::count())->toBe(0);
})->with([['other_user'], ['visitor']]);

it('refuses an unknown order with the same reason as a foreign order', function () {
    $user = User::factory()->create();

    p3d3ExpectRefusal(
        fn () => p3d3Service()->initiate($user, (string) Str::uuid(), p3d3Key('d')),
        Reason::OrderUnavailable,
    );
});

it('never lets a provider run when the actor is not the owner', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $order = p3d3Order(user: $owner);
    $provider = p3d3Provider();

    p3d3ExpectRefusal(
        fn () => p3d3Service($provider)->initiate($intruder, $order->public_id, p3d3Key('e')),
        Reason::OrderUnavailable,
    );

    expect($provider->calls)->toBe(0);
});

// ---------------------------------------------------------------------------
// Eligibility
// ---------------------------------------------------------------------------

it('refuses a free order without creating a payment or calling the provider', function () {
    $user = User::factory()->create();
    $order = p3d3Order(['total_minor' => 0, 'subtotal_minor' => 0], user: $user);
    $provider = p3d3Provider();

    p3d3ExpectRefusal(
        fn () => p3d3Service($provider)->initiate($user, $order->public_id, p3d3Key('f')),
        Reason::FreeOrder,
    );

    expect(Payment::count())->toBe(0)
        ->and($provider->calls)->toBe(0);
});

it('refuses an expired order', function () {
    $user = User::factory()->create();
    // orders.expires_at is frozen by the immutability trigger, so the expired
    // state is created at INSERT. placed_at must stay <= expires_at
    // (orders_expiration_after_placement_check).
    $order = p3d3Order([
        'placed_at' => now()->subMinutes(2),
        'expires_at' => now()->subMinute(),
    ], user: $user);

    p3d3ExpectRefusal(
        fn () => p3d3Service()->initiate($user, $order->public_id, p3d3Key('g')),
        Reason::OrderExpired,
    );

    expect(Payment::count())->toBe(0);
});

it('refuses an order that is no longer payable', function (OrderStatus $status) {
    $user = User::factory()->create();
    // orders.status is a frozen-except-lifecycle column; set the non-payable
    // state at INSERT so no illegal UPDATE transition is attempted.
    $order = p3d3Order(['status' => $status], user: $user);

    p3d3ExpectRefusal(
        fn () => p3d3Service()->initiate($user, $order->public_id, p3d3Key('h')),
        Reason::OrderNotPayable,
    );

    // The service created nothing; any seed payment carries a different provider.
    expect(Payment::query()->where('provider', P3D3_PROVIDER)->count())->toBe(0);
})->with([
    // refunded / partially_refunded reach the same OrderNotPayable outcome but
    // would additionally require seeded refund rows (out of P3-D3 scope); Paid
    // already covers the captured-order refusal.
    [OrderStatus::Paid],
    [OrderStatus::PaymentReview],
    [OrderStatus::Cancelled],
    [OrderStatus::Expired],
]);

// ---------------------------------------------------------------------------
// Idempotency key contract
// ---------------------------------------------------------------------------

it('refuses a malformed idempotency key before any write or provider call', function (string $key) {
    $user = User::factory()->create();
    $order = p3d3Order(user: $user);
    $provider = p3d3Provider();

    p3d3ExpectRefusal(
        fn () => p3d3Service($provider)->initiate($user, $order->public_id, $key),
        Reason::InvalidIdempotencyKey,
    );

    expect(Payment::count())->toBe(0)
        ->and($provider->calls)->toBe(0);
})->with([['short'], [''], [str_repeat('a', 256)], ['has spaces inside the key here!!']]);

it('never stores or exposes the raw idempotency key', function () {
    $user = User::factory()->create();
    $order = p3d3Order(user: $user);
    $key = 'RAW-PAYMENT-IDEMPOTENCY-KEY-0123456789';

    $result = p3d3Service()->initiate($user, $order->public_id, $key);
    $payment = Payment::query()->where('order_id', $order->id)->sole();

    $row = json_encode(DB::table('payments')->where('id', $payment->id)->first());

    expect($row)->not->toContain($key)
        ->and($payment->idempotency_key_hash)->toBe(hash('sha256', $key))
        ->and($result->status)->toBe(PaymentStatus::Pending);
});

// ---------------------------------------------------------------------------
// Replay
// ---------------------------------------------------------------------------

it('returns the same payment on an identical replay without a second row', function () {
    $user = User::factory()->create();
    $order = p3d3Order(user: $user);
    $key = p3d3Key('i');

    $first = p3d3Service()->initiate($user, $order->public_id, $key);
    $second = p3d3Service()->initiate($user, $order->public_id, $key);

    expect($second->paymentPublicId)->toBe($first->paymentPublicId)
        ->and($second->attemptNumber)->toBe($first->attemptNumber)
        ->and($second->amountMinor)->toBe($first->amountMinor)
        ->and(Payment::query()->where('order_id', $order->id)->count())->toBe(1);
});

it('sends the same stable payment public id to the provider on every replay', function () {
    $user = User::factory()->create();
    $order = p3d3Order(user: $user);
    $key = p3d3Key('j');

    // A provider that fails to persist a reference the first time, forcing a
    // resume on replay with the same public id.
    $flaky = p3d3Provider(['throw' => true]);
    p3d3ExpectRefusal(
        fn () => p3d3Service($flaky)->initiate($user, $order->public_id, $key),
        Reason::ProviderUnavailable,
    );

    $healthy = p3d3Provider();
    $healthy->calls; // access to keep analyzers quiet
    $service = p3d3Service($healthy);
    $result = $service->initiate($user, $order->public_id, $key);

    $payment = Payment::query()->where('order_id', $order->id)->sole();

    expect(Payment::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and($healthy->seenPublicIds)->toBe([$payment->public_id])
        ->and($result->paymentPublicId)->toBe($payment->public_id)
        ->and($payment->provider_payment_reference)->not->toBeNull();
});

it('refuses the same key reused on a different order', function () {
    $user = User::factory()->create();
    $orderA = p3d3Order(user: $user);
    $orderB = p3d3Order(user: $user);
    $key = p3d3Key('k');

    p3d3Service()->initiate($user, $orderA->public_id, $key);

    p3d3ExpectRefusal(
        fn () => p3d3Service()->initiate($user, $orderB->public_id, $key),
        Reason::IdempotencyConflict,
    );

    expect(Payment::count())->toBe(1);
});

it('refuses the same key reused under a different provider', function () {
    $user = User::factory()->create();
    $order = p3d3Order(user: $user);
    $key = p3d3Key('l');

    p3d3Service(p3d3Provider())->initiate($user, $order->public_id, $key);

    p3d3ExpectRefusal(
        fn () => p3d3Service(p3d3Provider(['name' => 'other-gateway']))
            ->initiate($user, $order->public_id, $key),
        Reason::IdempotencyConflict,
    );

    expect(Payment::query()->where('order_id', $order->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Attempts — one live attempt per order
// ---------------------------------------------------------------------------

it('refuses a fresh key while a live attempt already exists', function () {
    $user = User::factory()->create();
    $order = p3d3Order(user: $user);

    p3d3Service()->initiate($user, $order->public_id, p3d3Key('m'));

    p3d3ExpectRefusal(
        fn () => p3d3Service()->initiate($user, $order->public_id, p3d3Key('n')),
        Reason::PaymentAlreadyInProgress,
    );

    expect(Payment::query()->where('order_id', $order->id)->count())->toBe(1);
});

it('allows a new attempt once the previous one is terminal', function (PaymentStatus $terminal) {
    $user = User::factory()->create();
    $order = p3d3Order(user: $user);

    p3d3Service()->initiate($user, $order->public_id, p3d3Key('o'));
    $first = Payment::query()->where('order_id', $order->id)->sole();
    DB::table('payments')->where('id', $first->id)->update([
        'status' => $terminal->value,
        $terminal->value.'_at' => now(),
    ]);

    $second = p3d3Service()->initiate($user, $order->public_id, p3d3Key('p'));

    expect($second->attemptNumber)->toBe(2)
        ->and(Payment::query()->where('order_id', $order->id)->count())->toBe(2);
})->with([[PaymentStatus::Failed], [PaymentStatus::Cancelled], [PaymentStatus::Expired]]);

it('never allows a new attempt once the order left the pending state', function (OrderStatus $status) {
    // A succeeded or reviewed payment always moves the order out of `pending`;
    // once that has happened, initiation is refused for the order itself,
    // independently of any lingering live-attempt check.
    $user = User::factory()->create();
    $order = p3d3Order(['status' => $status], user: $user);

    p3d3ExpectRefusal(
        fn () => p3d3Service()->initiate($user, $order->public_id, p3d3Key('r')),
        Reason::OrderNotPayable,
    );

    expect(Payment::query()->where('provider', P3D3_PROVIDER)->count())->toBe(0);
})->with([[OrderStatus::PaymentReview], [OrderStatus::Paid]]);

// ---------------------------------------------------------------------------
// Transaction boundary
// ---------------------------------------------------------------------------

it('calls the provider outside the reservation transaction', function () {
    $user = User::factory()->create();
    $order = p3d3Order(user: $user);
    $provider = p3d3Provider();

    // Under RefreshDatabase the whole test runs inside one wrapping transaction,
    // so the meaningful proof is that the provider did NOT run one level deeper:
    // the service's own reservation transaction was already committed.
    $baseline = DB::transactionLevel();

    p3d3Service($provider)->initiate($user, $order->public_id, p3d3Key('s'));

    expect($provider->calls)->toBe(1)
        ->and($provider->transactionLevelDuringCall)->toBe($baseline)
        ->and($provider->paymentVisibleDuringCall)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Provider failure and resume
// ---------------------------------------------------------------------------

it('leaves the payment pending with no reference when the provider times out', function () {
    $user = User::factory()->create();
    $order = p3d3Order(user: $user);

    p3d3ExpectRefusal(
        fn () => p3d3Service(p3d3Provider(['throw' => true]))->initiate($user, $order->public_id, p3d3Key('t')),
        Reason::ProviderUnavailable,
    );

    $payment = Payment::query()->where('order_id', $order->id)->sole();

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->provider_payment_reference)->toBeNull();

    p3d3AssertNoDownstream($order);
});

it('persists the reference on the same row when a resume succeeds', function () {
    $user = User::factory()->create();
    $order = p3d3Order(user: $user);
    $key = p3d3Key('u');

    p3d3ExpectRefusal(
        fn () => p3d3Service(p3d3Provider(['throw' => true]))->initiate($user, $order->public_id, $key),
        Reason::ProviderUnavailable,
    );
    $payment = Payment::query()->where('order_id', $order->id)->sole();

    p3d3Service(p3d3Provider(['reference' => 'RESUMED-REF-1']))->initiate($user, $order->public_id, $key);

    expect($payment->fresh()->provider_payment_reference)->toBe('RESUMED-REF-1')
        ->and(Payment::query()->where('order_id', $order->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Provider response validation and reference finalisation
// ---------------------------------------------------------------------------

it('refuses a malformed provider response and keeps the payment pending', function (array $bad) {
    $user = User::factory()->create();
    $order = p3d3Order(user: $user);

    p3d3ExpectRefusal(
        fn () => p3d3Service(p3d3Provider($bad))->initiate($user, $order->public_id, p3d3Key('v')),
        Reason::ProviderProtocolFailure,
    );

    $payment = Payment::query()->where('order_id', $order->id)->sole();

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->provider_payment_reference)->toBeNull();
})->with([
    'blank reference' => [['reference' => '   ']],
    'reference too long' => [['reference' => str_repeat('R', 256)]],
    'status too long' => [['provider_status' => str_repeat('s', 101)]],
    'method too long' => [['provider_method' => str_repeat('m', 65)]],
]);

it('finalises the reference idempotently when the same value comes back', function () {
    $user = User::factory()->create();
    $order = p3d3Order(user: $user);
    $key = p3d3Key('w');

    p3d3Service(p3d3Provider(['reference' => 'STABLE-REF']))->initiate($user, $order->public_id, $key);
    $result = p3d3Service(p3d3Provider(['reference' => 'STABLE-REF']))->initiate($user, $order->public_id, $key);

    $payment = Payment::query()->where('order_id', $order->id)->sole();

    expect($payment->provider_payment_reference)->toBe('STABLE-REF')
        ->and($result->providerPaymentReference)->toBe('STABLE-REF');
});

it('refuses to overwrite an existing reference with a different one', function () {
    $user = User::factory()->create();
    $order = p3d3Order(user: $user);

    // A provider that, while it runs (i.e. between reserve and finalise),
    // writes FIRST-REF onto the row — simulating a concurrent finalisation —
    // and then returns a DIFFERENT reference. Finalise must refuse to
    // overwrite and leave FIRST-REF intact.
    $racing = new class implements PaymentProvider
    {
        public function name(): string
        {
            return P3D3_PROVIDER;
        }

        public function initiate(ProviderInitiationRequest $request): ProviderInitiationResult
        {
            DB::table('payments')
                ->where('public_id', $request->paymentPublicId)
                ->update(['provider_payment_reference' => 'FIRST-REF']);

            return new ProviderInitiationResult(providerPaymentReference: 'SECOND-REF');
        }
    };

    p3d3ExpectRefusal(
        fn () => p3d3Service($racing)->initiate($user, $order->public_id, p3d3Key('x')),
        Reason::ProviderReferenceConflict,
    );

    expect(Payment::query()->where('order_id', $order->id)->sole()->provider_payment_reference)->toBe('FIRST-REF');
});

it('never persists provider metadata', function () {
    $user = User::factory()->create();
    $order = p3d3Order(user: $user);

    p3d3Service()->initiate($user, $order->public_id, p3d3Key('y'));

    expect(Payment::query()->where('order_id', $order->id)->sole()->provider_metadata)->toBeNull();
});

it('keeps the order pending and writes nothing downstream on success', function () {
    $user = User::factory()->create();
    $order = p3d3Order(user: $user);

    p3d3Service()->initiate($user, $order->public_id, p3d3Key('z'));

    expect(Payment::query()->where('order_id', $order->id)->sole()->status)->toBe(PaymentStatus::Pending);

    p3d3AssertNoDownstream($order);
});

// ---------------------------------------------------------------------------
// FINDING A — an ambient transaction is refused before anything happens
// ---------------------------------------------------------------------------

it('refuses to run inside an ambient transaction, touching nothing', function () {
    $user = User::factory()->create();
    $order = p3d3Order(user: $user);
    $provider = p3d3Provider();

    DB::beginTransaction();
    try {
        p3d3ExpectRefusal(
            fn () => p3d3Service($provider)->initiate($user, $order->public_id, p3d3Key('A1')),
            Reason::IntegrityFailure,
        );
    } finally {
        DB::rollBack();
    }

    expect($provider->calls)->toBe(0)
        ->and(Payment::query()->where('order_id', $order->id)->count())->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::Pending);
});

// ---------------------------------------------------------------------------
// FINDING B — eligibility uses the injected clock, never the wall clock
// ---------------------------------------------------------------------------

it('resumes a replay strictly before the injected expiry, ignoring the wall clock', function () {
    $user = User::factory()->create();
    $order = p3d3Order([
        'placed_at' => CarbonImmutable::parse('2026-07-24 11:00:00Z'),
        'expires_at' => CarbonImmutable::parse('2026-07-24 12:00:00Z'),
    ], user: $user);
    $key = p3d3Key('B1');

    // First attempt at 11:55 fails at the provider, leaving a pending row.
    p3d3ExpectRefusal(
        fn () => p3d3Service(p3d3Provider(['throw' => true]))
            ->initiate($user, $order->public_id, $key, CarbonImmutable::parse('2026-07-24 11:55:00Z')),
        Reason::ProviderUnavailable,
    );

    // Replay at 11:59 (injected) must resume even though the real clock is well
    // past 2026-07-24 — the decision uses $at, not now().
    $resume = p3d3Provider(['reference' => 'RESUMED-B1']);
    p3d3Service($resume)->initiate($user, $order->public_id, $key, CarbonImmutable::parse('2026-07-24 11:59:00Z'));

    expect($resume->calls)->toBe(1)
        ->and(Payment::query()->where('order_id', $order->id)->sole()->provider_payment_reference)->toBe('RESUMED-B1');
});

it('does not resume a replay at the exact injected expiry instant', function () {
    $user = User::factory()->create();
    $order = p3d3Order([
        'placed_at' => CarbonImmutable::parse('2026-07-24 11:00:00Z'),
        'expires_at' => CarbonImmutable::parse('2026-07-24 12:00:00Z'),
    ], user: $user);
    $key = p3d3Key('B2');

    p3d3ExpectRefusal(
        fn () => p3d3Service(p3d3Provider(['throw' => true]))
            ->initiate($user, $order->public_id, $key, CarbonImmutable::parse('2026-07-24 11:55:00Z')),
        Reason::ProviderUnavailable,
    );

    // at == expires_at: the order is expired, so the provider is NOT re-called.
    $noResume = p3d3Provider();
    $result = p3d3Service($noResume)->initiate($user, $order->public_id, $key, CarbonImmutable::parse('2026-07-24 12:00:00Z'));

    expect($noResume->calls)->toBe(0)
        ->and($result->providerPaymentReference)->toBeNull();
});

it('refuses an initial call at the exact injected expiry instant', function () {
    $user = User::factory()->create();
    $order = p3d3Order([
        'placed_at' => CarbonImmutable::parse('2026-07-24 11:00:00Z'),
        'expires_at' => CarbonImmutable::parse('2026-07-24 12:00:00Z'),
    ], user: $user);

    p3d3ExpectRefusal(
        fn () => p3d3Service()->initiate($user, $order->public_id, p3d3Key('B3'), CarbonImmutable::parse('2026-07-24 12:00:00Z')),
        Reason::OrderExpired,
    );

    expect(Payment::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// FINDING C — a lost response is recovered by an idempotent re-call
// ---------------------------------------------------------------------------

it('re-calls the provider on replay to recover lost client instructions', function () {
    $user = User::factory()->create();
    $order = p3d3Order(user: $user);
    $key = p3d3Key('C1');

    // First call succeeds end to end: reference persisted, instructions returned
    // but assumed lost before reaching the client.
    $first = p3d3Provider(['reference' => 'REF-1']);
    p3d3Service($first)->initiate($user, $order->public_id, $key);
    $payment = Payment::query()->where('order_id', $order->id)->sole();

    // Replay with the same key: the provider is re-called with the SAME public
    // id, returns the SAME reference (idempotent), and the instructions are
    // handed back — no second row, same attempt number.
    $second = p3d3Provider(['reference' => 'REF-1']);
    $result = p3d3Service($second)->initiate($user, $order->public_id, $key);

    expect($second->calls)->toBe(1)
        ->and($second->seenPublicIds)->toBe([$payment->public_id])
        ->and($result->clientInstructions)->toBe(['ussd' => '*123#'])
        ->and($result->providerPaymentReference)->toBe('REF-1')
        ->and($result->attemptNumber)->toBe($payment->attempt_number)
        ->and(Payment::query()->where('order_id', $order->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// FINDING D — no raw database or provider exception escapes the public API
// ---------------------------------------------------------------------------

it('sanitises an unexpected database failure during finalisation', function () {
    $user = User::factory()->create();
    $order = p3d3Order(user: $user);

    // A migrator-installed trigger raises a NON-23505 error on any payment
    // update, forcing finalise() down its unexpected-exception path.
    $migrator = DB::connection('pgsql_migration');
    $migrator->unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION p3d3_block_update() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN RAISE EXCEPTION USING ERRCODE = '40001', MESSAGE = 'p3d3 injected serialization failure'; END; $$;
        CREATE TRIGGER p3d3_block_update_trigger BEFORE UPDATE ON payments
        FOR EACH ROW EXECUTE FUNCTION p3d3_block_update();
        SQL);

    try {
        p3d3ExpectRefusal(
            fn () => p3d3Service(p3d3Provider(['reference' => 'REF-D1']))->initiate($user, $order->public_id, p3d3Key('D1')),
            Reason::IntegrityFailure,
        );
    } finally {
        $migrator->unprepared(
            'DROP TRIGGER IF EXISTS p3d3_block_update_trigger ON payments; DROP FUNCTION IF EXISTS p3d3_block_update();'
        );
    }
});

it('never leaks sqlstate, sql or a constraint name in a sanitised failure', function () {
    $user = User::factory()->create();
    $order = p3d3Order(user: $user);

    $migrator = DB::connection('pgsql_migration');
    $migrator->unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION p3d3_block_update() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN RAISE EXCEPTION USING ERRCODE = '40001', MESSAGE = 'p3d3 injected serialization failure'; END; $$;
        CREATE TRIGGER p3d3_block_update_trigger BEFORE UPDATE ON payments
        FOR EACH ROW EXECUTE FUNCTION p3d3_block_update();
        SQL);

    try {
        p3d3Service(p3d3Provider(['reference' => 'REF-D2']))->initiate($user, $order->public_id, p3d3Key('D2'));
    } catch (PaymentInitiationException $e) {
        expect($e->getMessage())->not->toContain('40001')
            ->and($e->getMessage())->not->toContain('update')
            ->and($e->getMessage())->not->toContain('p3d3_block_update')
            ->and($e->getMessage())->not->toContain('SQLSTATE');
    } finally {
        $migrator->unprepared(
            'DROP TRIGGER IF EXISTS p3d3_block_update_trigger ON payments; DROP FUNCTION IF EXISTS p3d3_block_update();'
        );
    }
});

it('sanitises a provider whose name accessor throws', function () {
    $user = User::factory()->create();
    $order = p3d3Order(user: $user);

    $broken = new class implements PaymentProvider
    {
        public function name(): string
        {
            throw new RuntimeException('config exploded with secret inside');
        }

        public function initiate(ProviderInitiationRequest $request): ProviderInitiationResult
        {
            throw new RuntimeException('unreachable');
        }
    };

    try {
        p3d3Service($broken)->initiate($user, $order->public_id, p3d3Key('D4'));
        throw new RuntimeException('Expected a refusal.');
    } catch (PaymentInitiationException $e) {
        expect($e->reason)->toBe(Reason::ProviderUnavailable)
            ->and($e->getMessage())->not->toContain('secret');
    }

    expect(Payment::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Defensive classification — a spoofed message is never a DB violation
// ---------------------------------------------------------------------------

it('never treats a spoofed payments constraint message as a real violation', function (string $constraint) {
    // A provider that throws an application exception whose message names a real
    // payments constraint must be sanitised to ProviderUnavailable, never
    // mistaken for a uniqueness violation.
    $user = User::factory()->create();
    $order = p3d3Order(user: $user);

    $spoofing = new class($constraint) implements PaymentProvider
    {
        public function __construct(private string $constraint) {}

        public function name(): string
        {
            return P3D3_PROVIDER;
        }

        public function initiate(ProviderInitiationRequest $request): ProviderInitiationResult
        {
            throw new RuntimeException("duplicate key value violates unique constraint \"{$this->constraint}\"");
        }
    };

    p3d3ExpectRefusal(
        fn () => p3d3Service($spoofing)->initiate($user, $order->public_id, p3d3Key('DC')),
        Reason::ProviderUnavailable,
    );

    // The attempt stays pending (ambiguous), never mislabelled.
    expect(Payment::query()->where('order_id', $order->id)->sole()->status)->toBe(PaymentStatus::Pending);
})->with([
    ['payments_idempotency_key_hash_unique'],
    ['payments_order_id_attempt_number_unique'],
    ['payments_provider_reference_unique'],
]);

// ---------------------------------------------------------------------------
// FINDING E — service-level concurrency proofs on independent connections
// ---------------------------------------------------------------------------

/** An independent runtime connection to the same testing database. */
function p3d3RuntimePdo(): PDO
{
    $c = config('database.connections.pgsql');

    return new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'] ?? 5432, $c['database']),
        $c['username'],
        $c['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function p3d3InitiationProcess(int $userId, string $orderPublicId, string $key, string $applicationName): Process
{
    $script = <<<'PHP'
        require getcwd().'/vendor/autoload.php';
        $app = require getcwd().'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        // This process is deliberately held behind an advisory lock while its
        // competitor boots and commits. Keep the database timeout below the
        // process timeout, but large enough that slow CI I/O cannot turn the
        // intended 23505 race into an unrelated 55P03 refusal.
        Illuminate\Support\Facades\DB::statement("SET lock_timeout = '90s'");
        Illuminate\Support\Facades\DB::selectOne(
            "SELECT set_config('application_name', ?, false)",
            [$argv[4]],
        );

        $provider = new class implements App\Contracts\Payments\PaymentProvider
        {
            public int $calls = 0;

            public function name(): string
            {
                return 'powerpay-sandbox';
            }

            public function initiate(App\Contracts\Payments\ProviderInitiationRequest $request): App\Contracts\Payments\ProviderInitiationResult
            {
                $this->calls++;

                return new App\Contracts\Payments\ProviderInitiationResult(
                    providerPaymentReference: 'RACE-'.substr($request->paymentPublicId, 0, 12),
                );
            }
        };

        try {
            $result = (new App\Services\Payments\PaymentInitiationService($provider))->initiate(
                App\Models\User::query()->findOrFail((int) $argv[1]),
                $argv[2],
                $argv[3],
                Carbon\CarbonImmutable::now(),
            );

            echo json_encode([
                'outcome' => 'created',
                'payment_public_id' => $result->paymentPublicId,
                'provider_calls' => $provider->calls,
            ], JSON_THROW_ON_ERROR);
        } catch (App\Services\Payments\PaymentInitiationException $exception) {
            echo json_encode([
                'outcome' => 'refused',
                'reason' => $exception->reason->value,
                'provider_calls' => $provider->calls,
            ], JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            fwrite(STDERR, $exception::class.':'.$exception->getMessage());
            exit(2);
        }
        PHP;

    return new Process(
        [PHP_BINARY, '-r', $script, (string) $userId, $orderPublicId, $key, $applicationName],
        base_path(),
        null,
        null,
        120,
    );
}

// E0 — the payment is committed and visible to an independent connection before
// the provider ever runs.
it('commits the payment before the provider call and exposes it to an independent connection', function () {
    $user = User::factory()->create();
    $order = p3d3Order(user: $user);

    $probe = new class implements PaymentProvider
    {
        public int $transactionLevelDuringCall = -1;

        public bool $visibleToIndependentConnection = false;

        public string $independentRole = '';

        public function name(): string
        {
            return P3D3_PROVIDER;
        }

        public function initiate(ProviderInitiationRequest $request): ProviderInitiationResult
        {
            $this->transactionLevelDuringCall = DB::transactionLevel();

            $pdo = p3d3RuntimePdo();
            $this->independentRole = (string) $pdo->query('SELECT current_user')->fetchColumn();
            $stmt = $pdo->prepare('SELECT status FROM payments WHERE public_id = ?');
            $stmt->execute([$request->paymentPublicId]);
            $this->visibleToIndependentConnection = $stmt->fetchColumn() === 'pending';
            $stmt = null;
            $pdo = null; // release the connection immediately

            return new ProviderInitiationResult(providerPaymentReference: 'E0-REF');
        }
    };

    p3d3Service($probe)->initiate($user, $order->public_id, p3d3Key('E0'));

    expect($probe->transactionLevelDuringCall)->toBe(0)
        ->and($probe->independentRole)->toBe('digitrove_runtime')
        ->and($probe->visibleToIndependentConnection)->toBeTrue();
});

// C1 — two keys, same order: the order lock serialises, and after it clears the
// service refuses the second key.
it('serialises attempts on one order and then refuses a second key at the service level', function () {
    $user = User::factory()->create();
    $order = p3d3Order(user: $user);

    // The lock proof: an independent runtime connection holds the order lock;
    // a second one times out with 55P03.
    $a = p3d3RuntimePdo();
    $b = p3d3RuntimePdo();
    $a->beginTransaction();
    $a->exec('SELECT id FROM orders FOR UPDATE');
    $b->exec("SET lock_timeout = '1000ms'");
    $b->beginTransaction();

    $blocked = null;
    try {
        $b->exec('SELECT id FROM orders FOR UPDATE');
    } catch (PDOException $e) {
        $blocked = $e;
    }
    $b->rollBack();
    $a->rollBack();
    $a = null;
    $b = null; // release both connections before the service calls

    expect($blocked)->not->toBeNull('The order row lock did not serialise.')
        ->and($blocked->getCode())->toBe('55P03');

    // The service outcome once the lock is free: first key creates attempt 1,
    // a second, different key is refused because that attempt is live.
    p3d3Service()->initiate($user, $order->public_id, p3d3Key('C1a'));
    p3d3ExpectRefusal(
        fn () => p3d3Service()->initiate($user, $order->public_id, p3d3Key('C1b')),
        Reason::PaymentAlreadyInProgress,
    );

    $payments = Payment::query()->where('order_id', $order->id)->get();
    expect($payments)->toHaveCount(1)
        ->and($payments->first()->attempt_number)->toBe(1);
});

// C3 — same key, two orders: the second call is translated by the service to
// IdempotencyConflict, and the digest unique is proven as the DB backstop.
it('refuses the same digest on a second order at the service level and at the index', function () {
    $user = User::factory()->create();
    $orderA = p3d3Order(user: $user);
    $orderB = p3d3Order(user: $user);
    $key = p3d3Key('C3');

    p3d3Service()->initiate($user, $orderA->public_id, $key);

    // Service translation (the committed first attempt is found on lookup).
    p3d3ExpectRefusal(
        fn () => p3d3Service()->initiate($user, $orderB->public_id, $key),
        Reason::IdempotencyConflict,
    );

    // DB backstop: a raw insert of the same digest on order B is refused by the
    // global unique with 23505 — the constraint the savepoint recovery relies on.
    $digest = hash('sha256', $key);
    $pdo = p3d3RuntimePdo();
    $conflict = null;
    try {
        $pdo->exec("INSERT INTO payments (public_id, order_id, provider, idempotency_key_hash, attempt_number,
            amount_minor, currency, status, initiated_at, created_at, updated_at)
            SELECT gen_random_uuid(), id, '".P3D3_PROVIDER."', '{$digest}', 1, total_minor, currency,
            'pending', now(), now(), now() FROM orders WHERE public_id = '{$orderB->public_id}'");
    } catch (PDOException $e) {
        $conflict = $e;
    }
    $pdo = null; // release the connection

    expect($conflict)->not->toBeNull()
        ->and($conflict->getCode())->toBe('23505')
        ->and($conflict->getMessage())->toContain('payments_idempotency_key_hash_unique')
        ->and(Payment::count())->toBe(1);
});

// C3b — force the exact lookup/INSERT race: A has already observed no digest and
// waits inside its INSERT, B commits that digest on another order, then A resumes
// into the 23505 recovery path. This is the branch that must retain the injected
// instant and translate the collision instead of falling through IntegrityFailure.
it('recovers an actual concurrent digest insert with the precise conflict reason', function () {
    $user = User::factory()->create();
    $orderA = p3d3Order(user: $user);
    $orderB = p3d3Order(user: $user);
    $key = p3d3Key('C3b');
    $digest = hash('sha256', $key);
    $advisoryKey = 3_033_235_005;

    $migrator = DB::connection('pgsql_migration');
    $migrator->unprepared(<<<SQL
        CREATE OR REPLACE FUNCTION p3d3_pause_first_digest_insert() RETURNS trigger
        LANGUAGE plpgsql AS \$\$
        BEGIN
            IF NEW.order_id = {$orderA->id} THEN
                PERFORM pg_advisory_xact_lock({$advisoryKey});
            END IF;
            RETURN NEW;
        END;
        \$\$;
        CREATE TRIGGER p3d3_pause_first_digest_insert_trigger
        BEFORE INSERT ON payments
        FOR EACH ROW EXECUTE FUNCTION p3d3_pause_first_digest_insert();
        SQL);

    $locker = p3d3RuntimePdo();
    $locker->query("SELECT pg_advisory_lock({$advisoryKey})");
    $first = p3d3InitiationProcess($user->id, (string) $orderA->public_id, $key, 'p3d3-digest-race-first');
    $second = p3d3InitiationProcess($user->id, (string) $orderB->public_id, $key, 'p3d3-digest-race-second');
    $released = false;

    try {
        $first->start();

        $waiterObserved = false;
        for ($attempt = 0; $attempt < 400; $attempt++) {
            $waiting = (int) $migrator->selectOne(
                <<<'SQL'
                    SELECT COUNT(*) AS aggregate
                    FROM pg_stat_activity
                    WHERE application_name = 'p3d3-digest-race-first'
                      AND cardinality(pg_blocking_pids(pid)) > 0
                    SQL
            )->aggregate;

            if ($waiting > 0) {
                $waiterObserved = true;
                break;
            }

            if (! $first->isRunning()) {
                break;
            }

            usleep(50_000);
        }

        expect($waiterObserved)->toBeTrue(
            'The first service call never reached the blocked INSERT. '
            .$first->getErrorOutput().$first->getOutput()
        );

        $second->start();
        $second->wait();
        expect($second->isSuccessful())->toBeTrue($second->getErrorOutput());

        $winner = json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($winner['outcome'])->toBe('created')
            ->and($winner['provider_calls'])->toBe(1)
            ->and($first->isRunning())->toBeTrue(
                'The blocked call exited before the advisory lock was released: '
                .$first->getErrorOutput().$first->getOutput()
            );

        $locker->query("SELECT pg_advisory_unlock({$advisoryKey})");
        $released = true;
        $first->wait();
        expect($first->isSuccessful())->toBeTrue($first->getErrorOutput());

        $recovered = json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($recovered['outcome'])->toBe('refused')
            ->and($recovered['reason'])->toBe(Reason::IdempotencyConflict->value)
            ->and($recovered['provider_calls'])->toBe(0)
            ->and(Payment::query()->where('idempotency_key_hash', $digest)->count())->toBe(1)
            ->and(Payment::query()->where('idempotency_key_hash', $digest)->sole()->order_id)->toBe($orderB->id);
    } finally {
        if (! $released) {
            try {
                $locker->query("SELECT pg_advisory_unlock({$advisoryKey})");
            } catch (Throwable) {
                // The process cleanup below remains mandatory even if the lock session died.
            }
        }

        $first->stop(0.1);
        $second->stop(0.1);
        $locker = null;
        $migrator->unprepared(
            'DROP TRIGGER IF EXISTS p3d3_pause_first_digest_insert_trigger ON payments; '
            .'DROP FUNCTION IF EXISTS p3d3_pause_first_digest_insert();'
        );
    }
});

// C4 — a duplicate provider reference is refused by the real finalisation path.
it('refuses a duplicate provider reference through the service finalisation path', function () {
    $user = User::factory()->create();
    $orderA = p3d3Order(user: $user);
    $orderB = p3d3Order(user: $user);

    // Both orders get the same reference from the provider: the first finalises,
    // the second hits payments_provider_reference_unique and is sanitised to
    // IntegrityFailure without leaking the constraint.
    p3d3Service(p3d3Provider(['reference' => 'SHARED-REF']))->initiate($user, $orderA->public_id, p3d3Key('C4a'));

    try {
        p3d3Service(p3d3Provider(['reference' => 'SHARED-REF']))->initiate($user, $orderB->public_id, p3d3Key('C4b'));
        throw new RuntimeException('Expected a refusal.');
    } catch (PaymentInitiationException $e) {
        expect($e->reason)->toBe(Reason::IntegrityFailure)
            ->and($e->getMessage())->not->toContain('payments_provider_reference_unique')
            ->and($e->getMessage())->not->toContain('23505');
    }

    // The winner keeps its reference; the loser stays pending with none.
    expect(Payment::query()->where('order_id', $orderA->id)->sole()->provider_payment_reference)->toBe('SHARED-REF')
        ->and(Payment::query()->where('order_id', $orderB->id)->sole()->provider_payment_reference)->toBeNull();
});
