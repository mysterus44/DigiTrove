<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;
use Tests\Support\PhaseMigrationHarness;

uses(RefreshDatabase::class);

function forceP3CAConstraints(): void
{
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
}

function expectP3CAQueryException(Closure $callback, string $sqlState, string $messageFragment): void
{
    $exception = null;

    try {
        DB::transaction(function () use ($callback): void {
            $callback();
            forceP3CAConstraints();
        });
    } catch (QueryException $queryException) {
        $exception = $queryException;
    } finally {
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    expect($exception)->not->toBeNull()
        ->and((string) $exception->getCode())->toBe($sqlState)
        ->and($exception->getMessage())->toContain($messageFragment);
}

function expectP3CACheckViolation(Closure $callback, string $constraintName): void
{
    expectP3CAQueryException($callback, '23514', $constraintName);
}

function expectP3CAUniqueViolation(Closure $callback, string $constraintName): void
{
    expectP3CAQueryException($callback, '23505', $constraintName);
}

function expectP3CATriggerViolation(Closure $callback, string $messageFragment): void
{
    expectP3CAQueryException($callback, '23514', $messageFragment);
}

function expectP3CADeferredViolation(Closure $callback, string $messageFragment): void
{
    expectP3CAQueryException($callback, '23514', $messageFragment);
}

function createPendingOrderWithItem(array $orderAttributes = []): Order
{
    return DB::transaction(function () use ($orderAttributes): Order {
        $product = Product::factory()->create();
        $order = Order::factory()->create(array_merge(['status' => OrderStatus::Pending], $orderAttributes));

        OrderItem::factory()->forOrder($order)->forProduct($product)->create([
            'unit_price_minor' => $order->subtotal_minor,
            'quantity' => 1,
            'line_subtotal_minor' => $order->subtotal_minor,
            'line_discount_minor' => $order->discount_minor,
            'line_total_minor' => $order->subtotal_minor - $order->discount_minor,
            'currency' => $order->currency,
        ]);

        forceP3CAConstraints();

        return $order;
    });
}

function createFreeOrderWithItem(OrderStatus $status = OrderStatus::Paid): Order
{
    return DB::transaction(function () use ($status): Order {
        $product = Product::factory()->create();
        $order = Order::factory()->create([
            'subtotal_minor' => 0,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 0,
            'status' => $status,
            'paid_at' => $status === OrderStatus::Paid ? now() : null,
        ]);

        OrderItem::factory()->forOrder($order)->forProduct($product)->create([
            'unit_price_minor' => 0,
            'quantity' => 1,
            'line_subtotal_minor' => 0,
            'line_discount_minor' => 0,
            'line_total_minor' => 0,
            'currency' => $order->currency,
        ]);

        forceP3CAConstraints();

        return $order;
    });
}

/**
 * Raw payment row bypassing the Eloquent enum cast, so DB-level CHECK constraints
 * (e.g. an out-of-enum status) can be exercised directly.
 */
function rawPaymentRow(Order $order, array $overrides = []): array
{
    return array_merge([
        'public_id' => (string) Str::uuid(),
        'order_id' => $order->id,
        'provider' => 'powerpay',
        'provider_payment_reference' => null,
        'idempotency_key_hash' => hash('sha256', 'raw-'.Str::uuid()),
        'attempt_number' => 1,
        'amount_minor' => $order->total_minor,
        'currency' => $order->currency,
        'status' => 'pending',
        'provider_metadata' => null,
        'initiated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function requiredOrderStatusForPayment(PaymentStatus $status): OrderStatus
{
    return match ($status) {
        PaymentStatus::Succeeded => OrderStatus::Paid,
        PaymentStatus::RequiresReview => OrderStatus::PaymentReview,
        default => OrderStatus::Pending,
    };
}

function paymentFactoryInStatus(Order $order, PaymentStatus $status): Payment
{
    $factory = Payment::factory()->forOrder($order);

    $factory = match ($status) {
        PaymentStatus::Pending => $factory,
        PaymentStatus::Processing => $factory->processing(),
        PaymentStatus::RequiresReview => $factory->requiresReview(),
        PaymentStatus::Succeeded => $factory->succeeded(),
        PaymentStatus::Failed => $factory->failed(),
        PaymentStatus::Cancelled => $factory->cancelled(),
        PaymentStatus::Expired => $factory->expired(),
    };

    return $factory->create();
}

/**
 * @return array{order: Order, payment: Payment}
 */
function makeOrderWithPaymentInStatus(PaymentStatus $paymentStatus): array
{
    return DB::transaction(function () use ($paymentStatus): array {
        $orderStatus = requiredOrderStatusForPayment($paymentStatus);
        $product = Product::factory()->create();
        $order = Order::factory()->create([
            'status' => $orderStatus,
            'paid_at' => $orderStatus === OrderStatus::Paid ? now() : null,
        ]);

        OrderItem::factory()->forOrder($order)->forProduct($product)->create([
            'unit_price_minor' => $order->subtotal_minor,
            'quantity' => 1,
            'line_subtotal_minor' => $order->subtotal_minor,
            'line_discount_minor' => $order->discount_minor,
            'line_total_minor' => $order->subtotal_minor - $order->discount_minor,
            'currency' => $order->currency,
        ]);

        $payment = paymentFactoryInStatus($order, $paymentStatus);

        forceP3CAConstraints();

        return ['order' => $order->refresh(), 'payment' => $payment->refresh()];
    });
}

function assertAllowedPaymentTransition(PaymentStatus $from, PaymentStatus $to): void
{
    ['order' => $order, 'payment' => $payment] = makeOrderWithPaymentInStatus($from);

    DB::transaction(function () use ($order, $payment, $to): void {
        $targetOrderStatus = requiredOrderStatusForPayment($to);

        if ($order->status !== $targetOrderStatus) {
            $order->update([
                'status' => $targetOrderStatus,
                'paid_at' => $targetOrderStatus === OrderStatus::Paid ? now() : $order->paid_at,
            ]);
        }

        DB::table('payments')->where('id', $payment->id)->update(['status' => $to->value]);
        forceP3CAConstraints();
    });

    expect(DB::table('payments')->where('id', $payment->id)->value('status'))->toBe($to->value);
}

function assertForbiddenPaymentTransition(PaymentStatus $from, PaymentStatus $to): void
{
    ['payment' => $payment] = makeOrderWithPaymentInStatus($from);

    expectP3CATriggerViolation(
        fn () => DB::table('payments')->where('id', $payment->id)->update(['status' => $to->value]),
        'payments status transition is not allowed',
    );
}

it('runs P3C-A schema tests against PostgreSQL', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

it('creates the payments table with native types while later P3C tables stay absent', function () {
    expect(Schema::hasTable('payments'))->toBeTrue();

    expect(Schema::hasColumns('payments', [
        'id',
        'public_id',
        'order_id',
        'provider',
        'provider_payment_reference',
        'idempotency_key_hash',
        'attempt_number',
        'amount_minor',
        'currency',
        'status',
        'provider_status',
        'provider_method',
        'provider_metadata',
        'failure_code',
        'failure_message_sanitized',
        'initiated_at',
        'processing_at',
        'succeeded_at',
        'failed_at',
        'cancelled_at',
        'expired_at',
        'last_verified_at',
        'created_at',
        'updated_at',
    ]))->toBeTrue();

    $columns = DB::table('information_schema.columns')
        ->select('column_name', 'data_type', 'udt_name', 'character_maximum_length')
        ->where('table_schema', 'public')
        ->where('table_name', 'payments')
        ->get()
        ->keyBy('column_name');

    expect($columns['public_id']->data_type)->toBe('uuid')
        ->and($columns['order_id']->data_type)->toBe('bigint')
        ->and($columns['provider']->data_type)->toBe('character varying')
        ->and($columns['provider']->character_maximum_length)->toBe(32)
        ->and($columns['idempotency_key_hash']->character_maximum_length)->toBe(64)
        ->and($columns['currency']->character_maximum_length)->toBe(3)
        ->and($columns['attempt_number']->data_type)->toBe('integer')
        ->and($columns['amount_minor']->data_type)->toBe('bigint')
        ->and($columns['provider_metadata']->data_type)->toBe('jsonb')
        ->and($columns['initiated_at']->data_type)->toBe('timestamp with time zone');

    $forbiddenMoneyTypes = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('table_name', 'payments')
        ->whereIn('data_type', ['numeric', 'decimal', 'real', 'double precision'])
        ->count();

    expect($forbiddenMoneyTypes)->toBe(0);

    expect(array_map(fn (PaymentStatus $status): string => $status->value, PaymentStatus::cases()))->toBe([
        'pending',
        'processing',
        'requires_review',
        'succeeded',
        'failed',
        'cancelled',
        'expired',
    ]);

    $order = createPendingOrderWithItem();
    $payment = DB::transaction(function () use ($order): Payment {
        $payment = Payment::factory()->forOrder($order)->create();
        forceP3CAConstraints();

        return $payment;
    });

    expect($payment->toArray())->not->toHaveKey('idempotency_key_hash')
        ->and($payment->getRawOriginal('idempotency_key_hash'))->toMatch('/^[0-9a-f]{64}$/');
});

it('defines the required constraints, partial unique indexes, FK, functions, and triggers', function () {
    $constraintNames = DB::table('pg_constraint')
        ->join('pg_class', 'pg_class.oid', '=', 'pg_constraint.conrelid')
        ->where('pg_class.relname', 'payments')
        ->pluck('pg_constraint.conname');

    foreach ([
        'payments_provider_format_check',
        'payments_idempotency_hash_format_check',
        'payments_provider_reference_not_blank_check',
        'payments_attempt_number_positive_check',
        'payments_amount_positive_check',
        'payments_currency_format_check',
        'payments_provider_metadata_object_check',
        'payments_status_check',
        'payments_cycle_dates_after_initiated_check',
    ] as $constraintName) {
        expect($constraintNames)->toContain($constraintName);
    }

    $indexDefinitions = DB::table('pg_indexes')
        ->where('schemaname', 'public')
        ->where('tablename', 'payments')
        ->pluck('indexdef', 'indexname');

    expect($indexDefinitions)->toHaveKey('payments_public_id_unique')
        ->and($indexDefinitions)->toHaveKey('payments_idempotency_key_hash_unique')
        ->and($indexDefinitions)->toHaveKey('payments_order_id_attempt_number_unique')
        ->and($indexDefinitions)->toHaveKey('payments_status_initiated_at_index')
        ->and($indexDefinitions['payments_one_succeeded_per_order'])->toContain('UNIQUE')
        ->and($indexDefinitions['payments_one_succeeded_per_order'])->toContain("'succeeded'")
        ->and($indexDefinitions['payments_one_requires_review_per_order'])->toContain("'requires_review'")
        ->and($indexDefinitions['payments_provider_reference_unique'])->toContain('provider_payment_reference IS NOT NULL');

    $foreignKey = DB::table('pg_constraint')
        ->where('contype', 'f')
        ->where('conname', 'payments_order_id_foreign')
        ->first();

    expect($foreignKey)->not->toBeNull()
        ->and($foreignKey->confdeltype)->toBe('r');

    $functionNames = DB::table('pg_proc')
        ->whereIn('proname', [
            'prevent_payments_delete',
            'enforce_payments_immutability',
            'validate_payment_order_amount',
            'validate_payment_order_consistency',
        ])
        ->pluck('proname');

    expect($functionNames)->toHaveCount(4);

    $immediateTriggers = DB::table('pg_trigger')
        ->whereIn('tgname', [
            'payments_prevent_delete_trigger',
            'payments_enforce_immutability_trigger',
            'payments_validate_order_amount_trigger',
        ])
        ->count();

    expect($immediateTriggers)->toBe(3);

    $deferredTriggerNames = [
        'payments_validate_order_consistency_trigger',
        'orders_validate_payment_consistency_trigger',
    ];

    $deferredTriggers = DB::table('pg_trigger')
        ->select('tgname', 'tgdeferrable', 'tginitdeferred')
        ->whereIn('tgname', $deferredTriggerNames)
        ->get()
        ->keyBy('tgname');

    expect($deferredTriggers)->toHaveCount(2);

    foreach ($deferredTriggerNames as $triggerName) {
        expect((bool) $deferredTriggers[$triggerName]->tgdeferrable)->toBeTrue()
            ->and((bool) $deferredTriggers[$triggerName]->tginitdeferred)->toBeTrue();
    }
});

it('accepts canonical providers and rejects non-canonical ones', function () {
    $order = createPendingOrderWithItem();

    DB::transaction(function () use ($order): void {
        Payment::factory()->forOrder($order)->create(['provider' => 'powerpay', 'attempt_number' => 1]);
        Payment::factory()->forOrder($order)->create(['provider' => 'provider_test', 'attempt_number' => 2]);
        forceP3CAConstraints();
    });

    expect(DB::table('payments')->where('order_id', $order->id)->count())->toBe(2);

    foreach (['PowerPay', 'POWERPAY', ' powerpay', 'power pay', ''] as $invalidProvider) {
        expectP3CACheckViolation(
            fn () => Payment::factory()->forOrder($order)->create(['provider' => $invalidProvider, 'attempt_number' => 9]),
            'payments_provider_format_check',
        );
    }
});

it('enforces hash, amount, currency, attempt, metadata, and status formats', function () {
    $order = createPendingOrderWithItem();
    $otherOrder = createPendingOrderWithItem();

    $persisted = DB::transaction(function () use ($order): Payment {
        $payment = Payment::factory()->forOrder($order)->create([
            'idempotency_key_hash' => str_repeat('a', 64),
            'attempt_number' => 1,
        ]);
        forceP3CAConstraints();

        return $payment;
    });

    expectP3CACheckViolation(
        fn () => Payment::factory()->forOrder($order)->create(['idempotency_key_hash' => str_repeat('A', 64), 'attempt_number' => 2]),
        'payments_idempotency_hash_format_check',
    );
    expectP3CAUniqueViolation(
        fn () => Payment::factory()->forOrder($otherOrder)->create(['idempotency_key_hash' => str_repeat('a', 64)]),
        'payments_idempotency_key_hash_unique',
    );
    expectP3CACheckViolation(
        fn () => Payment::factory()->forOrder($order)->create(['attempt_number' => 0]),
        'payments_attempt_number_positive_check',
    );
    expectP3CAUniqueViolation(
        fn () => Payment::factory()->forOrder($order)->create(['attempt_number' => 1, 'idempotency_key_hash' => hash('sha256', 'dup-attempt')]),
        'payments_order_id_attempt_number_unique',
    );
    expectP3CACheckViolation(
        fn () => Payment::factory()->forOrder($order)->create(['provider_payment_reference' => '   ', 'attempt_number' => 3]),
        'payments_provider_reference_not_blank_check',
    );
    expectP3CACheckViolation(
        fn () => Payment::factory()->forOrder($order)->create(['provider_metadata' => [1, 2, 3], 'attempt_number' => 4]),
        'payments_provider_metadata_object_check',
    );
    expectP3CACheckViolation(
        fn () => DB::table('payments')->insert(rawPaymentRow($order, ['status' => 'refunded', 'attempt_number' => 5])),
        'payments_status_check',
    );

    expect($persisted->getRawOriginal('idempotency_key_hash'))->toBe(str_repeat('a', 64));
});

it('validates payment amount, currency, and free orders against the order at insert time', function () {
    $order = createPendingOrderWithItem();

    expectP3CATriggerViolation(
        fn () => Payment::factory()->forOrder($order)->create(['amount_minor' => 9000]),
        'payments amount must equal orders total',
    );
    expectP3CATriggerViolation(
        fn () => Payment::factory()->forOrder($order)->create(['amount_minor' => 0]),
        'payments amount must equal orders total',
    );
    expectP3CATriggerViolation(
        fn () => Payment::factory()->forOrder($order)->create(['currency' => 'USD']),
        'payments currency must match orders currency',
    );

    $freeOrder = createFreeOrderWithItem(OrderStatus::Paid);

    expectP3CATriggerViolation(
        fn () => Payment::factory()->forOrder($freeOrder)->create(['amount_minor' => 1000, 'currency' => 'XOF']),
        'payments are not allowed on a free order',
    );

    DB::transaction(function () use ($order): void {
        Payment::factory()->forOrder($order)->create();
        forceP3CAConstraints();
    });

    expect(DB::table('payments')->where('order_id', $order->id)->count())->toBe(1);
});

it('allows multiple attempts and provider references while capping succeeded and review payments', function () {
    $order = createPendingOrderWithItem();

    DB::transaction(function () use ($order): void {
        Payment::factory()->forOrder($order)->create(['attempt_number' => 1, 'provider' => 'powerpay', 'provider_payment_reference' => 'REF-1']);
        Payment::factory()->forOrder($order)->failed()->create(['attempt_number' => 2, 'provider' => 'powerpay', 'provider_payment_reference' => 'REF-2']);
        Payment::factory()->forOrder($order)->create(['attempt_number' => 3, 'provider' => 'wave', 'provider_payment_reference' => 'REF-1']);
        forceP3CAConstraints();
    });

    expect(DB::table('payments')->where('order_id', $order->id)->count())->toBe(3);

    expectP3CAUniqueViolation(
        fn () => Payment::factory()->forOrder($order)->create(['attempt_number' => 4, 'provider' => 'powerpay', 'provider_payment_reference' => 'REF-1']),
        'payments_provider_reference_unique',
    );

    ['order' => $paidOrder, 'payment' => $succeeded] = makeOrderWithPaymentInStatus(PaymentStatus::Succeeded);
    expectP3CAUniqueViolation(
        fn () => Payment::factory()->forOrder($paidOrder)->succeeded()->create(['attempt_number' => 2]),
        'payments_one_succeeded_per_order',
    );

    ['order' => $reviewOrder] = makeOrderWithPaymentInStatus(PaymentStatus::RequiresReview);
    expectP3CAUniqueViolation(
        fn () => Payment::factory()->forOrder($reviewOrder)->requiresReview()->create(['attempt_number' => 2]),
        'payments_one_requires_review_per_order',
    );

    expect($succeeded->status)->toBe(PaymentStatus::Succeeded);
});

it('keeps payments immutable and blocks physical deletion', function () {
    $order = createPendingOrderWithItem();
    $otherOrder = createPendingOrderWithItem();

    $payment = DB::transaction(function () use ($order): Payment {
        $payment = Payment::factory()->forOrder($order)->create(['attempt_number' => 1]);
        forceP3CAConstraints();

        return $payment;
    });

    expectP3CATriggerViolation(fn () => $payment->delete(), 'payments are immutable and cannot be deleted');

    foreach ([
        ['order_id' => $otherOrder->id],
        ['provider' => 'wave'],
        ['amount_minor' => 9000],
        ['currency' => 'USD'],
        ['idempotency_key_hash' => hash('sha256', 'changed')],
        ['attempt_number' => 2],
    ] as $mutation) {
        expectP3CATriggerViolation(
            fn () => DB::table('payments')->where('id', $payment->id)->update($mutation),
            'payments commercial data is immutable',
        );
    }
});

it('permits provider reference and cycle dates to be set once but never rewritten', function () {
    $order = createPendingOrderWithItem();

    $payment = DB::transaction(function () use ($order): Payment {
        $payment = Payment::factory()->forOrder($order)->create(['attempt_number' => 1]);
        forceP3CAConstraints();

        return $payment;
    });

    DB::table('payments')->where('id', $payment->id)->update(['provider_payment_reference' => 'REF-A']);
    expect(DB::table('payments')->where('id', $payment->id)->value('provider_payment_reference'))->toBe('REF-A');

    expectP3CATriggerViolation(
        fn () => DB::table('payments')->where('id', $payment->id)->update(['provider_payment_reference' => 'REF-B']),
        'payments provider reference is immutable once set',
    );
    expectP3CATriggerViolation(
        fn () => DB::table('payments')->where('id', $payment->id)->update(['provider_payment_reference' => null]),
        'payments provider reference is immutable once set',
    );

    DB::table('payments')->where('id', $payment->id)->update(['processing_at' => now()]);
    expectP3CATriggerViolation(
        fn () => DB::table('payments')->where('id', $payment->id)->update(['processing_at' => now()->addHour()]),
        'payments cycle dates are immutable once set',
    );

    $verifiedAt = now()->addHour();
    DB::table('payments')->where('id', $payment->id)->update(['last_verified_at' => $verifiedAt]);
    DB::table('payments')->where('id', $payment->id)->update(['last_verified_at' => $verifiedAt->copy()->addHour()]);
    expectP3CATriggerViolation(
        fn () => DB::table('payments')->where('id', $payment->id)->update(['last_verified_at' => $verifiedAt->copy()->subMinutes(30)]),
        'payments last_verified_at cannot move backwards',
    );
});

it('permits every allowed payment status transition', function () {
    $allowed = [
        [PaymentStatus::Pending, PaymentStatus::Processing],
        [PaymentStatus::Pending, PaymentStatus::RequiresReview],
        [PaymentStatus::Pending, PaymentStatus::Failed],
        [PaymentStatus::Pending, PaymentStatus::Cancelled],
        [PaymentStatus::Pending, PaymentStatus::Expired],
        [PaymentStatus::Processing, PaymentStatus::Succeeded],
        [PaymentStatus::Processing, PaymentStatus::RequiresReview],
        [PaymentStatus::Processing, PaymentStatus::Failed],
        [PaymentStatus::Processing, PaymentStatus::Cancelled],
        [PaymentStatus::Processing, PaymentStatus::Expired],
        [PaymentStatus::Failed, PaymentStatus::RequiresReview],
        [PaymentStatus::Cancelled, PaymentStatus::RequiresReview],
        [PaymentStatus::Expired, PaymentStatus::RequiresReview],
        [PaymentStatus::RequiresReview, PaymentStatus::Succeeded],
        [PaymentStatus::RequiresReview, PaymentStatus::Failed],
        [PaymentStatus::RequiresReview, PaymentStatus::Cancelled],
        [PaymentStatus::RequiresReview, PaymentStatus::Expired],
    ];

    foreach ($allowed as [$from, $to]) {
        assertAllowedPaymentTransition($from, $to);
    }
});

it('rejects forbidden payment status transitions including out of succeeded', function () {
    foreach ([
        [PaymentStatus::Pending, PaymentStatus::Succeeded],
        [PaymentStatus::Failed, PaymentStatus::Succeeded],
        [PaymentStatus::Cancelled, PaymentStatus::Succeeded],
        [PaymentStatus::Expired, PaymentStatus::Succeeded],
        [PaymentStatus::Succeeded, PaymentStatus::Failed],
        [PaymentStatus::Succeeded, PaymentStatus::Cancelled],
    ] as [$from, $to]) {
        assertForbiddenPaymentTransition($from, $to);
    }
});

it('enforces deferred payment and order consistency at commit', function () {
    // Succeeded payment on a still-pending order is rejected at commit.
    expectP3CADeferredViolation(function (): void {
        $order = createPendingOrderWithItem();
        Payment::factory()->forOrder($order)->succeeded()->create();
    }, 'unpaid orders must not have succeeded or review payments');

    // Review payment on a still-pending order is rejected at commit.
    expectP3CADeferredViolation(function (): void {
        $order = createPendingOrderWithItem();
        Payment::factory()->forOrder($order)->requiresReview()->create();
    }, 'unpaid orders must not have succeeded or review payments');

    // Non-free paid order without a succeeded payment is rejected at commit.
    expectP3CADeferredViolation(function (): void {
        $product = Product::factory()->create();
        $order = Order::factory()->create(['status' => OrderStatus::Paid, 'paid_at' => now()]);
        OrderItem::factory()->forOrder($order)->forProduct($product)->create();
    }, 'paid orders require exactly one succeeded payment');

    // payment_review order without a review payment is rejected at commit.
    expectP3CADeferredViolation(function (): void {
        $product = Product::factory()->create();
        $order = Order::factory()->create(['status' => OrderStatus::PaymentReview]);
        OrderItem::factory()->forOrder($order)->forProduct($product)->create();
    }, 'payment_review orders require exactly one requires_review payment');

    // Succeeded payment coexisting with a review payment is impossible.
    expectP3CADeferredViolation(function (): void {
        ['order' => $order] = makeOrderWithPaymentInStatus(PaymentStatus::Succeeded);
        Payment::factory()->forOrder($order)->requiresReview()->create(['attempt_number' => 2]);
    }, 'paid orders require exactly one succeeded payment');
});

it('accepts coherent payment and order transactions in either creation order', function () {
    // Payment created first, order status flipped afterwards, same transaction.
    ['payment' => $paymentFirst] = DB::transaction(function (): array {
        $product = Product::factory()->create();
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        OrderItem::factory()->forOrder($order)->forProduct($product)->create();
        $payment = Payment::factory()->forOrder($order)->succeeded()->create();
        $order->update(['status' => OrderStatus::Paid, 'paid_at' => now()]);
        forceP3CAConstraints();

        return ['payment' => $payment];
    });

    // Order status set first, payment created afterwards, same transaction.
    ['payment' => $orderFirst] = DB::transaction(function (): array {
        $product = Product::factory()->create();
        $order = Order::factory()->create(['status' => OrderStatus::Paid, 'paid_at' => now()]);
        OrderItem::factory()->forOrder($order)->forProduct($product)->create();
        $payment = Payment::factory()->forOrder($order)->succeeded()->create();
        forceP3CAConstraints();

        return ['payment' => $payment];
    });

    // payment_review order and its review payment together.
    ['payment' => $reviewPayment] = DB::transaction(function (): array {
        $product = Product::factory()->create();
        $order = Order::factory()->create(['status' => OrderStatus::PaymentReview]);
        OrderItem::factory()->forOrder($order)->forProduct($product)->create();
        $payment = Payment::factory()->forOrder($order)->requiresReview()->create();
        forceP3CAConstraints();

        return ['payment' => $payment];
    });

    expect($paymentFirst->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($orderFirst->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($reviewPayment->refresh()->status)->toBe(PaymentStatus::RequiresReview);
});

it('accepts a free order marked paid without any payment', function () {
    $freeOrder = createFreeOrderWithItem(OrderStatus::Paid);

    expect($freeOrder->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(DB::table('payments')->where('order_id', $freeOrder->id)->count())->toBe(0);
});

it('rolls back only the P3C-A payments migration while preserving P3B', function () {
    $harness = new PhaseMigrationHarness('digitrove_p3ca_rollback_'.strtolower(Str::random(10)));

    $boundary = '2026_07_14_000004_create_payments_table.php';
    $gateMigrations = ['2026_07_14_000004_create_payments_table.php'];
    $p3caFunctions = [
        'prevent_payments_delete',
        'enforce_payments_immutability',
        'validate_payment_order_amount',
        'validate_payment_order_consistency',
    ];
    $p3caTriggers = [
        'payments_prevent_delete_trigger',
        'payments_enforce_immutability_trigger',
        'payments_validate_order_amount_trigger',
        'payments_validate_order_consistency_trigger',
        'orders_validate_payment_consistency_trigger',
    ];
    $p3bFunctions = [
        'prevent_orders_delete', 'enforce_orders_immutability',
        'prevent_order_items_delete', 'enforce_order_items_immutability',
        'validate_order_items_consistency', 'validate_coupon_redemption_consistency',
    ];
    $p3bTriggers = [
        'orders_prevent_delete_trigger', 'orders_enforce_immutability_trigger',
        'orders_validate_items_consistency_trigger', 'orders_validate_redemption_consistency_trigger',
        'order_items_prevent_delete_trigger', 'order_items_enforce_immutability_trigger',
        'order_items_validate_order_consistency_trigger', 'coupon_redemptions_validate_order_consistency_trigger',
    ];

    try {
        $harness->create();
        $applied = $harness->applyMigrationsThrough($boundary);

        // Stops exactly at the payments gate: no webhook/refund/later migration is applied.
        expect($harness->currentDatabase())->toBe($harness->databaseName())
            ->and($applied)->toContain('2026_07_14_000004_create_payments_table')
            ->and(end($applied))->toBe('2026_07_14_000004_create_payments_table');

        // Before rollback: P1/P2/P3A/P3B applied, payments present, no later-phase tables.
        expect($harness->hasTable('payments'))->toBeTrue()
            ->and($harness->hasTable('orders'))->toBeTrue()
            ->and($harness->hasTable('carts'))->toBeTrue()
            ->and($harness->hasTable('payment_webhook_events'))->toBeFalse()
            ->and($harness->hasTable('refunds'))->toBeFalse()
            ->and($harness->countFunctions($p3caFunctions))->toBe(4)
            ->and($harness->countTriggers($p3caTriggers))->toBe(5);

        // Roll back ONLY the payments migration; assert exactly it ran down().
        $downed = $harness->rollbackExactMigrations($gateMigrations);
        expect($downed)->toBe(['2026_07_14_000004_create_payments_table']);

        // After rollback: every P3C-A object is gone.
        expect($harness->hasTable('payments'))->toBeFalse()
            ->and($harness->countFunctions($p3caFunctions))->toBe(0)
            ->and($harness->countTriggers($p3caTriggers))->toBe(0);

        // P3B is fully preserved: tables, six functions, eight triggers, hardened constraint.
        expect($harness->hasTable('orders'))->toBeTrue()
            ->and($harness->hasTable('order_items'))->toBeTrue()
            ->and($harness->hasTable('coupon_redemptions'))->toBeTrue()
            ->and($harness->countFunctions($p3bFunctions))->toBe(6)
            ->and($harness->countTriggers($p3bTriggers))->toBe(8)
            ->and($harness->hasConstraint('orders_coupon_snapshot_consistency_check'))->toBeTrue();

        // No later-phase migration was ever applied or rolled back.
        expect($harness->hasTable('payment_webhook_events'))->toBeFalse()
            ->and($harness->hasTable('refunds'))->toBeFalse();
    } finally {
        $harness->drop();
    }
});

it('does not introduce P6 marketing tables', function () {
    foreach (['campaigns'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("Unexpected out-of-scope table exists: {$table}");
    }
});
