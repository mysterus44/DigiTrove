<?php

declare(strict_types=1);

use App\Enums\CrmOrderAttributionReason;
use App\Enums\CrmOrderAttributionSource;
use App\Enums\CrmOrderAttributionStatus;
use App\Enums\OrderStatus;
use App\Enums\UserStatus;
use App\Models\CrmOrderAttribution;
use App\Models\CrmOrderAttributionOutbox;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithCrmDatabase;

uses(InteractsWithCrmDatabase::class);

const P6A10_LIST_SIGNATURE = 'public.list_due_crm_order_attributions(integer)';
const P6A10_PROCESS_SIGNATURE = 'public.process_crm_order_attribution(bigint)';

function p6a10Resolve(string $email, string $origin, ?int $userId = null, ?int $orderId = null): object
{
    return DB::selectOne(
        'SELECT * FROM public.resolve_crm_contact(?::varchar, ?::varchar, ?::bigint, ?::bigint)',
        [$email, $origin, $userId, $orderId],
    );
}

function p6a10Process(int $orderId): object
{
    return DB::selectOne('SELECT * FROM public.process_crm_order_attribution(?::bigint)', [$orderId]);
}

function p6a10Acquire(Order $order): void
{
    DB::transaction(function () use ($order): void {
        if ((int) $order->total_minor > 0) {
            Payment::factory()->forOrder($order)->succeeded()->create();
        }

        $order->forceFill([
            'status' => OrderStatus::Paid->value,
            'paid_at' => now(),
        ])->save();

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    });
}

