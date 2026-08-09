<?php

declare(strict_types=1);

use App\Filament\Pages\CrmContacts;
use App\Support\CrmMoneyPresenter;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\CrmAdminFixtures as Admin;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

beforeEach(function (): void {
    config(['crm.foundation_enabled' => true]);
});

it('shows one row per currency and never a cross-currency total', function () {
    $contactId = Fx::contact();
    Fx::rollup($contactId, 'XOF', acquiredOrders: 2, gross: 50_000, refunded: 5_000);
    Fx::rollup($contactId, 'USD', acquiredOrders: 1, gross: 3_000, refunded: 0);

    test()->actingAs(Admin::admin());

    $component = Livewire::test(CrmContacts::class)->call('select', $contactId);
    $rollups = $component->instance()->commerceRollups();

    expect($rollups)->toHaveCount(2)
        ->and($rollups[0]['currency'])->toBe('USD')
        ->and($rollups[0]['net_revenue_minor'])->toBe(3_000)
        ->and($rollups[1]['currency'])->toBe('XOF')
        ->and($rollups[1]['net_revenue_minor'])->toBe(45_000);

    // 45 000 + 3 000 = 48 000 is meaningless without an FX rate and is never produced.
    $component->assertSee('XOF')
        ->assertSee('USD')
        ->assertSee('Aucun total multi-devises')
        ->assertDontSee('48 000')
        ->assertDontSee('48000');
});

it('renders amounts as exact minor units with an explicit currency', function () {
    $contactId = Fx::contact();
    Fx::rollup($contactId, 'XOF', acquiredOrders: 1, gross: 50_000, refunded: 5_000);

    test()->actingAs(Admin::admin());

    Livewire::test(CrmContacts::class)
        ->call('select', $contactId)
        // The currency is always beside the number; a bare figure is never shown.
        ->assertSee('XOF (unités mineures)');
});

/**
 * XOF has exponent 0 and USD exponent 2. Dividing by 100 would understate every XOF
 * amount by two orders of magnitude, so no such division may exist anywhere on the
 * CRM money path until an audited exponent table does.
 */
it('never divides by 100 and never uses a float on the money path', function () {
    $presenter = file_get_contents(app_path('Support/CrmMoneyPresenter.php'));
    $page = file_get_contents(app_path('Filament/Pages/CrmContacts.php'));
    $view = file_get_contents(resource_path('views/filament/pages/crm-contacts.blade.php'));
    $service = file_get_contents(app_path('Services/Crm/CrmAdminReadService.php'));

    foreach ([$presenter, $page, $view, $service] as $source) {
        expect($source)->not->toContain('/ 100')
            ->and($source)->not->toContain('/100')
            ->and($source)->not->toContain('floatval')
            ->and($source)->not->toContain('round(');
    }

    // The presenter formats; it never sums, converts or rounds.
    expect($presenter)->not->toContain('function total')
        ->and($presenter)->not->toContain('array_sum');
});

it('formats exact integers including negatives and refuses a malformed currency', function () {
    expect(CrmMoneyPresenter::format(0, 'XOF'))->toContain('0 XOF')
        ->and(CrmMoneyPresenter::format(45_000, 'XOF'))->toContain('45 000 XOF')
        ->and(CrmMoneyPresenter::format(-250, 'USD'))->toContain('-250 USD')
        // A trailing newline must not slip through a `$`-anchored pattern (the P3-D1.1
        // A1 defect). Money::assertValidCurrency is the single source of that rule.
        ->and(fn () => CrmMoneyPresenter::format(100, "XOF\n"))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CrmMoneyPresenter::format(100, 'xof'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CrmMoneyPresenter::format(100, 'XOFF'))->toThrow(InvalidArgumentException::class);
});

it('shows nothing rather than zeroes for a contact that never acquired', function () {
    $contactId = Fx::contact();

    test()->actingAs(Admin::admin());

    $component = Livewire::test(CrmContacts::class)->call('select', $contactId);

    // An absent rollup is NOT "acquired 0": the row simply does not exist.
    expect($component->instance()->commerceRollups())->toBe([]);
    $component->assertSee('Aucune acquisition');
});
