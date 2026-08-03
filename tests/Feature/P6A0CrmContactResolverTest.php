<?php

declare(strict_types=1);

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Crm\CrmContactResolver;
use App\Services\Crm\CrmOperationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\InteractsWithCrmDatabase;

uses(InteractsWithCrmDatabase::class);

it('resolves guest and verified contacts through the runtime authority only', function () {
    $resolver = new CrmContactResolver;
    $guestOrder = $this->crmPendingOrder('service-guest@example.test');
    $guest = $resolver->resolveGuestOrder(' Service-Guest@Example.Test ', $guestOrder->id);
    $guestReplay = $resolver->resolveGuestOrder('service-guest@example.test', $guestOrder->id);

    $user = User::factory()->create(['email' => 'service-account@example.test']);
    $account = $resolver->resolveVerifiedAccount('SERVICE-ACCOUNT@example.test', $user->id);

    expect($guest->id)->toBe($guestReplay->id)
        ->and($guest->publicId)->toBe($guestReplay->publicId)
        ->and($account->id)->not->toBe($guest->id)
        ->and(DB::connection('pgsql_migration')->table('crm_contacts')->count())->toBe(2);
});

it('fails closed with a sanitized error for disabled, malformed or mismatched input', function () {
    $resolver = new CrmContactResolver;
    $order = $this->crmPendingOrder('expected@example.test');

    config(['crm.foundation_enabled' => false]);
    expect(fn () => $resolver->resolveGuestOrder('expected@example.test', $order->id))
        ->toThrow(CrmOperationException::class, 'The CRM operation could not be completed.');

    config(['crm.foundation_enabled' => true]);
    expect(fn () => $resolver->resolveGuestOrder('not-an-email', $order->id))
        ->toThrow(CrmOperationException::class, 'The CRM operation could not be completed.')
        ->and(fn () => $resolver->resolveGuestOrder('other@example.test', $order->id))
        ->toThrow(CrmOperationException::class, 'The CRM operation could not be completed.');
});

it('fails closed for an inactive verified account without creating a contact', function (UserStatus $status) {
    $email = "service-{$status->value}@example.test";
    $user = User::factory()->create([
        'email' => $email,
        'status' => $status,
    ]);

    expect(fn () => (new CrmContactResolver)->resolveVerifiedAccount($email, $user->id))
        ->toThrow(CrmOperationException::class, 'The CRM operation could not be completed.')
        ->and(DB::connection('pgsql_migration')->table('crm_contacts')->where('email', $email)->exists())
        ->toBeFalse();
})->with([
    'suspended' => UserStatus::Suspended,
    'blocked' => UserStatus::Blocked,
]);

it('refuses ambient transactions and a non-runtime database identity', function () {
    $resolver = new CrmContactResolver;
    $order = $this->crmPendingOrder('boundary@example.test');

    DB::beginTransaction();
    try {
        expect(fn () => $resolver->resolveGuestOrder('boundary@example.test', $order->id))
            ->toThrow(CrmOperationException::class);
    } finally {
        DB::rollBack();
    }

    $original = config('database.default');
    config(['database.default' => 'pgsql_migration']);
    DB::purge('pgsql_migration');
    try {
        expect(fn () => $resolver->resolveGuestOrder('boundary@example.test', $order->id))
            ->toThrow(CrmOperationException::class);
    } finally {
        config(['database.default' => $original]);
        DB::purge('pgsql_migration');
    }
});

it('does not log raw email or CRM evidence', function () {
    Log::spy();
    $order = $this->crmPendingOrder('private-crm@example.test');
    (new CrmContactResolver)->resolveGuestOrder('private-crm@example.test', $order->id);

    Log::shouldNotHaveReceived('debug');
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});
