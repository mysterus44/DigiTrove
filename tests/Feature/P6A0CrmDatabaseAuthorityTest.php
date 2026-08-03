<?php

declare(strict_types=1);

use App\Enums\CrmContactOrigin;
use App\Enums\CrmContactStatus;
use App\Enums\MarketingChannel;
use App\Enums\MarketingConsentAction;
use App\Enums\MarketingConsentSource;
use App\Enums\MarketingPurpose;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCrmDatabase;

uses(InteractsWithCrmDatabase::class);

function p6a0ResolveContact(string $email, string $origin, ?int $userId = null, ?int $orderId = null): object
{
    return DB::selectOne(
        'SELECT * FROM public.resolve_crm_contact(?::varchar, ?::varchar, ?::bigint, ?::bigint)',
        [$email, $origin, $userId, $orderId],
    );
}

function p6a0RecordConsent(
    string $contactPublicId,
    string $action,
    string $source,
    ?int $userId,
    ?int $orderId,
    string $key,
    string $policy = '2026-08-v1',
): object {
    return DB::selectOne(
        'SELECT * FROM public.record_crm_marketing_consent(?::uuid, ?::varchar, ?::varchar, ?::bigint, ?::bigint, ?::varchar, ?::varchar)',
        [$contactPublicId, $action, $source, $userId, $orderId, $policy, hash('sha256', $key)],
    );
}

function p6a0ExpectDatabaseRefusal(Closure $callback, string $message): void
{
    $exception = null;

    try {
        $callback();
    } catch (QueryException $queryException) {
        $exception = $queryException;
    }

    expect($exception)->not->toBeNull()
        ->and((string) $exception->getCode())->toBe('23514')
        ->and($exception->getMessage())->toContain($message);
}

it('uses exact PostgreSQL types, checks, indexes and closed enums', function () {
    $owner = DB::connection('pgsql_migration');
    $columns = collect($owner->select(<<<'SQL'
        SELECT table_name, column_name, data_type, udt_name, character_maximum_length, is_nullable
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name IN ('crm_contacts', 'crm_marketing_consent_events')
        ORDER BY table_name, ordinal_position
        SQL))->keyBy(fn (object $column): string => $column->table_name.'.'.$column->column_name);

    expect($columns['crm_contacts.id']->udt_name)->toBe('int8')
        ->and($columns['crm_contacts.public_id']->udt_name)->toBe('uuid')
        ->and($columns['crm_contacts.email']->udt_name)->toBe('citext')
        ->and($columns['crm_contacts.user_id']->udt_name)->toBe('int8')
        ->and($columns['crm_contacts.anonymized_at']->udt_name)->toBe('timestamptz')
        ->and($columns['crm_marketing_consent_events.idempotency_hash']->udt_name)->toBe('varchar')
        ->and((int) $columns['crm_marketing_consent_events.idempotency_hash']->character_maximum_length)->toBe(64)
        ->and($columns['crm_marketing_consent_events.recorded_at']->udt_name)->toBe('timestamptz')
        ->and($columns->keys()->contains(fn (string $key): bool => str_contains($key, 'visitor_id')))->toBeFalse()
        ->and($columns->keys()->contains(fn (string $key): bool => str_contains($key, 'lifetime_value')))->toBeFalse()
        ->and($columns->keys()->contains(fn (string $key): bool => str_contains($key, 'orders_count')))->toBeFalse();

    $indexes = collect($owner->select(<<<'SQL'
        SELECT indexname, indexdef FROM pg_indexes
        WHERE schemaname = 'public'
          AND tablename IN ('crm_contacts', 'crm_marketing_consent_events')
        SQL))->pluck('indexdef', 'indexname');

    expect($indexes)->toHaveKeys([
        'crm_contacts_public_id_unique',
        'crm_contacts_active_email_unique',
        'crm_marketing_consent_events_public_id_unique',
        'crm_marketing_consent_events_idempotency_hash_unique',
        'crm_marketing_consent_events_current_index',
    ])->and($indexes['crm_contacts_active_email_unique'])->toContain('WHERE (email IS NOT NULL)')
        ->and(CrmContactOrigin::cases())->toHaveCount(2)
        ->and(CrmContactStatus::cases())->toHaveCount(2)
        ->and(MarketingChannel::cases())->toBe([MarketingChannel::Email])
        ->and(MarketingPurpose::cases())->toBe([MarketingPurpose::Promotional])
        ->and(MarketingConsentAction::cases())->toHaveCount(2)
        ->and(MarketingConsentSource::cases())->toHaveCount(2);
});

