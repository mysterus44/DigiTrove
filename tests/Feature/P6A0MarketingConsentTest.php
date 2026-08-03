<?php

declare(strict_types=1);

use App\Enums\MarketingConsentAction;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\Crm\CrmContactResolver;
use App\Services\Crm\CrmOperationException;
use App\Services\Crm\MarketingConsentRecorder;
use App\Services\Crm\MarketingConsentStatusQuery;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCrmDatabase;

uses(InteractsWithCrmDatabase::class);

function p6a0ServiceKey(string $seed): string
{
    return str_pad($seed, 24, 'x');
}

it('records checkout grant and account grant withdrawal regrant through explicit sources', function () {
    $resolver = new CrmContactResolver;
    $recorder = new MarketingConsentRecorder;
    $status = new MarketingConsentStatusQuery;

    $order = $this->crmPendingOrder('consent-checkout@example.test');
    $guest = $resolver->resolveGuestOrder('consent-checkout@example.test', $order->id);
    expect($status->hasCurrentConsent($guest->publicId))->toBeFalse();

    $checkout = $recorder->grantAtCheckout($guest->publicId, $order->id, p6a0ServiceKey('checkout'));
    $checkoutReplay = $recorder->grantAtCheckout($guest->publicId, $order->id, p6a0ServiceKey('checkout'));
    expect($checkout->inserted)->toBeTrue()
        ->and($checkoutReplay->inserted)->toBeFalse()
        ->and($checkoutReplay->eventPublicId)->toBe($checkout->eventPublicId)
        ->and($status->hasCurrentConsent($guest->publicId))->toBeTrue();

    $user = User::factory()->create(['email' => 'consent-account@example.test']);
    $account = $resolver->resolveVerifiedAccount($user->email, $user->id);
    $recorder->recordAccountSettings($account->publicId, MarketingConsentAction::Granted, $user->id, p6a0ServiceKey('grant'));
    $recorder->recordAccountSettings($account->publicId, MarketingConsentAction::Withdrawn, $user->id, p6a0ServiceKey('withdraw'));
    expect($status->hasCurrentConsent($account->publicId))->toBeFalse();
    $recorder->recordAccountSettings($account->publicId, MarketingConsentAction::Granted, $user->id, p6a0ServiceKey('regrant'));
    expect($status->hasCurrentConsent($account->publicId))->toBeTrue();
});

it('uses only the configured policy version and fails closed when it is absent', function () {
    $order = $this->crmPendingOrder('policy@example.test');
    $contact = (new CrmContactResolver)->resolveGuestOrder('policy@example.test', $order->id);
    $recorder = new MarketingConsentRecorder;

    config(['crm.marketing_policy_version' => '']);
    expect(fn () => $recorder->grantAtCheckout($contact->publicId, $order->id, p6a0ServiceKey('empty-policy')))
        ->toThrow(CrmOperationException::class);

    config(['crm.marketing_policy_version' => '2026-08-v2']);
    $recorder->grantAtCheckout($contact->publicId, $order->id, p6a0ServiceKey('policy-v2'));
    expect(DB::connection('pgsql_migration')->table('crm_marketing_consent_events')->value('policy_version'))
        ->toBe('2026-08-v2');
});

it('ignores analytics consent and legacy profile booleans completely', function () {
    $user = User::factory()->create(['email' => 'legacy@example.test']);
    CustomerProfile::factory()->create([
        'user_id' => $user->id,
        'marketing_consent' => true,
        'consent_updated_at' => now(),
    ]);
    $contact = (new CrmContactResolver)->resolveVerifiedAccount($user->email, $user->id);

    expect((new MarketingConsentStatusQuery)->hasCurrentConsent($contact->publicId))->toBeFalse()
        ->and(DB::connection('pgsql_migration')->table('crm_marketing_consent_events')->count())->toBe(0);

    config(['analytics.ingestion.enabled' => true]);
    expect((new MarketingConsentStatusQuery)->hasCurrentConsent($contact->publicId))->toBeFalse();
});

it('stores only the idempotency digest and accepts no browser timestamp', function () {
    $order = $this->crmPendingOrder('digest@example.test');
    $contact = (new CrmContactResolver)->resolveGuestOrder('digest@example.test', $order->id);
    $rawKey = p6a0ServiceKey('raw-private-key');
    (new MarketingConsentRecorder)->grantAtCheckout($contact->publicId, $order->id, $rawKey);
    $event = DB::connection('pgsql_migration')->table('crm_marketing_consent_events')->first();

    expect($event->idempotency_hash)->toBe(hash('sha256', $rawKey))
        ->and($event->idempotency_hash)->not->toContain($rawKey)
        ->and($event->recorded_at)->not->toBeNull();
});
