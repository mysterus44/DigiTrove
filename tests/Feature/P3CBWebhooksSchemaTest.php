<?php

use App\Enums\WebhookProcessingStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;
use Tests\Support\PhaseMigrationHarness;

uses(RefreshDatabase::class);

function expectP3CBQueryException(Closure $callback, string $sqlState, string $messageFragment): void
{
    $exception = null;

    try {
        DB::transaction(function () use ($callback): void {
            $callback();
        });
    } catch (QueryException $queryException) {
        $exception = $queryException;
    }

    expect($exception)->not->toBeNull()
        ->and((string) $exception->getCode())->toBe($sqlState)
        ->and($exception->getMessage())->toContain($messageFragment);
}

function expectP3CBCheckViolation(Closure $callback, string $constraintName): void
{
    expectP3CBQueryException($callback, '23514', $constraintName);
}

function expectP3CBUniqueViolation(Closure $callback, string $constraintName): void
{
    expectP3CBQueryException($callback, '23505', $constraintName);
}

function expectP3CBTriggerViolation(Closure $callback, string $messageFragment): void
{
    expectP3CBQueryException($callback, '23514', $messageFragment);
}

function createLinkablePayment(string $provider = 'powerpay'): Payment
{
    return DB::transaction(function () use ($provider): Payment {
        $product = Product::factory()->create();
        $order = Order::factory()->create();
        OrderItem::factory()->forOrder($order)->forProduct($product)->create([
            'unit_price_minor' => $order->subtotal_minor,
            'quantity' => 1,
            'line_subtotal_minor' => $order->subtotal_minor,
            'line_discount_minor' => $order->discount_minor,
            'line_total_minor' => $order->subtotal_minor - $order->discount_minor,
            'currency' => $order->currency,
        ]);

        return Payment::factory()->forOrder($order)->create(['provider' => $provider, 'attempt_number' => 1]);
    });
}