it('deduplicates only the trimmed case-insensitive exact email for guest orders', function () {
    $order = $this->crmPendingOrder('buyer@example.test');
    $first = p6a0ResolveContact('  Buyer@Example.Test ', 'guest_order', orderId: $order->id);
    $same = p6a0ResolveContact('buyer@example.test', 'guest_order', orderId: $order->id);

    $aliasOneOrder = $this->crmPendingOrder('buyer+one@example.test');
    $aliasTwoOrder = $this->crmPendingOrder('buyer+two@example.test');
    $aliasOne = p6a0ResolveContact('buyer+one@example.test', 'guest_order', orderId: $aliasOneOrder->id);
    $aliasTwo = p6a0ResolveContact('buyer+two@example.test', 'guest_order', orderId: $aliasTwoOrder->id);

    expect($same->contact_id)->toBe($first->contact_id)
        ->and($same->public_id)->toBe($first->public_id)
        ->and($aliasOne->contact_id)->not->toBe($aliasTwo->contact_id)
        ->and(DB::connection('pgsql_migration')->table('crm_contacts')->count())->toBe(3);

    p6a0ExpectDatabaseRefusal(
        fn () => p6a0ResolveContact('other@example.test', 'guest_order', orderId: $order->id),
        'CRM guest order evidence is invalid',
    );
});

it('links only an exact verified account and never uses visitor identity', function () {
    $user = User::factory()->create(['email' => 'verified@example.test']);
    $contact = p6a0ResolveContact('VERIFIED@example.test', 'verified_account', userId: $user->id);
    $stored = DB::connection('pgsql_migration')->table('crm_contacts')->where('id', $contact->contact_id)->first();

    expect($stored->user_id)->toBe($user->id)
        ->and($stored->email)->toBe('verified@example.test')
        ->and($stored->origin)->toBe('verified_account');

    $unverified = User::factory()->unverified()->create(['email' => 'unverified@example.test']);
    p6a0ExpectDatabaseRefusal(
        fn () => p6a0ResolveContact('unverified@example.test', 'verified_account', userId: $unverified->id),
        'CRM verified account evidence is invalid',
    );
    p6a0ExpectDatabaseRefusal(
        fn () => p6a0ResolveContact('mismatch@example.test', 'verified_account', userId: $user->id),
        'CRM verified account evidence is invalid',
    );
});

it('refuses inactive account evidence before creating a CRM contact', function (UserStatus $status) {
    $email = "authority-{$status->value}@example.test";
    $user = User::factory()->create([
        'email' => $email,
        'status' => $status,
    ]);

    p6a0ExpectDatabaseRefusal(
        fn () => p6a0ResolveContact($email, 'verified_account', userId: $user->id),
        'CRM verified account evidence is invalid',
    );

    expect(DB::connection('pgsql_migration')->table('crm_contacts')->where('email', $email)->exists())
        ->toBeFalse();
})->with([
    'suspended' => UserStatus::Suspended,
    'blocked' => UserStatus::Blocked,
]);

it('refuses linking an existing guest contact to an inactive account', function (UserStatus $status) {
    $email = "trigger-{$status->value}@example.test";
    $order = $this->crmPendingOrder($email);
    $contact = p6a0ResolveContact($email, 'guest_order', orderId: $order->id);
    $user = User::factory()->create([
        'email' => $email,
        'status' => $status,
    ]);
    $owner = DB::connection('pgsql_migration');

    p6a0ExpectDatabaseRefusal(
        fn () => $owner->table('crm_contacts')->where('id', $contact->contact_id)->update([
            'user_id' => $user->id,
            'updated_at' => now(),
        ]),
        'CRM contact user link is invalid',
    );

    expect($owner->table('crm_contacts')->where('id', $contact->contact_id)->value('user_id'))
        ->toBeNull();
})->with([
    'suspended' => UserStatus::Suspended,
    'blocked' => UserStatus::Blocked,
]);

it('links an existing guest contact to an active verified account', function () {
    $email = 'active-link@example.test';
    $order = $this->crmPendingOrder($email);
    $guest = p6a0ResolveContact($email, 'guest_order', orderId: $order->id);
    $user = User::factory()->create(['email' => $email]);

    $linked = p6a0ResolveContact($email, 'verified_account', userId: $user->id);

    expect($linked->contact_id)->toBe($guest->contact_id)
        ->and(DB::connection('pgsql_migration')->table('crm_contacts')->where('id', $guest->contact_id)->value('user_id'))
        ->toBe($user->id);
});

