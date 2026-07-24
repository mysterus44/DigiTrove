<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;
use Tests\Support\PhaseMigrationHarness;

uses(RefreshDatabase::class);

function forceP3CCConstraints(): void
{
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
}

function expectP3CCQueryException(Closure $callback, string $sqlState, string $messageFragment): void
{
    $exception = null;

    try {
        DB::transaction(function () use ($callback): void {
            $callback();
            forceP3CCConstraints();
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

function expectP3CCCheckViolation(Closure $callback, string $constraintName): void
{
    expectP3CCQueryException($callback, '23514', $constraintName);
}

function expectP3CCUniqueViolation(Closure $callback, string $constraintName): void
{
    expectP3CCQueryException($callback, '23505', $constraintName);
}

function expectP3CCTriggerViolation(Closure $callback, string $messageFragment): void
{
    expectP3CCQueryException($callback, '23514', $messageFragment);
}

function expectP3CCDeferredViolation(Closure $callback, string $messageFragment): void
{
    expectP3CCQueryException($callback, '23514', $messageFragment);
}

function createRefundablePayment(int $amount = 10000, string $provider = 'powerpay'): Payment
{
    return DB::transaction(function () use ($amount, $provider): Payment {
        $product = Product::factory()->create();
        $order = Order::factory()->create([
            'subtotal_minor' => $amount,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => $amount,
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
        ]);
        OrderItem::factory()->forOrder($order)->forProduct($product)->create([
            'unit_price_minor' => $amount,
            'quantity' => 1,
            'line_subtotal_minor' => $amount,
            'line_discount_minor' => 0,
            'line_total_minor' => $amount,
            'currency' => $order->currency,
        ]);
        $payment = Payment::factory()->forOrder($order)->succeeded()->create(['provider' => $provider]);
        forceP3CCConstraints();

        return $payment;
    });
}

function attachSucceededRefund(Payment $payment, int $amount, OrderStatus $orderStatus): Refund
{
    return DB::transaction(function () use ($payment, $amount, $orderStatus): Refund {
        $refund = Refund::factory()->forPayment($payment)->succeeded()->create(['amount_minor' => $amount]);
        DB::table('orders')->where('id', $payment->order_id)->update(['status' => $orderStatus->value]);
        forceP3CCConstraints();

        return $refund;
    });
}

it('runs P3C-C schema tests against PostgreSQL', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

it('creates the refunds table with native types, FK, enum, functions and triggers', function () {
    expect(Schema::hasTable('refunds'))->toBeTrue();

    expect(Schema::hasColumns('refunds', [
        'id', 'public_id', 'payment_id', 'provider', 'provider_refund_reference',
        'idempotency_key_hash', 'amount_minor', 'currency', 'status', 'reason_code',
        'reason_note_sanitized', 'initiated_by_user_id', 'provider_status',
        'provider_metadata', 'requested_at', 'processing_at', 'succeeded_at',
        'failed_at', 'cancelled_at', 'last_verified_at', 'created_at', 'updated_at',
    ]))->toBeTrue();

    $columns = DB::table('information_schema.columns')
        ->select('column_name', 'data_type', 'udt_name', 'character_maximum_length', 'column_default', 'is_nullable')
        ->where('table_schema', 'public')->where('table_name', 'refunds')
        ->get()->keyBy('column_name');

    expect($columns['id']->data_type)->toBe('bigint')
        ->and($columns['public_id']->data_type)->toBe('uuid')
        ->and($columns['payment_id']->data_type)->toBe('bigint')
        ->and($columns['payment_id']->is_nullable)->toBe('NO')
        ->and($columns['provider']->data_type)->toBe('character varying')
        ->and($columns['provider']->character_maximum_length)->toBe(32)
        ->and($columns['provider_refund_reference']->data_type)->toBe('text')
        ->and($columns['idempotency_key_hash']->character_maximum_length)->toBe(64)
        ->and($columns['amount_minor']->data_type)->toBe('bigint')
        ->and($columns['currency']->data_type)->toBe('character varying')
        ->and($columns['currency']->character_maximum_length)->toBe(3)
        ->and($columns['status']->column_default)->toContain('pending')
        ->and($columns['reason_code']->data_type)->toBe('text')
        ->and($columns['provider_status']->data_type)->toBe('text')
        ->and($columns['provider_metadata']->data_type)->toBe('jsonb')
        ->and($columns['requested_at']->data_type)->toBe('timestamp with time zone');

    $forbiddenMoney = DB::table('information_schema.columns')
        ->where('table_schema', 'public')->where('table_name', 'refunds')
        ->whereIn('data_type', ['numeric', 'decimal', 'real', 'double precision', 'money'])->count();
    expect($forbiddenMoney)->toBe(0);

    expect(array_map(fn (RefundStatus $s): string => $s->value, RefundStatus::cases()))
        ->toBe(['pending', 'processing', 'succeeded', 'failed', 'cancelled']);

    $paymentFk = DB::table('pg_constraint')->where('conname', 'refunds_payment_id_foreign')->first();
    $userFk = DB::table('pg_constraint')->where('conname', 'refunds_initiated_by_user_id_foreign')->first();
    expect($paymentFk->confdeltype)->toBe('r')->and($userFk->confdeltype)->toBe('n');

    $constraintNames = DB::table('pg_constraint')
        ->join('pg_class', 'pg_class.oid', '=', 'pg_constraint.conrelid')
        ->where('pg_class.relname', 'refunds')
        ->pluck('pg_constraint.conname');
    foreach ([
        'refunds_public_id_unique', 'refunds_idempotency_key_hash_unique',
        'refunds_provider_format_check', 'refunds_idempotency_hash_format_check',
        'refunds_provider_reference_not_blank_check', 'refunds_amount_positive_check',
        'refunds_currency_format_check', 'refunds_status_check',
        'refunds_reason_note_not_blank_check', 'refunds_provider_metadata_object_check',
        'refunds_cycle_dates_check', 'refunds_status_dates_consistency_check',
    ] as $constraintName) {
        expect($constraintNames)->toContain($constraintName);
    }

    $indexes = DB::table('pg_indexes')->where('tablename', 'refunds')->pluck('indexdef', 'indexname');
    expect($indexes)->toHaveKeys([
        'refunds_public_id_unique', 'refunds_idempotency_key_hash_unique',
        'refunds_payment_id_index', 'refunds_payment_id_status_index',
        'refunds_status_requested_index', 'refunds_provider_reference_unique',
    ])->and($indexes['refunds_provider_reference_unique'])
        ->toContain('UNIQUE')
        ->toContain('provider_refund_reference IS NOT NULL')
        ->and($indexes['refunds_status_requested_index'])->toContain('requested_at DESC');

    expect(DB::table('pg_proc')->whereIn('proname', [
        'prevent_refunds_delete', 'enforce_refunds_immutability', 'validate_refund_payment_consistency',
        'enforce_refund_cumulative_cap', 'validate_refund_order_consistency',
    ])->count())->toBe(5);

    $deferred = DB::table('pg_trigger')->select('tgname', 'tgdeferrable', 'tginitdeferred')
        ->whereIn('tgname', ['refunds_validate_order_consistency_trigger', 'orders_validate_refund_consistency_trigger'])
        ->get()->keyBy('tgname');
    expect($deferred)->toHaveCount(2);
    foreach ($deferred as $t) {
        expect((bool) $t->tgdeferrable)->toBeTrue()->and((bool) $t->tginitdeferred)->toBeTrue();
    }
    $immediate = DB::table('pg_trigger')->whereIn('tgname', [
        'refunds_prevent_delete_trigger', 'refunds_enforce_immutability_trigger',
        'refunds_validate_payment_consistency_trigger', 'refunds_enforce_cumulative_cap_trigger',
    ])->count();
    expect($immediate)->toBe(4);

    $triggerDefinitions = DB::table('pg_trigger')
        ->selectRaw('tgname, pg_get_triggerdef(oid) AS definition')
        ->whereIn('tgname', [
            'refunds_prevent_delete_trigger', 'refunds_enforce_immutability_trigger',
            'refunds_validate_payment_consistency_trigger', 'refunds_enforce_cumulative_cap_trigger',
            'refunds_validate_order_consistency_trigger', 'orders_validate_refund_consistency_trigger',
        ])->pluck('definition', 'tgname');
    expect($triggerDefinitions)->toHaveCount(6)
        ->and($triggerDefinitions['refunds_validate_order_consistency_trigger'])
        ->toContain('DEFERRABLE INITIALLY DEFERRED')
        ->and($triggerDefinitions['orders_validate_refund_consistency_trigger'])
        ->toContain('DEFERRABLE INITIALLY DEFERRED')
        ->and($triggerDefinitions['refunds_enforce_cumulative_cap_trigger'])
        ->toContain('BEFORE INSERT OR UPDATE OF status');

    $functionDefinition = (string) DB::selectOne(
        "SELECT pg_get_functiondef(oid) AS definition FROM pg_proc WHERE proname = 'enforce_refund_cumulative_cap'",
    )->definition;
    expect($functionDefinition)->toContain('FOR UPDATE')
        ->toContain("status = 'succeeded'")
        ->toContain('id <> NEW.id');

    expect(Schema::hasColumn('refunds', 'order_id'))->toBeFalse()
        ->and(Schema::hasColumn('refunds', 'raw_payload'))->toBeFalse()
        ->and(Schema::hasColumn('refunds', 'provider_response'))->toBeFalse()
        ->and(Schema::hasColumn('refunds', 'secret'))->toBeFalse();

    $refund = Refund::factory()->forPayment(createRefundablePayment())->create();
    expect($refund->toArray())->not->toHaveKey('idempotency_key_hash')
        ->and($refund->getRawOriginal('idempotency_key_hash'))->toMatch('/^[0-9a-f]{64}$/');
});

it('enforces amount, currency and provider against the payment', function () {
    $payment = createRefundablePayment(10000, 'powerpay');

    // Valid pending refund accepted.
    DB::transaction(function () use ($payment): void {
        Refund::factory()->forPayment($payment)->create(['amount_minor' => 5000]);
        forceP3CCConstraints();
    });
    expect(DB::table('refunds')->where('payment_id', $payment->id)->count())->toBe(1);

    expectP3CCCheckViolation(fn () => Refund::factory()->forPayment($payment)->create(['amount_minor' => 0]), 'refunds_amount_positive_check');
    expectP3CCCheckViolation(fn () => Refund::factory()->forPayment($payment)->create(['amount_minor' => -100]), 'refunds_amount_positive_check');
    // A lowercase/different currency is caught by the payment-match trigger (fires before the
    // format CHECK, which stays as defense-in-depth since the payment currency is always uppercase).
    expectP3CCTriggerViolation(fn () => Refund::factory()->forPayment($payment)->create(['currency' => 'xof']), 'refunds currency must match the payment currency');
    expectP3CCTriggerViolation(fn () => Refund::factory()->forPayment($payment)->create(['currency' => 'USD']), 'refunds currency must match the payment currency');
    expectP3CCTriggerViolation(fn () => Refund::factory()->forPayment($payment)->create(['provider' => 'wave']), 'refunds provider must match the payment provider');
    // The cross-table provider trigger fires before table CHECK validation.
    expectP3CCTriggerViolation(fn () => Refund::factory()->forPayment($payment)->create(['provider' => 'PowerPay']), 'refunds provider must match the payment provider');
});

it('rejects refunds for every non-succeeded payment status', function () {
    foreach ([
        PaymentStatus::Pending,
        PaymentStatus::Processing,
        PaymentStatus::RequiresReview,
        PaymentStatus::Failed,
        PaymentStatus::Cancelled,
        PaymentStatus::Expired,
    ] as $status) {
        $payment = createPaymentWithStatusForRefund($status);
        expectP3CCTriggerViolation(
            fn () => Refund::factory()->forPayment($payment)->create(),
            'refunds require a succeeded payment',
        );
    }
});

function createPendingOrderForRefund(): Order
{
    return DB::transaction(function (): Order {
        $product = Product::factory()->create();
        $order = Order::factory()->create();
        OrderItem::factory()->forOrder($order)->forProduct($product)->create([
            'unit_price_minor' => $order->subtotal_minor, 'quantity' => 1,
            'line_subtotal_minor' => $order->subtotal_minor, 'line_discount_minor' => $order->discount_minor,
            'line_total_minor' => $order->subtotal_minor - $order->discount_minor, 'currency' => $order->currency,
        ]);
        forceP3CCConstraints();

        return $order;
    });
}

function createPaymentWithStatusForRefund(PaymentStatus $status): Payment
{
    $order = createPendingOrderForRefund();

    return DB::transaction(function () use ($order, $status): Payment {
        $factory = Payment::factory()->forOrder($order);
        $factory = match ($status) {
            PaymentStatus::Pending => $factory,
            PaymentStatus::Processing => $factory->processing(),
            PaymentStatus::RequiresReview => $factory->requiresReview(),
            PaymentStatus::Failed => $factory->failed(),
            PaymentStatus::Cancelled => $factory->cancelled(),
            PaymentStatus::Expired => $factory->expired(),
            PaymentStatus::Succeeded => $factory->succeeded(),
        };

        if ($status === PaymentStatus::RequiresReview) {
            DB::table('orders')->where('id', $order->id)->update(['status' => OrderStatus::PaymentReview->value]);
        }

        $payment = $factory->create();
        forceP3CCConstraints();

        return $payment;
    });
}

it('enforces idempotency hash, provider reference format and uniqueness', function () {
    $payment = createRefundablePayment();
    $other = createRefundablePayment();
    $otherProvider = createRefundablePayment(10000, 'wave');

    $persisted = DB::transaction(function () use ($payment): Refund {
        $r = Refund::factory()->forPayment($payment)->create(['idempotency_key_hash' => str_repeat('a', 64)]);
        forceP3CCConstraints();

        return $r;
    });

    expectP3CCCheckViolation(fn () => Refund::factory()->forPayment($payment)->create(['idempotency_key_hash' => str_repeat('A', 64)]), 'refunds_idempotency_hash_format_check');
    expectP3CCCheckViolation(fn () => Refund::factory()->forPayment($payment)->create(['idempotency_key_hash' => str_repeat('a', 63)]), 'refunds_idempotency_hash_format_check');
    expectP3CCUniqueViolation(fn () => Refund::factory()->forPayment($other)->create(['idempotency_key_hash' => str_repeat('a', 64)]), 'refunds_idempotency_key_hash_unique');
    expectP3CCUniqueViolation(fn () => Refund::factory()->forPayment($otherProvider)->create(['idempotency_key_hash' => str_repeat('a', 64)]), 'refunds_idempotency_key_hash_unique');
    expectP3CCCheckViolation(fn () => Refund::factory()->forPayment($payment)->create(['provider_refund_reference' => '   ']), 'refunds_provider_reference_not_blank_check');
    expectP3CCCheckViolation(fn () => Refund::factory()->forPayment($payment)->create(['provider_metadata' => [1, 2, 3]]), 'refunds_provider_metadata_object_check');

    // Provider reference unique per provider; different provider (its own payment) allowed.
    DB::transaction(function () use ($payment): void {
        Refund::factory()->forPayment($payment)->create(['provider_refund_reference' => 'rf_1']);
        forceP3CCConstraints();
    });
    expectP3CCUniqueViolation(fn () => Refund::factory()->forPayment($payment)->create(['provider_refund_reference' => 'rf_1']), 'refunds_provider_reference_unique');
    DB::transaction(function () use ($otherProvider): void {
        Refund::factory()->forPayment($otherProvider)->create(['provider_refund_reference' => 'rf_1']);
        Refund::factory()->forPayment($otherProvider)->create(['provider_refund_reference' => null]);
        Refund::factory()->forPayment($otherProvider)->create(['provider_refund_reference' => null]);
        forceP3CCConstraints();
    });

    expect($persisted->getRawOriginal('idempotency_key_hash'))->toBe(str_repeat('a', 64));
});

it('enforces status/date coherence and invalid enum status', function () {
    $payment = createRefundablePayment();

    expectP3CCCheckViolation(function () use ($payment): void {
        DB::table('refunds')->insert([
            'public_id' => (string) Str::uuid(), 'payment_id' => $payment->id, 'provider' => 'powerpay',
            'idempotency_key_hash' => hash('sha256', 'raw-'.Str::uuid()), 'amount_minor' => 1000, 'currency' => 'XOF',
            'status' => 'refunded', 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }, 'refunds_status_check');

    // succeeded requires succeeded_at.
    expectP3CCCheckViolation(fn () => Refund::factory()->forPayment($payment)->create(['status' => 'succeeded', 'succeeded_at' => null]), 'refunds_status_dates_consistency_check');
    // pending must not carry a terminal date.
    expectP3CCCheckViolation(fn () => Refund::factory()->forPayment($payment)->create(['status' => 'pending', 'failed_at' => now()]), 'refunds_status_dates_consistency_check');
    expectP3CCCheckViolation(fn () => Refund::factory()->forPayment($payment)->create(['reason_note_sanitized' => '   ']), 'refunds_reason_note_not_blank_check');
    expectP3CCCheckViolation(fn () => Refund::factory()->forPayment($payment)->create(['provider_metadata' => 'scalar']), 'refunds_provider_metadata_object_check');
    expectP3CCCheckViolation(fn () => Refund::factory()->forPayment($payment)->create(['failed_at' => now()->subDay()]), 'refunds_cycle_dates_check');
});

it('rejects NULL bypasses on required financial fields while preserving optional NULLs', function () {
    $payment = createRefundablePayment();

    expectP3CCTriggerViolation(
        fn () => Refund::factory()->forPayment($payment)->create(['provider' => null]),
        'refunds provider must match the payment provider',
    );
    expectP3CCTriggerViolation(
        fn () => Refund::factory()->forPayment($payment)->create(['currency' => null]),
        'refunds currency must match the payment currency',
    );
    expectP3CCQueryException(
        fn () => Refund::factory()->forPayment($payment)->create(['amount_minor' => null]),
        '23502',
        'null value in column "amount_minor"',
    );
    expectP3CCQueryException(
        fn () => Refund::factory()->forPayment($payment)->create(['idempotency_key_hash' => null]),
        '23502',
        'null value in column "idempotency_key_hash"',
    );
    expectP3CCQueryException(
        fn () => Refund::factory()->forPayment($payment)->create(['status' => null]),
        '23502',
        'null value in column "status"',
    );

    DB::transaction(function () use ($payment): void {
        Refund::factory()->forPayment($payment)->create([
            'provider_refund_reference' => null,
            'reason_note_sanitized' => null,
            'initiated_by_user_id' => null,
            'provider_metadata' => null,
        ]);
        forceP3CCConstraints();
    });
});

it('provides opaque identities, bidirectional relations, casts and coherent factory states', function () {
    $payment = createRefundablePayment();
    $refund = DB::transaction(function () use ($payment): Refund {
        $refund = Refund::factory()->forPayment($payment)->create();
        forceP3CCConstraints();

        return $refund;
    });

    expect(Str::isUuid($refund->public_id))->toBeTrue()
        ->and($refund->status)->toBe(RefundStatus::Pending)
        ->and($refund->amount_minor)->toBeInt()
        ->and($refund->payment->is($payment))->toBeTrue()
        ->and($payment->refunds->first()->is($refund))->toBeTrue()
        ->and($refund->toArray())->not->toHaveKey('idempotency_key_hash');

    expectP3CCUniqueViolation(
        fn () => Refund::factory()->forPayment($payment)->create(['public_id' => $refund->public_id]),
        'refunds_public_id_unique',
    );

    $processing = Refund::factory()->forPayment($payment)->processing()->make();
    $succeeded = Refund::factory()->forPayment($payment)->succeeded()->make();
    $failed = Refund::factory()->forPayment($payment)->failed()->make();
    $cancelled = Refund::factory()->forPayment($payment)->cancelled()->make();
    expect($processing->status)->toBe(RefundStatus::Processing)
        ->and($processing->processing_at)->not->toBeNull()
        ->and($succeeded->status)->toBe(RefundStatus::Succeeded)
        ->and($succeeded->succeeded_at)->not->toBeNull()
        ->and($failed->status)->toBe(RefundStatus::Failed)
        ->and($cancelled->status)->toBe(RefundStatus::Cancelled);

    // Every state is composable: the last state wins and clears incompatible dates.
    $stateMethods = ['pending', 'processing', 'succeeded', 'failed', 'cancelled'];
    foreach ($stateMethods as $first) {
        foreach ($stateMethods as $last) {
            $composed = Refund::factory()->forPayment($payment)->{$first}()->{$last}()->make();
            expect($composed->status)->toBe(RefundStatus::from($last));

            if ($last === 'pending') {
                expect($composed->processing_at)->toBeNull();
            }
            if ($last === 'processing') {
                expect($composed->processing_at)->not->toBeNull();
            }

            foreach (['succeeded', 'failed', 'cancelled'] as $terminal) {
                $attribute = "{$terminal}_at";
                if ($terminal === $last) {
                    expect($composed->{$attribute})->not->toBeNull();
                } else {
                    expect($composed->{$attribute})->toBeNull();
                }
            }
        }
    }
});

it('permits allowed refund transitions and rejects the rest', function () {
    $payment = createRefundablePayment();
    $refund = DB::transaction(function () use ($payment): Refund {
        $r = Refund::factory()->forPayment($payment)->create();
        forceP3CCConstraints();

        return $r;
    });

    // pending -> processing accepted.
    DB::table('refunds')->where('id', $refund->id)->update(['status' => 'processing', 'processing_at' => now()]);
    expect(DB::table('refunds')->where('id', $refund->id)->value('status'))->toBe('processing');

    // pending -> succeeded is forbidden (must go through processing).
    $r2 = DB::transaction(function () use ($payment): Refund {
        $r = Refund::factory()->forPayment($payment)->create(['idempotency_key_hash' => hash('sha256', 'r2')]);
        forceP3CCConstraints();

        return $r;
    });
    expectP3CCTriggerViolation(fn () => DB::table('refunds')->where('id', $r2->id)->update(['status' => 'succeeded', 'succeeded_at' => now()]), 'refunds status transition is not allowed');

    // terminal is final: failed -> processing rejected.
    $r3 = DB::transaction(function () use ($payment): Refund {
        $r = Refund::factory()->forPayment($payment)->failed()->create(['idempotency_key_hash' => hash('sha256', 'r3')]);
        forceP3CCConstraints();

        return $r;
    });
    expectP3CCTriggerViolation(fn () => DB::table('refunds')->where('id', $r3->id)->update(['status' => 'processing']), 'refunds status transition is not allowed');

    foreach ([
        ['failed', 'failed_at'],
        ['cancelled', 'cancelled_at'],
    ] as [$target, $dateColumn]) {
        $candidate = DB::transaction(function () use ($payment): Refund {
            $refund = Refund::factory()->forPayment($payment)->create();
            forceP3CCConstraints();

            return $refund;
        });
        DB::table('refunds')->where('id', $candidate->id)->update([
            'status' => $target,
            $dateColumn => now(),
        ]);
        expect(DB::table('refunds')->where('id', $candidate->id)->value('status'))->toBe($target);
    }

    foreach ([
        ['failed', 'failed_at'],
        ['cancelled', 'cancelled_at'],
    ] as [$target, $dateColumn]) {
        $candidate = DB::transaction(function () use ($payment): Refund {
            $refund = Refund::factory()->forPayment($payment)->processing()->create();
            forceP3CCConstraints();

            return $refund;
        });
        DB::table('refunds')->where('id', $candidate->id)->update([
            'status' => $target,
            $dateColumn => now(),
        ]);
        expect(DB::table('refunds')->where('id', $candidate->id)->value('status'))->toBe($target);
    }

    foreach ([
        ['succeeded', 'failed'],
        ['failed', 'cancelled'],
        ['cancelled', 'processing'],
    ] as [$terminal, $target]) {
        $candidate = DB::transaction(function () use ($payment, $terminal): Refund {
            $factory = Refund::factory()->forPayment($payment);
            $factory = match ($terminal) {
                'succeeded' => $factory->succeeded(),
                'failed' => $factory->failed(),
                'cancelled' => $factory->cancelled(),
            };
            $refund = $factory->create();
            if ($terminal === 'succeeded') {
                DB::table('orders')->where('id', $payment->order_id)->update(['status' => 'partially_refunded']);
            }
            forceP3CCConstraints();

            return $refund;
        });
        expectP3CCTriggerViolation(
            fn () => DB::table('refunds')->where('id', $candidate->id)->update(['status' => $target]),
            'refunds status transition is not allowed',
        );
    }
});

it('keeps refunds immutable and blocks physical deletion', function () {
    $payment = createRefundablePayment();
    $other = createRefundablePayment();
    $refund = DB::transaction(function () use ($payment): Refund {
        $r = Refund::factory()->forPayment($payment)->create();
        forceP3CCConstraints();

        return $r;
    });

    expectP3CCTriggerViolation(fn () => $refund->delete(), 'refunds are immutable and cannot be deleted');

    $deleteCandidates = [$refund];
    foreach (['processing', 'failed', 'cancelled'] as $state) {
        $deleteCandidates[] = DB::transaction(function () use ($payment, $state): Refund {
            $candidate = Refund::factory()->forPayment($payment)->{$state}()->create();
            forceP3CCConstraints();

            return $candidate;
        });
    }
    $succeededPayment = createRefundablePayment();
    $deleteCandidates[] = attachSucceededRefund($succeededPayment, 1000, OrderStatus::PartiallyRefunded);

    foreach ($deleteCandidates as $candidate) {
        expectP3CCTriggerViolation(
            fn () => DB::table('refunds')->where('id', $candidate->id)->delete(),
            'refunds are immutable and cannot be deleted',
        );
    }
    expectP3CCTriggerViolation(
        fn () => DB::table('refunds')->whereIn('id', array_map(fn (Refund $candidate): int => $candidate->id, $deleteCandidates))->delete(),
        'refunds are immutable and cannot be deleted',
    );
    expectP3CCTriggerViolation(
        fn () => $payment->refunds()->delete(),
        'refunds are immutable and cannot be deleted',
    );

    foreach ([
        ['public_id' => (string) Str::uuid()],
        ['payment_id' => $other->id],
        ['provider' => 'wave'],
        ['amount_minor' => 9999],
        ['currency' => 'USD'],
        ['idempotency_key_hash' => hash('sha256', 'changed')],
        ['reason_code' => 'changed_reason'],
        ['requested_at' => now()->subMinute()],
        ['created_at' => now()->subMinute()],
    ] as $mutation) {
        expectP3CCTriggerViolation(fn () => DB::table('refunds')->where('id', $refund->id)->update($mutation), 'refunds commercial data is immutable');
    }

    // provider reference set-once.
    DB::table('refunds')->where('id', $refund->id)->update(['provider_refund_reference' => 'rf_x']);
    expectP3CCTriggerViolation(fn () => DB::table('refunds')->where('id', $refund->id)->update(['provider_refund_reference' => 'rf_y']), 'refunds provider reference is immutable once set');
    expectP3CCTriggerViolation(fn () => DB::table('refunds')->where('id', $refund->id)->update(['provider_refund_reference' => null]), 'refunds provider reference is immutable once set');

    $processingAt = now();
    DB::table('refunds')->where('id', $refund->id)->update(['processing_at' => $processingAt]);
    expectP3CCTriggerViolation(
        fn () => DB::table('refunds')->where('id', $refund->id)->update(['processing_at' => $processingAt->copy()->addMinute()]),
        'refunds lifecycle dates are immutable once set',
    );
    expectP3CCTriggerViolation(
        fn () => DB::table('refunds')->where('id', $refund->id)->update(['processing_at' => null]),
        'refunds lifecycle dates are immutable once set',
    );

    $verifiedAt = now()->addMinute();
    DB::table('refunds')->where('id', $refund->id)->update(['last_verified_at' => $verifiedAt]);
    DB::table('refunds')->where('id', $refund->id)->update(['last_verified_at' => $verifiedAt->copy()->addMinute()]);
    expectP3CCTriggerViolation(
        fn () => DB::table('refunds')->where('id', $refund->id)->update(['last_verified_at' => $verifiedAt]),
        'refunds last_verified_at cannot move backwards',
    );
    expectP3CCTriggerViolation(
        fn () => DB::table('refunds')->where('id', $refund->id)->update(['last_verified_at' => null]),
        'refunds last_verified_at cannot move backwards',
    );

    DB::table('refunds')->where('id', $refund->id)->update([
        'provider_status' => 'provider_pending',
        'provider_metadata' => json_encode(['attempt' => 1]),
        'reason_note_sanitized' => 'Customer request confirmed.',
    ]);
    expect(DB::table('refunds')->where('id', $refund->id)->value('provider_status'))->toBe('provider_pending');
});

it('preserves refunds when the initiating user is deleted and forbids initiator replacement', function () {
    $payment = createRefundablePayment();
    $user = User::factory()->create();
    $replacement = User::factory()->create();
    $refund = DB::transaction(function () use ($payment, $user): Refund {
        $refund = Refund::factory()->forPayment($payment)->create(['initiated_by_user_id' => $user->id]);
        forceP3CCConstraints();

        return $refund;
    });

    expectP3CCTriggerViolation(
        fn () => DB::table('refunds')->where('id', $refund->id)->update(['initiated_by_user_id' => $replacement->id]),
        'refunds initiator reference may only be nulled',
    );
    expectP3CCTriggerViolation(
        fn () => DB::table('refunds')->where('id', $refund->id)->update(['initiated_by_user_id' => null]),
        'refunds initiator reference may only be nulled',
    );

    $financialSnapshot = DB::table('refunds')->where('id', $refund->id)->first([
        'payment_id', 'provider', 'amount_minor', 'currency', 'status',
    ]);

    $user->forceDelete();
    expect($refund->refresh()->initiated_by_user_id)->toBeNull()
        ->and(DB::table('refunds')->where('id', $refund->id)->exists())->toBeTrue()
        ->and(DB::table('refunds')->where('id', $refund->id)->first([
            'payment_id', 'provider', 'amount_minor', 'currency', 'status',
        ]))->toEqual($financialSnapshot);
});

it('enforces the cumulative capture cap including via update and status exclusion', function () {
    $payment = createRefundablePayment(10000);

    // Partial succeeded refund accepted with the order moved to partially_refunded.
    attachSucceededRefund($payment, 4000, OrderStatus::PartiallyRefunded);
    expect((int) DB::table('refunds')->where('payment_id', $payment->id)->where('status', 'succeeded')->sum('amount_minor'))->toBe(4000);

    // A second succeeded refund that would exceed the capture is rejected.
    expectP3CCTriggerViolation(function () use ($payment): void {
        Refund::factory()->forPayment($payment)->succeeded()->create(['amount_minor' => 7000]);
    }, 'refunds succeeded total exceeds the captured payment amount');

    // Completing to the exact captured amount is accepted (order -> refunded).
    attachSucceededRefund($payment, 6000, OrderStatus::Refunded);
    expect((int) DB::table('refunds')->where('payment_id', $payment->id)->where('status', 'succeeded')->sum('amount_minor'))->toBe(10000);

    // Non-succeeded refunds do not count toward the cap: a large pending refund is allowed.
    DB::transaction(function () use ($payment): void {
        Refund::factory()->forPayment($payment)->create(['amount_minor' => 9999, 'idempotency_key_hash' => hash('sha256', 'pending-big')]);
        forceP3CCConstraints();
    });

    // A pending refund promoted to succeeded that would exceed the cap is rejected on UPDATE.
    $extra = createRefundablePayment(10000);
    attachSucceededRefund($extra, 6000, OrderStatus::PartiallyRefunded);
    $pending = DB::transaction(function () use ($extra): Refund {
        $r = Refund::factory()->forPayment($extra)->processing()->create(['amount_minor' => 6000, 'idempotency_key_hash' => hash('sha256', 'to-succeed')]);
        forceP3CCConstraints();

        return $r;
    });
    expectP3CCTriggerViolation(fn () => DB::table('refunds')->where('id', $pending->id)->update(['status' => 'succeeded', 'succeeded_at' => now()]), 'refunds succeeded total exceeds the captured payment amount');

    // Excluding the current row on UPDATE permits the exact remaining amount.
    $exact = createRefundablePayment(10000);
    attachSucceededRefund($exact, 6000, OrderStatus::PartiallyRefunded);
    $remaining = DB::transaction(function () use ($exact): Refund {
        $refund = Refund::factory()->forPayment($exact)->processing()->create([
            'amount_minor' => 4000,
            'idempotency_key_hash' => hash('sha256', 'exact-current-row-exclusion'),
        ]);
        forceP3CCConstraints();

        return $refund;
    });
    DB::transaction(function () use ($exact, $remaining): void {
        DB::table('refunds')->where('id', $remaining->id)->update(['status' => 'succeeded', 'succeeded_at' => now()]);
        DB::table('orders')->where('id', $exact->order_id)->update(['status' => 'refunded']);
        forceP3CCConstraints();
    });
    expect((int) DB::table('refunds')->where('payment_id', $exact->id)->where('status', 'succeeded')->sum('amount_minor'))->toBe(10000);
});

it('enforces the complete deferred refund total to order status truth table', function () {
    foreach ([OrderStatus::PartiallyRefunded, OrderStatus::Refunded] as $invalidStatus) {
        $payment = createRefundablePayment(10000);
        expectP3CCDeferredViolation(
            fn () => DB::table('orders')->where('id', $payment->order_id)->update(['status' => $invalidStatus->value]),
            'refunds total is inconsistent with the order status',
        );
        expect(Order::find($payment->order_id)->status)->toBe(OrderStatus::Paid);
    }

    foreach ([OrderStatus::Paid, OrderStatus::Refunded] as $invalidStatus) {
        $payment = createRefundablePayment(10000);
        expectP3CCDeferredViolation(function () use ($payment, $invalidStatus): void {
            Refund::factory()->forPayment($payment)->succeeded()->create(['amount_minor' => 4000]);
            DB::table('orders')->where('id', $payment->order_id)->update(['status' => $invalidStatus->value]);
        }, 'refunds total is inconsistent with the order status');
        expect(Order::find($payment->order_id)->status)->toBe(OrderStatus::Paid)
            ->and(DB::table('refunds')->where('payment_id', $payment->id)->exists())->toBeFalse();
    }

    $partial = createRefundablePayment(10000);
    attachSucceededRefund($partial, 4000, OrderStatus::PartiallyRefunded);
    expect(Order::find($partial->order_id)->status)->toBe(OrderStatus::PartiallyRefunded);

    foreach ([OrderStatus::Paid, OrderStatus::PartiallyRefunded] as $invalidStatus) {
        $payment = createRefundablePayment(10000);
        expectP3CCDeferredViolation(function () use ($payment, $invalidStatus): void {
            Refund::factory()->forPayment($payment)->succeeded()->create(['amount_minor' => 10000]);
            DB::table('orders')->where('id', $payment->order_id)->update(['status' => $invalidStatus->value]);
        }, 'refunds total is inconsistent with the order status');
        expect(Order::find($payment->order_id)->status)->toBe(OrderStatus::Paid)
            ->and(DB::table('refunds')->where('payment_id', $payment->id)->exists())->toBeFalse();
    }

    $complete = createRefundablePayment(10000);
    attachSucceededRefund($complete, 10000, OrderStatus::Refunded);
    expect(Order::find($complete->order_id)->status)->toBe(OrderStatus::Refunded)
        ->and(DB::table('payments')->where('id', $complete->id)->value('status'))->toBe('succeeded');
});

it('rolls back only the P3C-C refunds migration while preserving P3C-B, P3C-A and P3B', function () {
    $harness = new PhaseMigrationHarness('digitrove_p3cc_rollback_'.strtolower(Str::random(10)));

    $boundary = '2026_07_14_000007_create_refunds_table.php';
    $functions = ['prevent_refunds_delete', 'enforce_refunds_immutability', 'validate_refund_payment_consistency', 'enforce_refund_cumulative_cap', 'validate_refund_order_consistency'];
    $triggers = ['refunds_prevent_delete_trigger', 'refunds_enforce_immutability_trigger', 'refunds_validate_payment_consistency_trigger', 'refunds_enforce_cumulative_cap_trigger', 'refunds_validate_order_consistency_trigger', 'orders_validate_refund_consistency_trigger'];

    try {
        $harness->create();
        $applied = $harness->applyMigrationsThrough($boundary);
        expect(end($applied))->toBe('2026_07_14_000007_create_refunds_table')
            ->and($applied)->toContain('2026_07_14_000006_harden_webhook_external_event_unique')
            ->and($harness->hasTable('refunds'))->toBeTrue()
            ->and($harness->countFunctions($functions))->toBe(5)
            ->and($harness->countTriggers($triggers))->toBe(6);

        $downed = $harness->rollbackExactMigrations([$boundary]);
        expect($downed)->toBe(['2026_07_14_000007_create_refunds_table'])
            ->and($harness->hasTable('refunds'))->toBeFalse()
            ->and($harness->countFunctions($functions))->toBe(0)
            ->and($harness->countTriggers($triggers))->toBe(0);

        // Earlier phases preserved.
        expect($harness->hasTable('payment_webhook_events'))->toBeTrue()
            ->and($harness->hasTable('payments'))->toBeTrue()
            ->and($harness->hasTable('orders'))->toBeTrue()
            ->and($harness->ranMigrations())->toContain('2026_07_14_000006_harden_webhook_external_event_unique')
            ->and($harness->hasConstraint('orders_coupon_snapshot_consistency_check'))->toBeTrue()
            ->and($harness->hasTable('download_grants'))->toBeFalse();
    } finally {
        $harness->drop();
    }

    expect(DB::table('pg_database')->where('datname', $harness->databaseName())->exists())->toBeFalse();
});

it('serialises concurrent succeeded refunds on the payment row and never exceeds the capture', function () {
    $harness = new PhaseMigrationHarness('digitrove_p3cc_concurrency_'.strtolower(Str::random(10)));
    $connection = config('database.connections.pgsql_migration');
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $connection['host'], $connection['port'] ?? 5432, $harness->databaseName());
    $pdo = fn (): PDO => new PDO($dsn, $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $a = null;
    $b = null;
    $child = null;

    try {
        $harness->create();
        $harness->applyMigrationsThrough('2026_07_14_000007_create_refunds_table.php');

        // Seed four independent paid orders and succeeded 10000 payments.
        $seed = <<<'PHP'
        $make = function () {
            return \Illuminate\Support\Facades\DB::transaction(function () {
                $product = \App\Models\Product::factory()->create();
                $order = \App\Models\Order::factory()->create(['subtotal_minor'=>10000,'discount_minor'=>0,'tax_minor'=>0,'total_minor'=>10000,'status'=>'paid','paid_at'=>now()]);
                \App\Models\OrderItem::factory()->forOrder($order)->forProduct($product)->create(['unit_price_minor'=>10000,'quantity'=>1,'line_subtotal_minor'=>10000,'line_discount_minor'=>0,'line_total_minor'=>10000,'currency'=>$order->currency]);
                $payment = \App\Models\Payment::factory()->forOrder($order)->succeeded()->create(['provider'=>'powerpay']);
                \Illuminate\Support\Facades\DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                return $payment->id.'|'.$order->id;
            });
        };
        echo 'SEED:'.$make().';'.$make().';'.$make().';'.$make();
        PHP;
        $process = new Process([PHP_BINARY, 'artisan', 'tinker', '--execute', $seed], base_path(), [
            // P4-B0: throwaway fixtures are seeded with the migrator/owner identity.
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql_migration', 'DB_DATABASE' => $harness->databaseName(),
        ]);
        $process->setTimeout(60);
        $process->run();
        expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
        expect($process->getOutput())->toMatch('/SEED:\d+\|\d+;\d+\|\d+;\d+\|\d+;\d+\|\d+/');
        preg_match('/SEED:([^\r\n]+)/', $process->getOutput(), $seedMatch);
        [$first, $second, $third, $fourth] = array_map(
            fn (string $pair): array => array_map('intval', explode('|', $pair)),
            explode(';', trim($seedMatch[1])),
        );
        [$paymentId, $orderId] = $first;
        [$secondPaymentId, $secondOrderId] = $second;
        [$thirdPaymentId, $thirdOrderId] = $third;
        [$transitionPaymentId, $transitionOrderId] = $fourth;

        $insertRefund = 'INSERT INTO refunds (public_id, payment_id, provider, idempotency_key_hash, amount_minor, currency, status, requested_at, succeeded_at, created_at, updated_at) '
            .'VALUES (gen_random_uuid(), :pid, \'powerpay\', :hash, 6000, \'XOF\', \'succeeded\', now(), now(), now(), now())';

        // Connection A: insert a succeeded refund of 6000, hold the payments-row lock (no commit yet).
        $a = $pdo();
        $a->beginTransaction();
        $stmtA = $a->prepare($insertRefund);
        $stmtA->execute(['pid' => $paymentId, 'hash' => hash('sha256', 'concurrent-a')]);

        // A real second process blocks on the same payments row until A commits.
        $childCode = <<<'PHP'
        $pdo = new PDO(getenv('TEST_DSN'), getenv('TEST_DB_USER'), getenv('TEST_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("SET lock_timeout = '10s'");
        $pdo->beginTransaction();
        echo "READY\n";
        flush();
        try {
            $sql = "INSERT INTO refunds (public_id, payment_id, provider, idempotency_key_hash, amount_minor, currency, status, requested_at, succeeded_at, created_at, updated_at) VALUES (gen_random_uuid(), :pid, 'powerpay', :hash, 6000, 'XOF', 'succeeded', now(), now(), now(), now())";
            $statement = $pdo->prepare($sql);
            $statement->execute(['pid' => (int) getenv('TEST_PAYMENT_ID'), 'hash' => hash('sha256', 'concurrent-b')]);
            $pdo->commit();
            echo 'UNEXPECTED_SUCCESS';
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo 'ERR:'.$exception->getCode().':'.$exception->getMessage();
        }
        PHP;
        $child = new Process([PHP_BINARY, '-r', $childCode], base_path(), [
            'TEST_DSN' => $dsn,
            'TEST_DB_USER' => (string) $connection['username'],
            'TEST_DB_PASSWORD' => (string) $connection['password'],
            'TEST_PAYMENT_ID' => (string) $paymentId,
        ]);
        $child->setTimeout(15);
        $child->start();

        $readyDeadline = microtime(true) + 5;
        while (! str_contains($child->getOutput(), 'READY') && microtime(true) < $readyDeadline) {
            usleep(50000);
        }
        expect($child->getOutput())->toContain('READY');
        usleep(250000);
        expect($child->isRunning())->toBeTrue('The second refund did not wait on the payment row lock.');

        // A commits. B wakes, sees 6000 already committed, then fails the cap trigger.
        $a->exec('UPDATE orders SET status = \'partially_refunded\' WHERE id = '.$orderId);
        $a->exec('SET CONSTRAINTS ALL IMMEDIATE');
        $a->commit();
        $child->wait();
        expect($child->isSuccessful())->toBeTrue($child->getOutput().$child->getErrorOutput())
            ->and($child->getOutput())->toContain('ERR:23514:')
            ->toContain('refunds succeeded total exceeds the captured payment amount')
            ->toContain('enforce_refund_cumulative_cap');

        // Final invariant: at most the captured amount was refunded.
        $total = (int) $pdo()->query("SELECT COALESCE(SUM(amount_minor),0) FROM refunds WHERE payment_id = {$paymentId} AND status = 'succeeded'")->fetchColumn();
        expect($total)->toBe(6000);

        // Holding payment 2 must not block a succeeded refund on independent payment 3.
        $a = $pdo();
        $a->beginTransaction();
        $stmtA = $a->prepare($insertRefund);
        $stmtA->execute(['pid' => $secondPaymentId, 'hash' => hash('sha256', 'independent-a')]);

        $b = $pdo();
        $b->exec("SET lock_timeout = '1500ms'");
        $b->beginTransaction();
        $stmtB = $b->prepare($insertRefund);
        $stmtB->execute(['pid' => $thirdPaymentId, 'hash' => hash('sha256', 'independent-b')]);
        $b->exec("UPDATE orders SET status = 'partially_refunded' WHERE id = {$thirdOrderId}");
        $b->exec('SET CONSTRAINTS ALL IMMEDIATE');
        $b->commit();

        $a->exec("UPDATE orders SET status = 'partially_refunded' WHERE id = {$secondOrderId}");
        $a->exec('SET CONSTRAINTS ALL IMMEDIATE');
        $a->commit();
        expect((int) $pdo()->query("SELECT COUNT(*) FROM refunds WHERE payment_id IN ({$secondPaymentId}, {$thirdPaymentId}) AND status = 'succeeded'")->fetchColumn())->toBe(2);

        // The same lock also protects two existing non-contributive refunds promoted
        // concurrently to succeeded; INSERT-only coverage would miss this path.
        $seedTransition = $pdo();
        $processingInsert = $seedTransition->prepare(
            "INSERT INTO refunds (public_id, payment_id, provider, idempotency_key_hash, amount_minor, currency, status, requested_at, processing_at, created_at, updated_at) VALUES (gen_random_uuid(), :pid, 'powerpay', :hash, 6000, 'XOF', 'processing', now(), now(), now(), now()) RETURNING id",
        );
        $processingInsert->execute(['pid' => $transitionPaymentId, 'hash' => hash('sha256', 'transition-a')]);
        $transitionRefundA = (int) $processingInsert->fetchColumn();
        $processingInsert->execute(['pid' => $transitionPaymentId, 'hash' => hash('sha256', 'transition-b')]);
        $transitionRefundB = (int) $processingInsert->fetchColumn();

        $a = $pdo();
        $a->beginTransaction();
        $a->exec("UPDATE refunds SET status = 'succeeded', succeeded_at = now() WHERE id = {$transitionRefundA}");

        $transitionChildCode = <<<'PHP'
        $pdo = new PDO(getenv('TEST_DSN'), getenv('TEST_DB_USER'), getenv('TEST_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("SET lock_timeout = '10s'");
        $pdo->beginTransaction();
        echo "READY\n";
        flush();
        try {
            $statement = $pdo->prepare("UPDATE refunds SET status = 'succeeded', succeeded_at = now() WHERE id = :id");
            $statement->execute(['id' => (int) getenv('TEST_REFUND_ID')]);
            $pdo->commit();
            echo 'UNEXPECTED_SUCCESS';
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo 'ERR:'.$exception->getCode().':'.$exception->getMessage();
        }
        PHP;
        $child = new Process([PHP_BINARY, '-r', $transitionChildCode], base_path(), [
            'TEST_DSN' => $dsn,
            'TEST_DB_USER' => (string) $connection['username'],
            'TEST_DB_PASSWORD' => (string) $connection['password'],
            'TEST_REFUND_ID' => (string) $transitionRefundB,
        ]);
        $child->setTimeout(15);
        $child->start();
        $readyDeadline = microtime(true) + 5;
        while (! str_contains($child->getOutput(), 'READY') && microtime(true) < $readyDeadline) {
            usleep(50000);
        }
        expect($child->getOutput())->toContain('READY');
        usleep(250000);
        expect($child->isRunning())->toBeTrue('The concurrent status transition did not wait on the payment row lock.');

        $a->exec("UPDATE orders SET status = 'partially_refunded' WHERE id = {$transitionOrderId}");
        $a->exec('SET CONSTRAINTS ALL IMMEDIATE');
        $a->commit();
        $child->wait();
        expect($child->isSuccessful())->toBeTrue($child->getOutput().$child->getErrorOutput())
            ->and($child->getOutput())->toContain('ERR:23514:')
            ->toContain('refunds succeeded total exceeds the captured payment amount')
            ->toContain('enforce_refund_cumulative_cap');
        expect((int) $pdo()->query("SELECT COALESCE(SUM(amount_minor),0) FROM refunds WHERE payment_id = {$transitionPaymentId} AND status = 'succeeded'")->fetchColumn())->toBe(6000);

        $a = null;
        $b = null;
    } finally {
        if ($child instanceof Process && $child->isRunning()) {
            $child->stop(1);
        }
        if ($a instanceof PDO && $a->inTransaction()) {
            $a->rollBack();
        }
        if ($b instanceof PDO && $b->inTransaction()) {
            $b->rollBack();
        }
        $a = null;
        $b = null;
        $harness->drop();
    }

    expect(DB::table('pg_database')->where('datname', $harness->databaseName())->exists())->toBeFalse();
});

it('does not introduce P6 marketing or downstream tables', function () {
    foreach (['campaigns'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("Unexpected out-of-scope table exists: {$table}");
    }
});