function p6a10FreeOrder(string $email): Order
{
    return DB::transaction(function () use ($email): Order {
        $productId = Product::factory()->create()->id;
        $order = Order::factory()->create([
            'customer_email' => $email,
            'subtotal_minor' => 0,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 0,
            'status' => OrderStatus::Pending,
        ]);

        DB::table('order_items')->insert([
            'order_id' => $order->id,
            'product_id' => null,
            'purchased_product_id' => $productId,
            'product_name_snapshot' => 'Free CRM fixture',
            'product_slug_snapshot' => 'free-crm-fixture',
            'product_type_snapshot' => 'ebook',
            'unit_price_minor' => 0,
            'quantity' => 1,
            'line_subtotal_minor' => 0,
            'line_discount_minor' => 0,
            'line_total_minor' => 0,
            'currency' => 'XOF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $order;
    });
}

function p6a10ExpectRefusal(Closure $callback, string $message, string $sqlState = '23514'): void
{
    $exception = null;

    try {
        $callback();
    } catch (QueryException $queryException) {
        $exception = $queryException;
    }

    expect($exception)->not->toBeNull()
        ->and((string) $exception->getCode())->toBe($sqlState)
        ->and($exception->getMessage())->toContain($message);
}

it('installs exactly the P6-A1.0 tables with closed physical contracts', function () {
    $owner = DB::connection('pgsql_migration');
    $columns = collect($owner->select(<<<'SQL'
        SELECT table_name, column_name, udt_name, character_maximum_length,
               datetime_precision, is_nullable, column_default
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name IN ('crm_order_attribution_outbox', 'crm_order_attributions')
        ORDER BY table_name, ordinal_position
        SQL))->keyBy(fn (object $column): string => $column->table_name.'.'.$column->column_name);

    expect(Schema::hasTable('crm_order_attribution_outbox'))->toBeTrue()
        ->and(Schema::hasTable('crm_order_attributions'))->toBeTrue()
        ->and($columns)->toHaveCount(12)
        ->and($columns['crm_order_attribution_outbox.order_id']->udt_name)->toBe('int8')
        ->and($columns['crm_order_attribution_outbox.contact_id_snapshot']->udt_name)->toBe('int8')
        ->and($columns['crm_order_attribution_outbox.attempt_count']->udt_name)->toBe('int4')
        ->and($columns['crm_order_attribution_outbox.available_at']->udt_name)->toBe('timestamptz')
        ->and($columns['crm_order_attribution_outbox.available_at']->datetime_precision)->toBe(6)
        ->and($columns['crm_order_attributions.attributed_at']->udt_name)->toBe('timestamptz')
        ->and($columns->keys()->contains(fn (string $key): bool => str_contains($key, 'email')))->toBeFalse()
        ->and($columns->keys()->contains(fn (string $key): bool => str_contains($key, 'visitor')))->toBeFalse()
        ->and($columns->contains(fn (object $column): bool => $column->udt_name === 'jsonb'))->toBeFalse()
        ->and(CrmOrderAttributionStatus::cases())->toHaveCount(3)
        ->and(CrmOrderAttributionReason::cases())->toHaveCount(2)
        ->and(CrmOrderAttributionSource::cases())->toHaveCount(3)
        ->and(glob(database_path('migrations').'/*.php'))->toHaveCount(37)
        ->and(glob(database_path('migrations').'/2026_07_14_000022*.php') ?: [])->toBe([]);
});

it('installs exact restrictive foreign keys checks indexes functions and triggers', function () {
    $owner = DB::connection('pgsql_migration');
    $foreignKeys = collect($owner->select(<<<'SQL'
        SELECT c.conname, c.confdeltype
        FROM pg_constraint c
        WHERE c.conrelid IN (
            'public.crm_order_attribution_outbox'::regclass,
            'public.crm_order_attributions'::regclass
        ) AND c.contype = 'f'
        ORDER BY c.conname
        SQL))->pluck('confdeltype', 'conname');
    $indexes = collect($owner->select(<<<'SQL'
        SELECT indexname, indexdef
        FROM pg_indexes
        WHERE schemaname = 'public'
          AND tablename IN ('crm_order_attribution_outbox', 'crm_order_attributions')
        ORDER BY indexname
        SQL))->pluck('indexdef', 'indexname');
    $functions = collect($owner->select(<<<'SQL'
        SELECT p.proname, p.prosecdef, p.proconfig, pg_get_userbyid(p.proowner) AS owner
        FROM pg_proc p
        WHERE p.pronamespace = 'public'::regnamespace
          AND p.proname IN (
              'enforce_crm_order_attribution_outbox_integrity',
              'prevent_crm_order_attribution_mutation',
              'enqueue_crm_order_attribution',
              'list_due_crm_order_attributions',
              'process_crm_order_attribution'
          )
        ORDER BY p.proname
        SQL))->keyBy('proname');
    $triggers = collect($owner->select(<<<'SQL'
        SELECT t.tgname, c.relname AS table_name, pg_get_triggerdef(t.oid) AS definition
        FROM pg_trigger t
        JOIN pg_class c ON c.oid = t.tgrelid
        WHERE NOT t.tgisinternal
          AND t.tgname IN (
              'crm_order_attribution_outbox_integrity_trigger',
              'crm_order_attributions_immutable_trigger',
              'orders_enqueue_crm_attribution_trigger'
          )
        ORDER BY t.tgname
        SQL))->keyBy('tgname');

    expect($foreignKeys)->toHaveCount(4)
        ->and($foreignKeys->every(fn (string $action): bool => $action === 'r'))->toBeTrue()
        ->and($indexes)->toHaveCount(3)
        ->and($indexes)->toHaveKeys([
            'crm_order_attribution_outbox_pkey',
            'crm_order_attributions_pkey',
            'crm_order_attributions_contact_order_index',
        ])
        ->and($indexes['crm_order_attributions_contact_order_index'])->toContain('(contact_id, order_id)')
        ->and($functions)->toHaveCount(5)
        ->and($triggers)->toHaveCount(3)
        ->and($triggers['orders_enqueue_crm_attribution_trigger']->table_name)->toBe('orders')
        ->and($triggers['orders_enqueue_crm_attribution_trigger']->definition)->toContain('AFTER INSERT OR UPDATE OF status, paid_at');

    foreach ($functions as $function) {
        expect($function->owner)->toBe('digitrove_crm_executor')
            ->and($function->proconfig)->toContain('search_path=pg_catalog, public, pg_temp');
    }

    expect($functions['enqueue_crm_order_attribution']->prosecdef)->toBeTrue()
        ->and($functions['list_due_crm_order_attributions']->prosecdef)->toBeTrue()
        ->and($functions['process_crm_order_attribution']->prosecdef)->toBeTrue();
});

it('keeps runtime execute-only and trigger authorities non-callable', function () {
    $owner = DB::connection('pgsql_migration');

    foreach (['crm_order_attribution_outbox', 'crm_order_attributions'] as $table) {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'] as $privilege) {
            expect((bool) $owner->scalar(
                "SELECT has_table_privilege('digitrove_runtime', ?, ?)",
                ["public.{$table}", $privilege],
            ))->toBeFalse("runtime unexpectedly has {$privilege} on {$table}");
        }
    }

    foreach ([P6A10_LIST_SIGNATURE, P6A10_PROCESS_SIGNATURE] as $signature) {
        expect((bool) $owner->scalar(
            "SELECT has_function_privilege('digitrove_runtime', ?, 'EXECUTE')",
            [$signature],
        ))->toBeTrue();
    }

    foreach ([
        'public.enqueue_crm_order_attribution()',
        'public.enforce_crm_order_attribution_outbox_integrity()',
        'public.prevent_crm_order_attribution_mutation()',
    ] as $signature) {
        expect((bool) $owner->scalar(
            "SELECT has_function_privilege('digitrove_runtime', ?, 'EXECUTE')",
            [$signature],
        ))->toBeFalse();
    }

    p6a10ExpectRefusal(
        fn () => DB::selectOne('SELECT public.enqueue_crm_order_attribution()'),
        'permission denied',
        '42501',
    );
});

it('enqueues acquired paid and free orders without resolving a contact', function () {
    $paid = $this->crmPendingOrder('paid-outbox@example.test');
    $free = p6a10FreeOrder('free-outbox@example.test');

    p6a10Acquire($paid);
    p6a10Acquire($free);

    $rows = DB::connection('pgsql_migration')->table('crm_order_attribution_outbox')->orderBy('order_id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('order_id')->all())->toBe([$paid->id, $free->id])
        ->and($rows->pluck('status')->unique()->all())->toBe(['pending'])
        ->and($rows->pluck('attempt_count')->unique()->all())->toBe([0])
        ->and($rows->pluck('contact_id_snapshot')->filter()->all())->toBe([])
        ->and(DB::connection('pgsql_migration')->table('crm_contacts')->count())->toBe(0);
});

it('captures one exact active contact and never replaces the snapshot', function () {
    $order = $this->crmPendingOrder('snapshot@example.test');
    $contact = p6a10Resolve('snapshot@example.test', 'guest_order', orderId: $order->id);
    p6a10Acquire($order);

    $snapshot = DB::connection('pgsql_migration')->table('crm_order_attribution_outbox')->where('order_id', $order->id)->first();
    expect($snapshot->contact_id_snapshot)->toBe((int) $contact->contact_id);

    $owner = DB::connection('pgsql_migration');
    p6a10ExpectRefusal(
        fn () => $owner->table('crm_order_attribution_outbox')->where('order_id', $order->id)->update([
            'contact_id_snapshot' => null,
            'updated_at' => now(),
        ]),
        'CRM order attribution outbox evidence is immutable',
    );
});

it('does not enqueue unpaid states and does not duplicate acquired transitions', function () {
    foreach ([OrderStatus::Pending, OrderStatus::PaymentReview, OrderStatus::Cancelled, OrderStatus::Expired] as $status) {
        $order = $this->crmPendingOrder($status->value.'@example.test');
        if ($status !== OrderStatus::Pending) {
            DB::transaction(function () use ($order, $status): void {
                if ($status === OrderStatus::PaymentReview) {
                    Payment::factory()->forOrder($order)->requiresReview()->create();
                }
                $order->forceFill([
                    'status' => $status->value,
                    'cancelled_at' => $status === OrderStatus::Cancelled ? now() : null,
                ])->save();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
        }
    }

    expect(DB::connection('pgsql_migration')->table('crm_order_attribution_outbox')->count())->toBe(0);

    $order = $this->crmPendingOrder('single-outbox@example.test');
    $payment = null;
    DB::transaction(function () use ($order, &$payment): void {
        $payment = Payment::factory()->forOrder($order)->succeeded()->create();
        $order->forceFill(['status' => OrderStatus::Paid->value, 'paid_at' => now()])->save();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    });

    DB::transaction(function () use ($order, $payment): void {
        Refund::factory()->forPayment($payment)->succeeded()->create(['amount_minor' => 1_000]);
        $order->forceFill(['status' => OrderStatus::PartiallyRefunded->value])->save();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    });

    expect(DB::connection('pgsql_migration')->table('crm_order_attribution_outbox')->where('order_id', $order->id)->count())
        ->toBe(1);
});

it('preserves a captured contact through anonymization and same-email recreation', function () {
    $order = $this->crmPendingOrder('history@example.test');
    $old = p6a10Resolve('history@example.test', 'guest_order', orderId: $order->id);
    p6a10Acquire($order);

    $owner = DB::connection('pgsql_migration');
    $owner->table('crm_contacts')->where('id', $old->contact_id)->update([
        'email' => null,
        'user_id' => null,
        'status' => 'anonymized',
        'anonymized_at' => now(),
        'updated_at' => now(),
    ]);

    $otherOrder = $this->crmPendingOrder('history@example.test');
    $new = p6a10Resolve('history@example.test', 'guest_order', orderId: $otherOrder->id);
    $result = p6a10Process($order->id);

    expect($result->status)->toBe('attributed')
        ->and($result->source)->toBe('existing_contact_snapshot')
        ->and($result->contact_public_id)->toBe($old->public_id)
        ->and($new->contact_id)->not->toBe($old->contact_id)
        ->and($owner->table('crm_order_attributions')->where('order_id', $order->id)->value('contact_id'))
        ->toBe((int) $old->contact_id);
});

it('resolves verified accounts and falls back to guest evidence for inactive identities', function (UserStatus $status, string $expectedSource) {
    $email = 'processor-'.$status->value.'@example.test';
    $user = User::factory()->create(['email' => $email, 'status' => $status]);
    $order = $this->crmPendingOrder($email, $user);
    p6a10Acquire($order);

    $result = p6a10Process($order->id);
    $contact = DB::connection('pgsql_migration')->table('crm_contacts')->where('email', $email)->first();

    expect($result->status)->toBe('attributed')
        ->and($result->source)->toBe($expectedSource)
        ->and($contact->user_id)->toBe($status === UserStatus::Active ? $user->id : null);
})->with([
    'active' => [UserStatus::Active, 'verified_account_resolution'],
    'suspended' => [UserStatus::Suspended, 'guest_order_resolution'],
    'blocked' => [UserStatus::Blocked, 'guest_order_resolution'],
]);

it('falls back to guest evidence for deleted unverified and mismatched accounts', function (string $case) {
    $email = 'fallback-'.$case.'@example.test';
    $user = User::factory()->create(['email' => $case === 'mismatch' ? 'different@example.test' : $email]);
    if ($case === 'unverified') {
        $user->forceFill(['email_verified_at' => null])->save();
    }
    $order = $this->crmPendingOrder($email, $user);
    if ($case === 'deleted') {
        DB::connection('pgsql_migration')->table('users')->where('id', $user->id)->delete();
        $order->refresh();
    }
    p6a10Acquire($order);

    $result = p6a10Process($order->id);

    expect($result->source)->toBe('guest_order_resolution')
        ->and(DB::connection('pgsql_migration')->table('crm_contacts')->where('email', $email)->value('user_id'))
        ->toBeNull();
})->with(['deleted', 'unverified', 'mismatch']);

it('classifies legacy invalid email without rolling back finance or creating a contact', function (string $email) {
    $order = $this->crmPendingOrder($email);
    p6a10Acquire($order);

    expect($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and(DB::connection('pgsql_migration')->table('crm_order_attribution_outbox')->where('order_id', $order->id)->exists())
        ->toBeTrue();

    $result = p6a10Process($order->id);

    expect($result->status)->toBe('unattributable')
        ->and($result->reason)->toBe('invalid_email_contract')
        ->and($result->contact_public_id)->toBeNull()
        ->and(DB::connection('pgsql_migration')->table('crm_contacts')->count())->toBe(0)
        ->and(DB::connection('pgsql_migration')->table('crm_order_attributions')->count())->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid);
})->with([
    'too long' => str_repeat('a', 250).'@x.test',
    'bad syntax' => 'legacy-without-at-sign',
]);

it('keeps replay idempotent and turns a different existing attribution into a terminal conflict', function () {
    $first = $this->crmPendingOrder('replay@example.test');
    p6a10Acquire($first);
    $initial = p6a10Process($first->id);
    $replay = p6a10Process($first->id);

    expect($replay->status)->toBe('attributed')
        ->and($replay->contact_public_id)->toBe($initial->contact_public_id)
        ->and(DB::connection('pgsql_migration')->table('crm_order_attributions')->where('order_id', $first->id)->count())
        ->toBe(1)
        ->and(DB::connection('pgsql_migration')->table('crm_order_attribution_outbox')->where('order_id', $first->id)->value('attempt_count'))
        ->toBe(1);

    $conflictOrder = $this->crmPendingOrder('conflict-target@example.test');
    p6a10Acquire($conflictOrder);
    $evidenceOrder = $this->crmPendingOrder('other-contact@example.test');
    $other = p6a10Resolve('other-contact@example.test', 'guest_order', orderId: $evidenceOrder->id);
    DB::connection('pgsql_migration')->table('crm_order_attributions')->insert([
        'order_id' => $conflictOrder->id,
        'contact_id' => $other->contact_id,
        'source' => 'guest_order_resolution',
        'attributed_at' => now(),
    ]);

    $conflict = p6a10Process($conflictOrder->id);
    expect($conflict->status)->toBe('unattributable')
        ->and($conflict->reason)->toBe('attribution_conflict')
        ->and(DB::connection('pgsql_migration')->table('crm_order_attributions')->where('order_id', $conflictOrder->id)->value('contact_id'))
        ->toBe((int) $other->contact_id);
});

it('keeps attribution immutable and due listing bounded stable and terminal-aware', function () {
    $first = $this->crmPendingOrder('due-one@example.test');
    $second = $this->crmPendingOrder('due-two@example.test');
    p6a10Acquire($first);
    p6a10Acquire($second);

    $pending = DB::connection('pgsql_migration')->select(<<<'SQL'
        SELECT order_id, available_at, clock_timestamp() AS observed_at,
               available_at <= clock_timestamp() AS is_due
        FROM crm_order_attribution_outbox
        WHERE order_id IN (?, ?)
        ORDER BY available_at, order_id
        SQL, [$first->id, $second->id]);

    expect($pending)->toHaveCount(2);
    foreach ($pending as $row) {
        expect($row->is_due)->toBeTrue(
            "Outbox available_at {$row->available_at} was after PostgreSQL clock {$row->observed_at}.",
        );
    }

    $due = DB::select('SELECT * FROM public.list_due_crm_order_attributions(?::integer)', [1]);
    expect($due)->toHaveCount(1)
        ->and((int) $due[0]->order_id)->toBe($first->id);

    p6a10Process($first->id);
    $remaining = DB::select('SELECT * FROM public.list_due_crm_order_attributions(?::integer)', [100]);
    expect(collect($remaining)->pluck('order_id')->map(fn ($id): int => (int) $id)->all())->toBe([$second->id]);

    p6a10ExpectRefusal(
        fn () => DB::select('SELECT * FROM public.list_due_crm_order_attributions(101)'),
        'CRM order attribution batch size is invalid',
    );

    $owner = DB::connection('pgsql_migration');
    p6a10ExpectRefusal(
        fn () => $owner->table('crm_order_attributions')->where('order_id', $first->id)->update(['source' => 'existing_contact_snapshot']),
        'CRM order attributions are immutable',
    );
    p6a10ExpectRefusal(
        fn () => $owner->table('crm_order_attributions')->where('order_id', $first->id)->delete(),
        'CRM order attributions are immutable',
    );

    expect((new CrmOrderAttributionOutbox)->getGuarded())->toBe(['*'])
        ->and((new CrmOrderAttribution)->getGuarded())->toBe(['*']);
});