it('keeps anonymization irreversible and permits a new clean contact for the old email', function () {
    $order = $this->crmPendingOrder('erase@example.test');
    $old = p6a0ResolveContact('erase@example.test', 'guest_order', orderId: $order->id);
    $owner = DB::connection('pgsql_migration');

    $owner->table('crm_contacts')->where('id', $old->contact_id)->update([
        'email' => null,
        'user_id' => null,
        'status' => 'anonymized',
        'anonymized_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::selectOne('SELECT public.has_current_marketing_consent(?::uuid) AS consent', [$old->public_id])->consent)->toBeFalse();
    p6a0ExpectDatabaseRefusal(
        fn () => $owner->table('crm_contacts')->where('id', $old->contact_id)->update([
            'email' => 'erase@example.test',
            'status' => 'active',
            'anonymized_at' => null,
        ]),
        'anonymized CRM contacts are immutable',
    );

    $new = p6a0ResolveContact('erase@example.test', 'guest_order', orderId: $order->id);
    expect($new->contact_id)->not->toBe($old->contact_id)
        ->and(DB::selectOne('SELECT public.has_current_marketing_consent(?::uuid) AS consent', [$new->public_id])->consent)->toBeFalse();
});

it('records a server-ordered append-only checkout grant idempotently', function () {
    $order = $this->crmPendingOrder('checkout@example.test');
    $contact = p6a0ResolveContact('checkout@example.test', 'guest_order', orderId: $order->id);

    expect(DB::selectOne('SELECT public.has_current_marketing_consent(?::uuid) AS consent', [$contact->public_id])->consent)->toBeFalse();

    $first = p6a0RecordConsent($contact->public_id, 'granted', 'checkout', null, $order->id, 'checkout-key');
    $replay = p6a0RecordConsent($contact->public_id, 'granted', 'checkout', null, $order->id, 'checkout-key');

    expect($first->inserted)->toBeTrue()
        ->and($replay->inserted)->toBeFalse()
        ->and($replay->event_public_id)->toBe($first->event_public_id)
        ->and(DB::selectOne('SELECT public.has_current_marketing_consent(?::uuid) AS consent', [$contact->public_id])->consent)->toBeTrue()
        ->and(DB::connection('pgsql_migration')->table('crm_marketing_consent_events')->count())->toBe(1);

    p6a0ExpectDatabaseRefusal(
        fn () => p6a0RecordConsent($contact->public_id, 'withdrawn', 'checkout', null, $order->id, 'checkout-withdraw'),
        'CRM checkout consent evidence is invalid',
    );
});

it('orders account grants and withdrawals and rejects invalid evidence', function () {
    $user = User::factory()->create(['email' => 'account@example.test']);
    $contact = p6a0ResolveContact('account@example.test', 'verified_account', userId: $user->id);

    p6a0RecordConsent($contact->public_id, 'granted', 'account_settings', $user->id, null, 'account-grant');
    expect(DB::selectOne('SELECT public.has_current_marketing_consent(?::uuid) AS consent', [$contact->public_id])->consent)->toBeTrue();

    p6a0RecordConsent($contact->public_id, 'withdrawn', 'account_settings', $user->id, null, 'account-withdraw');
    expect(DB::selectOne('SELECT public.has_current_marketing_consent(?::uuid) AS consent', [$contact->public_id])->consent)->toBeFalse();

    p6a0RecordConsent($contact->public_id, 'granted', 'account_settings', $user->id, null, 'account-regrant');
    expect(DB::selectOne('SELECT public.has_current_marketing_consent(?::uuid) AS consent', [$contact->public_id])->consent)->toBeTrue()
        ->and(DB::connection('pgsql_migration')->table('crm_marketing_consent_events')->pluck('action')->all())
        ->toBe(['granted', 'withdrawn', 'granted']);

    $user->update(['status' => 'suspended']);
    p6a0ExpectDatabaseRefusal(
        fn () => p6a0RecordConsent($contact->public_id, 'withdrawn', 'account_settings', $user->id, null, 'suspended-withdraw'),
        'CRM account consent evidence is invalid',
    );
});

it('refuses mutation and deletion of the consent ledger', function () {
    $order = $this->crmPendingOrder('ledger@example.test');
    $contact = p6a0ResolveContact('ledger@example.test', 'guest_order', orderId: $order->id);
    p6a0RecordConsent($contact->public_id, 'granted', 'checkout', null, $order->id, 'ledger-key');
    $owner = DB::connection('pgsql_migration');

    p6a0ExpectDatabaseRefusal(
        fn () => $owner->table('crm_marketing_consent_events')->update(['action' => 'withdrawn']),
        'CRM marketing consent events are append-only',
    );
    p6a0ExpectDatabaseRefusal(
        fn () => $owner->table('crm_marketing_consent_events')->delete(),
        'CRM marketing consent events are append-only',
    );
});

it('preserves CRM history when a linked user is physically removed', function () {
    $user = User::factory()->create(['email' => 'removed@example.test']);
    $contact = p6a0ResolveContact('removed@example.test', 'verified_account', userId: $user->id);
    p6a0RecordConsent($contact->public_id, 'granted', 'account_settings', $user->id, null, 'before-delete');
    $owner = DB::connection('pgsql_migration');

    $owner->table('users')->where('id', $user->id)->delete();

    expect($owner->table('crm_contacts')->where('id', $contact->contact_id)->value('user_id'))->toBeNull()
        ->and($owner->table('crm_marketing_consent_events')->value('user_id'))->toBeNull()
        ->and($owner->table('crm_marketing_consent_events')->count())->toBe(1);
});
