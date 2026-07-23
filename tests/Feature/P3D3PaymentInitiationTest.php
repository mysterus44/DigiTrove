<?php

declare(strict_types=1);

use App\Contracts\Payments\PaymentProvider;
use App\Contracts\Payments\ProviderInitiationRequest;
use App\Contracts\Payments\ProviderInitiationResult;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Payments\InitiatedPayment;
use App\Services\Payments\PaymentInitiationException;
use App\Services\Payments\PaymentInitiationRefusalReason as Reason;
use App\Services\Payments\PaymentInitiationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;
use Tests\Support\PhaseMigrationHarness;

uses(RefreshDatabase::class);

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
    // A pending, payable order created directly: P3-D3 is downstream of checkout
    // and only ever reads `orders`, so the factory default is enough.
    return Order::factory()->create(array_merge([
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

    expect(Payment::count())->toBe(0);
})->with([
    [OrderStatus::Paid],
    [OrderStatus::PaymentReview],
    [OrderStatus::Cancelled],
    [OrderStatus::Expired],
    [OrderStatus::Refunded],
    [OrderStatus::PartiallyRefunded],
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

    expect(Payment::count())->toBe(0);
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
// Real concurrency (two runtime PostgreSQL connections)
// ---------------------------------------------------------------------------

/**
 * @param  callable(PDO, PDO, PDO): void  $scenario  (seed, A, B)
 */
function p3d3Concurrency(callable $scenario): void
{
    $harness = new PhaseMigrationHarness('digitrove_p3d3_conc_'.strtolower(Str::random(10)));
    $migrator = config('database.connections.pgsql_migration');
    $runtime = config('database.connections.pgsql');
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

    $dsn = static fn (array $c): string => sprintf(
        'pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'] ?? 5432, $harness->databaseName(),
    );

    try {
        $harness->create();
        $harness->applyMigrationsThrough('2026_07_14_000013_create_download_logs_table.php');

        $seed = new PDO($dsn($migrator), $migrator['username'], $migrator['password'], $options);
        $a = new PDO($dsn($runtime), $runtime['username'], $runtime['password'], $options);
        $b = new PDO($dsn($runtime), $runtime['username'], $runtime['password'], $options);

        expect((string) $a->query('SELECT current_user')->fetchColumn())->toBe('digitrove_runtime')
            ->and((string) $b->query('SELECT current_user')->fetchColumn())->toBe('digitrove_runtime')
            ->and((bool) $a->query("SELECT has_database_privilege(current_user, current_database(), 'TEMP')")->fetchColumn())->toBeFalse();

        $scenario($seed, $a, $b);
    } finally {
        $harness->drop();
    }
}

function p3d3SeedPendingOrder(PDO $seed, string $number = 'DGT-2026-PAYAAAAAAA'): void
{
    // A committed order must satisfy the deferred validate_order_items_consistency
    // trigger, so it needs at least one order_item summing to its totals.
    $seed->exec("INSERT INTO products (slug, name, type, status, created_at, updated_at)
        VALUES ('pay-p', 'Pay Product', 'ebook', 'published', now(), now())");
    $seed->beginTransaction();
    $seed->exec("INSERT INTO orders (public_id, order_number, checkout_idempotency_hash, customer_email,
        subtotal_minor, discount_minor, tax_minor, total_minor, currency, status, placed_at, expires_at, created_at, updated_at)
        VALUES (gen_random_uuid(), '{$number}', '".hash('sha256', $number)."', 'pay@digitrove.test',
        5000, 0, 0, 5000, 'XOF', 'pending', now(), now() + interval '30 minutes', now(), now())");
    $seed->exec("INSERT INTO order_items (order_id, product_id, product_name_snapshot, product_slug_snapshot,
        product_type_snapshot, unit_price_minor, quantity, line_subtotal_minor, line_discount_minor,
        line_total_minor, currency, created_at, updated_at)
        SELECT o.id, p.id, 'Pay Product', 'pay-p', 'ebook', 5000, 1, 5000, 0, 5000, 'XOF', now(), now()
        FROM orders o, products p WHERE o.order_number = '{$number}' AND p.slug = 'pay-p'");
    $seed->commit();
}

function p3d3InsertPayment(PDO $pdo, int $orderRef, string $keyHash, int $attempt): string
{
    // $orderRef selects the order by its ordinal; simplest is to use the sole order.
    $pdo->exec("INSERT INTO payments (public_id, order_id, provider, idempotency_key_hash, attempt_number,
        amount_minor, currency, status, initiated_at, created_at, updated_at)
        SELECT gen_random_uuid(), o.id, '".P3D3_PROVIDER."', '{$keyHash}', {$attempt},
        5000, 'XOF', 'pending', now(), now(), now() FROM orders o LIMIT 1");

    return (string) $pdo->query("SELECT public_id FROM payments WHERE idempotency_key_hash = '{$keyHash}'")->fetchColumn();
}

it('serialises two attempts on the same order through the order row lock', function () {
    p3d3Concurrency(function (PDO $seed, PDO $a, PDO $b): void {
        p3d3SeedPendingOrder($seed);

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

        expect($blocked)->not->toBeNull('Two attempts on one order were NOT serialised.')
            ->and($blocked->getCode())->toBe('55P03');

        $b->rollBack();
        $a->rollBack();
    });
});

it('lets PostgreSQL refuse a duplicate idempotency digest across orders', function () {
    p3d3Concurrency(function (PDO $seed, PDO $a, PDO $b): void {
        p3d3SeedPendingOrder($seed);
        $digest = hash('sha256', 'shared-key-across-orders');

        $a->beginTransaction();
        p3d3InsertPayment($a, 1, $digest, 1);
        $a->commit();

        $conflict = null;
        try {
            $b->beginTransaction();
            // Same digest, second attempt number to dodge (order_id, attempt) —
            // the idempotency unique is what must fire.
            $b->exec("INSERT INTO payments (public_id, order_id, provider, idempotency_key_hash, attempt_number,
                amount_minor, currency, status, initiated_at, created_at, updated_at)
                SELECT gen_random_uuid(), o.id, '".P3D3_PROVIDER."', '{$digest}', 2,
                5000, 'XOF', 'pending', now(), now(), now() FROM orders o LIMIT 1");
            $b->commit();
        } catch (PDOException $e) {
            $conflict = $e;
            $b->rollBack();
        }

        expect($conflict)->not->toBeNull()
            ->and($conflict->getCode())->toBe('23505')
            ->and($conflict->getMessage())->toContain('payments_idempotency_key_hash_unique');
    });
});

it('lets PostgreSQL refuse a duplicate provider reference', function () {
    p3d3Concurrency(function (PDO $seed, PDO $a, PDO $b): void {
        p3d3SeedPendingOrder($seed);
        $first = p3d3InsertPayment($a, 1, hash('sha256', 'ref-key-1'), 1);
        $a->exec("UPDATE payments SET provider_payment_reference = 'DUP-REF' WHERE public_id = '{$first}'");

        $second = p3d3InsertPayment($b, 1, hash('sha256', 'ref-key-2'), 2);

        $conflict = null;
        try {
            $b->exec("UPDATE payments SET provider_payment_reference = 'DUP-REF' WHERE public_id = '{$second}'");
        } catch (PDOException $e) {
            $conflict = $e;
        }

        expect($conflict)->not->toBeNull()
            ->and($conflict->getCode())->toBe('23505')
            ->and($conflict->getMessage())->toContain('payments_provider_reference_unique');
    });
});