it('runs P3C-B schema tests against PostgreSQL', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

it('creates the payment_webhook_events table with native types and hides the payload hash', function () {
    expect(Schema::hasTable('payment_webhook_events'))->toBeTrue()
        ->and(Schema::hasTable('events'))->toBeFalse();

    expect(Schema::hasColumns('payment_webhook_events', [
        'id', 'provider', 'external_event_id', 'payment_id', 'event_type', 'payload_hash',
        'filtered_payload', 'signature_verified', 'processing_status', 'received_at',
        'processed_at', 'failed_at', 'retention_until', 'processing_error_sanitized',
        'created_at', 'updated_at',
    ]))->toBeTrue();

    // No raw/sensitive columns must exist.
    foreach (['raw_payload', 'payload', 'signature', 'token', 'pan', 'cvv', 'secret'] as $forbidden) {
        expect(Schema::hasColumn('payment_webhook_events', $forbidden))->toBeFalse("forbidden column present: {$forbidden}");
    }

    $columns = DB::table('information_schema.columns')
        ->select('column_name', 'data_type', 'character_maximum_length')
        ->where('table_schema', 'public')->where('table_name', 'payment_webhook_events')
        ->get()->keyBy('column_name');

    expect($columns['provider']->data_type)->toBe('character varying')
        ->and($columns['provider']->character_maximum_length)->toBe(32)
        ->and($columns['external_event_id']->character_maximum_length)->toBe(255)
        ->and($columns['payload_hash']->character_maximum_length)->toBe(64)
        ->and($columns['event_type']->character_maximum_length)->toBe(100)
        ->and($columns['processing_status']->character_maximum_length)->toBe(20)
        ->and($columns['filtered_payload']->data_type)->toBe('jsonb')
        ->and($columns['signature_verified']->data_type)->toBe('boolean')
        ->and($columns['payment_id']->data_type)->toBe('bigint')
        ->and($columns['received_at']->data_type)->toBe('timestamp with time zone');

    expect(array_map(fn (WebhookProcessingStatus $s): string => $s->value, WebhookProcessingStatus::cases()))
        ->toBe(['received', 'processed', 'ignored', 'failed']);

    $event = PaymentWebhookEvent::factory()->create();
    expect($event->toArray())->not->toHaveKey('payload_hash')
        ->and($event->getRawOriginal('payload_hash'))->toMatch('/^[0-9a-f]{64}$/');
});

it('defines the FK, constraints, indexes, three functions and three non-deferred triggers', function () {
    $foreignKey = DB::table('pg_constraint')->where('conname', 'payment_webhook_events_payment_id_foreign')->first();
    expect($foreignKey)->not->toBeNull()->and($foreignKey->confdeltype)->toBe('r');

    $constraints = DB::table('pg_constraint')
        ->join('pg_class', 'pg_class.oid', '=', 'pg_constraint.conrelid')
        ->where('pg_class.relname', 'payment_webhook_events')->where('pg_constraint.contype', 'c')
        ->pluck('pg_constraint.conname');
    foreach ([
        'payment_webhook_events_provider_format_check',
        'payment_webhook_events_external_id_not_blank_check',
        'payment_webhook_events_event_type_not_blank_check',
        'payment_webhook_events_payload_hash_format_check',
        'payment_webhook_events_filtered_payload_object_check',
        'payment_webhook_events_processing_status_check',
        'payment_webhook_events_cycle_dates_check',
        'payment_webhook_events_status_dates_consistency_check',
        'payment_webhook_events_signed_requires_external_id_check',
        'payment_webhook_events_invalid_minimal_shape_check',
    ] as $name) {
        expect($constraints)->toContain($name);
    }

    $indexes = DB::table('pg_indexes')->where('tablename', 'payment_webhook_events')->pluck('indexdef', 'indexname');
    expect($indexes['payment_webhook_events_provider_external_event_unique'])->toContain('UNIQUE')
        ->and($indexes['payment_webhook_events_provider_external_event_unique'])->toContain('external_event_id IS NOT NULL')
        ->and($indexes['payment_webhook_events_provider_payload_hash_unique'])->toContain('signature_verified = false')
        ->and($indexes)->toHaveKey('payment_webhook_events_payment_id_index')
        ->and($indexes)->toHaveKey('payment_webhook_events_processing_received_index')
        ->and($indexes)->toHaveKey('payment_webhook_events_retention_until_index');

    $functions = DB::table('pg_proc')->whereIn('proname', [
        'enforce_webhook_event_immutability',
        'validate_webhook_payment_consistency',
        'enforce_webhook_event_retention_delete',
    ])->pluck('proname');
    expect($functions)->toHaveCount(3);

    $triggers = DB::table('pg_trigger')->select('tgname', 'tgdeferrable')->whereIn('tgname', [
        'payment_webhook_events_enforce_immutability_trigger',
        'payment_webhook_events_validate_payment_consistency_trigger',
        'payment_webhook_events_enforce_retention_delete_trigger',
    ])->get();
    expect($triggers)->toHaveCount(3);
    foreach ($triggers as $trigger) {
        expect((bool) $trigger->tgdeferrable)->toBeFalse("trigger should not be deferrable: {$trigger->tgname}");
    }
});

it('enforces provider, hash and payload constraints', function () {
    PaymentWebhookEvent::factory()->create(['provider' => 'powerpay']);
    PaymentWebhookEvent::factory()->create(['provider' => 'provider_test']);
    expect(DB::table('payment_webhook_events')->count())->toBe(2);

    foreach (['PowerPay', 'POWERPAY', ' powerpay', 'power pay', ''] as $bad) {
        expectP3CBCheckViolation(fn () => PaymentWebhookEvent::factory()->create(['provider' => $bad]), 'payment_webhook_events_provider_format_check');
    }
    expectP3CBCheckViolation(fn () => PaymentWebhookEvent::factory()->create(['payload_hash' => str_repeat('A', 64)]), 'payment_webhook_events_payload_hash_format_check');
    expectP3CBCheckViolation(fn () => PaymentWebhookEvent::factory()->create(['filtered_payload' => [1, 2, 3]]), 'payment_webhook_events_filtered_payload_object_check');
    expectP3CBCheckViolation(fn () => PaymentWebhookEvent::factory()->create(['filtered_payload' => 'a string']), 'payment_webhook_events_filtered_payload_object_check');
});

it('enforces signed and invalid webhook shapes', function () {
    // Signed valid, received.
    PaymentWebhookEvent::factory()->create();
    // Invalid minimal shape is accepted.
    PaymentWebhookEvent::factory()->invalidSignatureMinimal()->create();
    expect(DB::table('payment_webhook_events')->count())->toBe(2);

    // Signed without external id -> refused.
    expectP3CBCheckViolation(fn () => PaymentWebhookEvent::factory()->create(['external_event_id' => null]), 'payment_webhook_events_signed_requires_external_id_check');
    // Blank external id on an (unsigned) invalid event -> not-blank check.
    expectP3CBCheckViolation(fn () => PaymentWebhookEvent::factory()->invalidSignatureMinimal()->create(['external_event_id' => '   ']), 'payment_webhook_events_external_id_not_blank_check');

    // Invalid variants that break the minimal shape.
    expectP3CBCheckViolation(fn () => PaymentWebhookEvent::factory()->invalidSignatureMinimal()->create(['filtered_payload' => ['x' => 1]]), 'payment_webhook_events_invalid_minimal_shape_check');
    // Unsigned invalid missing failed_at breaks the minimal shape.
    expectP3CBCheckViolation(fn () => PaymentWebhookEvent::factory()->invalidSignatureMinimal()->create(['failed_at' => null]), 'payment_webhook_events_invalid_minimal_shape_check');
    // A signed 'failed' event without failed_at breaks status/date coherence (isolated).
    expectP3CBCheckViolation(fn () => PaymentWebhookEvent::factory()->failed()->create(['failed_at' => null]), 'payment_webhook_events_status_dates_consistency_check');
    expectP3CBCheckViolation(fn () => PaymentWebhookEvent::factory()->invalidSignatureMinimal()->create(['processing_error_sanitized' => null]), 'payment_webhook_events_invalid_minimal_shape_check');
    // Unsigned but not 'failed' status -> invalid minimal shape.
    expectP3CBCheckViolation(fn () => PaymentWebhookEvent::factory()->create([
        'signature_verified' => false, 'processing_status' => 'received', 'external_event_id' => null,
        'filtered_payload' => null,
    ]), 'payment_webhook_events_invalid_minimal_shape_check');

    // Duplicate (provider, external_event_id) refused.
    $event = PaymentWebhookEvent::factory()->create(['provider' => 'powerpay', 'external_event_id' => 'evt_dup']);
    expectP3CBUniqueViolation(fn () => PaymentWebhookEvent::factory()->create(['provider' => 'powerpay', 'external_event_id' => 'evt_dup']), 'payment_webhook_events_provider_external_event_unique');
    // Same external id, different provider -> accepted.
    PaymentWebhookEvent::factory()->create(['provider' => 'wave', 'external_event_id' => 'evt_dup']);

    // Duplicate (provider, payload_hash) for invalid events refused; different provider accepted.
    $hash = hash('sha256', 'shared-invalid-hash');
    PaymentWebhookEvent::factory()->invalidSignatureMinimal()->create(['provider' => 'powerpay', 'payload_hash' => $hash]);
    expectP3CBUniqueViolation(fn () => PaymentWebhookEvent::factory()->invalidSignatureMinimal()->create(['provider' => 'powerpay', 'payload_hash' => $hash]), 'payment_webhook_events_provider_payload_hash_unique');
    PaymentWebhookEvent::factory()->invalidSignatureMinimal()->create(['provider' => 'wave', 'payload_hash' => $hash]);

    expect($event->exists)->toBeTrue();
});

it('enforces invalid enum status via a raw insert', function () {
    expectP3CBCheckViolation(function (): void {
        DB::table('payment_webhook_events')->insert([
            'provider' => 'powerpay',
            'external_event_id' => 'evt_'.Str::uuid(),
            'payment_id' => null,
            'event_type' => null,
            'payload_hash' => hash('sha256', 'raw-'.Str::uuid()),
            'filtered_payload' => null,
            'signature_verified' => true,
            'processing_status' => 'duplicate',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }, 'payment_webhook_events_processing_status_check');
});

it('validates webhook-to-payment consistency and immutable linkage', function () {
    $payment = createLinkablePayment('powerpay');
    $otherProvider = createLinkablePayment('wave');

    // Signed event, same provider -> accepted.
    $linked = PaymentWebhookEvent::factory()->forPayment($payment)->create();
    expect($linked->payment->is($payment))->toBeTrue()
        ->and($payment->webhookEvents->first()->is($linked))->toBeTrue();

    // Different provider than the payment -> refused.
    expectP3CBTriggerViolation(fn () => PaymentWebhookEvent::factory()->create([
        'provider' => 'wave', 'payment_id' => $payment->id,
    ]), 'payment_webhook_events provider must match the linked payment provider');

    // Unsigned event linked to a payment -> refused.
    expectP3CBTriggerViolation(fn () => PaymentWebhookEvent::factory()->create([
        'provider' => 'powerpay', 'payment_id' => $payment->id, 'signature_verified' => false,
        'processing_status' => 'failed', 'filtered_payload' => null, 'failed_at' => now(),
        'processing_error_sanitized' => 'x', 'external_event_id' => null,
    ]), 'payment_webhook_events cannot link an unsigned event to a payment');

    // payment_id NULL -> value accepted; replacement and removal refused.
    $event = PaymentWebhookEvent::factory()->create(['provider' => 'powerpay', 'payment_id' => null]);
    DB::table('payment_webhook_events')->where('id', $event->id)->update(['payment_id' => $payment->id]);
    expect(DB::table('payment_webhook_events')->where('id', $event->id)->value('payment_id'))->toBe($payment->id);

    expectP3CBTriggerViolation(fn () => DB::table('payment_webhook_events')->where('id', $event->id)->update(['payment_id' => $otherProvider->id]), 'payment reference is immutable once set');
    expectP3CBTriggerViolation(fn () => DB::table('payment_webhook_events')->where('id', $event->id)->update(['payment_id' => null]), 'payment reference is immutable once set');
});

it('keeps audit columns immutable and event_type / dates set-once', function () {
    $event = PaymentWebhookEvent::factory()->create();

    foreach ([
        ['provider' => 'wave'],
        ['external_event_id' => 'evt_changed'],
        ['payload_hash' => hash('sha256', 'changed')],
        ['signature_verified' => false],
        ['filtered_payload' => json_encode(['x' => 2])],
    ] as $mutation) {
        expectP3CBTriggerViolation(fn () => DB::table('payment_webhook_events')->where('id', $event->id)->update($mutation), 'payment_webhook_events audit data is immutable');
    }

    // event_type: NULL -> value accepted, replacement refused.
    $noType = PaymentWebhookEvent::factory()->create(['event_type' => null]);
    DB::table('payment_webhook_events')->where('id', $noType->id)->update(['event_type' => 'payment.succeeded']);
    expect(DB::table('payment_webhook_events')->where('id', $noType->id)->value('event_type'))->toBe('payment.succeeded');
    expectP3CBTriggerViolation(fn () => DB::table('payment_webhook_events')->where('id', $noType->id)->update(['event_type' => 'other']), 'event_type is immutable once set');

    // processed_at set-once.
    $processed = PaymentWebhookEvent::factory()->processed()->create();
    expectP3CBTriggerViolation(fn () => DB::table('payment_webhook_events')->where('id', $processed->id)->update(['processed_at' => now()->addHour()]), 'processing dates are immutable once set');

    // retention: extension accepted, shortening refused.
    $retained = PaymentWebhookEvent::factory()->create();
    $current = $retained->retention_until;
    DB::table('payment_webhook_events')->where('id', $retained->id)->update(['retention_until' => $current->copy()->addDays(30)]);
    expectP3CBTriggerViolation(fn () => DB::table('payment_webhook_events')->where('id', $retained->id)->update(['retention_until' => $current->copy()->subDays(30)]), 'retention_until cannot be shortened or cleared');
});

it('allows only received-to-terminal transitions', function () {
    // received -> processed / ignored / failed accepted (with coherent dates).
    $a = PaymentWebhookEvent::factory()->create();
    DB::table('payment_webhook_events')->where('id', $a->id)->update(['processing_status' => 'processed', 'processed_at' => now()]);
    expect(DB::table('payment_webhook_events')->where('id', $a->id)->value('processing_status'))->toBe('processed');

    $b = PaymentWebhookEvent::factory()->create();
    DB::table('payment_webhook_events')->where('id', $b->id)->update(['processing_status' => 'ignored', 'processed_at' => now()]);
    $c = PaymentWebhookEvent::factory()->create();
    DB::table('payment_webhook_events')->where('id', $c->id)->update(['processing_status' => 'failed', 'failed_at' => now()]);
    expect(DB::table('payment_webhook_events')->where('id', $c->id)->value('processing_status'))->toBe('failed');

    // Terminal states are final.
    $processed = PaymentWebhookEvent::factory()->processed()->create();
    expectP3CBTriggerViolation(fn () => DB::table('payment_webhook_events')->where('id', $processed->id)->update(['processing_status' => 'ignored']), 'processing status transition is not allowed');
    $failed = PaymentWebhookEvent::factory()->failed()->create();
    expectP3CBTriggerViolation(fn () => DB::table('payment_webhook_events')->where('id', $failed->id)->update(['processing_status' => 'processed', 'processed_at' => now()]), 'processing status transition is not allowed');
});

it('permits deletion only after retention on a terminal status', function () {
    // received -> refused.
    $received = PaymentWebhookEvent::factory()->create();
    expectP3CBTriggerViolation(fn () => DB::table('payment_webhook_events')->where('id', $received->id)->delete(), 'may only be deleted after retention on a terminal status');

    // terminal without retention -> refused.
    $noRetention = PaymentWebhookEvent::factory()->processed()->create(['retention_until' => null]);
    expectP3CBTriggerViolation(fn () => DB::table('payment_webhook_events')->where('id', $noRetention->id)->delete(), 'may only be deleted after retention on a terminal status');

    // terminal with future retention -> refused.
    $future = PaymentWebhookEvent::factory()->processed()->create(['retention_until' => now()->addDays(90)]);
    expectP3CBTriggerViolation(fn () => DB::table('payment_webhook_events')->where('id', $future->id)->delete(), 'may only be deleted after retention on a terminal status');

    // terminal after retention -> allowed.
    $past = PaymentWebhookEvent::factory()->processed()->create([
        'received_at' => now()->subDays(100),
        'processed_at' => now()->subDays(99),
        'retention_until' => now()->subDay(),
    ]);
    DB::table('payment_webhook_events')->where('id', $past->id)->delete();
    expect(DB::table('payment_webhook_events')->where('id', $past->id)->exists())->toBeFalse();
});

it('does not let an unsigned event reserve the signed external-event uniqueness', function () {
    // An unsigned/invalid webhook forging an external_event_id is stored (kept for audit)...
    PaymentWebhookEvent::factory()->invalidSignatureMinimal()->create([
        'provider' => 'powerpay',
        'external_event_id' => 'evt_shared',
        'payload_hash' => hash('sha256', 'unsigned-poison'),
    ]);

    // ...but it must NOT block a later legitimate signed event with the same identifier.
    $signed = PaymentWebhookEvent::factory()->create([
        'provider' => 'powerpay',
        'external_event_id' => 'evt_shared',
    ]);
    expect($signed->exists)->toBeTrue();

    // Replay dedup for SIGNED events stays intact: a second signed event still conflicts.
    expectP3CBUniqueViolation(fn () => PaymentWebhookEvent::factory()->create([
        'provider' => 'powerpay',
        'external_event_id' => 'evt_shared',
    ]), 'payment_webhook_events_provider_external_event_unique');
});

it('rolls back only the P3C-B webhook migration while preserving P3C-A and P3B', function () {
    $harness = new PhaseMigrationHarness('digitrove_p3cb_rollback_'.strtolower(Str::random(10)));

    $boundary = '2026_07_14_000005_create_payment_webhook_events_table.php';
    $gate = ['2026_07_14_000005_create_payment_webhook_events_table.php'];
    $functions = ['enforce_webhook_event_immutability', 'validate_webhook_payment_consistency', 'enforce_webhook_event_retention_delete'];
    $triggers = [
        'payment_webhook_events_enforce_immutability_trigger',
        'payment_webhook_events_validate_payment_consistency_trigger',
        'payment_webhook_events_enforce_retention_delete_trigger',
    ];

    try {
        $harness->create();
        $applied = $harness->applyMigrationsThrough($boundary);

        expect($harness->currentDatabase())->toBe($harness->databaseName())
            ->and($applied)->toContain('2026_07_14_000005_create_payment_webhook_events_table')
            ->and(end($applied))->toBe('2026_07_14_000005_create_payment_webhook_events_table');

        expect($harness->hasTable('payment_webhook_events'))->toBeTrue()
            ->and($harness->hasTable('payments'))->toBeTrue()
            ->and($harness->hasTable('orders'))->toBeTrue()
            ->and($harness->hasTable('refunds'))->toBeFalse()
            ->and($harness->countFunctions($functions))->toBe(3)
            ->and($harness->countTriggers($triggers))->toBe(3);

        $downed = $harness->rollbackExactMigrations($gate);
        expect($downed)->toBe(['2026_07_14_000005_create_payment_webhook_events_table']);

        // P3C-B objects gone.
        expect($harness->hasTable('payment_webhook_events'))->toBeFalse()
            ->and($harness->countFunctions($functions))->toBe(0)
            ->and($harness->countTriggers($triggers))->toBe(0);

        // P3C-A preserved.
        expect($harness->hasTable('payments'))->toBeTrue()
            ->and($harness->countFunctions(['prevent_payments_delete', 'validate_payment_order_consistency']))->toBe(2);
        // P3B preserved.
        expect($harness->hasTable('orders'))->toBeTrue()
            ->and($harness->hasConstraint('orders_coupon_snapshot_consistency_check'))->toBeTrue();
        // No later-phase table applied.
        expect($harness->hasTable('refunds'))->toBeFalse()
            ->and($harness->hasTable('download_grants'))->toBeFalse();
    } finally {
        $harness->drop();
    }
});

it('does not introduce delivery or downstream tables', function () {
    foreach (['events', 'licenses'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("Unexpected out-of-scope table exists: {$table}");
    }
});
