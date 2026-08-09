<?php

declare(strict_types=1);

use App\Filament\Pages\CrmContacts;
use App\Services\Crm\CrmAdminReadService;
use App\Services\Crm\CrmOperationException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\CrmAdminFixtures as Admin;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

beforeEach(function (): void {
    config(['crm.foundation_enabled' => true]);
});

it('renders the consent timeline of one contact only', function () {
    $contactId = Fx::contact();
    $other = Fx::contact();
    Fx::grantMarketingConsent($contactId);
    Fx::grantMarketingConsent($contactId);
    Fx::grantMarketingConsent($other);

    test()->actingAs(Admin::admin());

    $component = Livewire::test(CrmContacts::class)->call('select', $contactId);

    expect($component->instance()->consentEvents())->toHaveCount(2);

    $component->assertSee('Consentement marketing')
        ->assertSee('granted')
        ->assertSee('promotional');
});

it('bounds the consent timeline by the authority page size', function () {
    $contactId = Fx::contact();

    for ($i = 0; $i < 3; $i++) {
        Fx::grantMarketingConsent($contactId);
    }

    test()->actingAs(Admin::admin());

    $service = app(CrmAdminReadService::class);
    $firstTwo = $service->consentEvents($contactId, null, 2);
    expect($firstTwo)->toHaveCount(2);

    // Keyset continuation, not OFFSET.
    $rest = $service->consentEvents($contactId, $firstTwo[1]['event_id'], 2);
    expect($rest)->toHaveCount(1);

    // The bound belongs to PostgreSQL and is not silently clamped in PHP.
    expect(fn () => $service->consentEvents($contactId, null, 101))->toThrow(CrmOperationException::class);
});

/**
 * The three notions are distinct and must stay distinct on screen:
 *   consent  — the contact agreed to be contacted (a ledger fact);
 *   membership — the contact matches a segment definition (a computed fact);
 *   send eligibility — the decision to actually send, which P6-B0 does not make at all.
 */
it('keeps consent, membership and send eligibility separate', function () {
    $contactId = Fx::contact();
    Fx::rollup($contactId, 'XOF', gross: 50_000);

    // A member WITHOUT any consent event: membership must not imply consent.
    $definition = Fx::definition([
        'field' => 'commerce.net_revenue_minor', 'operator' => 'gte', 'currency' => 'XOF', 'value' => 1_000,
    ]);
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion($definition);
    Fx::buildGeneration($segmentId);

    test()->actingAs(Admin::admin());

    $component = Livewire::test(CrmContacts::class)->call('select', $contactId);

    expect($component->instance()->segmentMemberships())->toHaveCount(1)
        ->and($component->instance()->consentEvents())->toBe([]);

    // The screen states the distinction explicitly and offers no send affordance.
    // `escape: false` because the blade text is literal markup, so its apostrophes are
    // NOT HTML-escaped in the output.
    $component->assertSee("n'est pas l'appartenance", escape: false)
        ->assertSee("n'est pas une autorisation d'envoi", escape: false);

    $page = file_get_contents(app_path('Filament/Pages/CrmContacts.php'));
    foreach (['Mail::', 'Notification::', 'send(', 'Campaign'] as $token) {
        expect($page)->not->toContain($token);
    }
});

it('never derives a consent decision in the UI', function () {
    // The page reads the append-only ledger and renders it. It computes no "is currently
    // opted in" verdict: that belongs to MarketingConsentStatusQuery, not to a screen.
    $page = file_get_contents(app_path('Filament/Pages/CrmContacts.php'));

    expect($page)->not->toContain('MarketingConsentRecorder')
        ->and($page)->not->toContain('withdraw')
        ->and($page)->not->toContain('grant');
});
