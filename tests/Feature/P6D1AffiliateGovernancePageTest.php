<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Pages\AffiliateProgramme;
use App\Models\User;
use App\Services\Affiliate\AffiliatePolicy;
use App\Services\Affiliate\AffiliatePolicyService;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\SegmentFixtures as Fx;
use Tests\Support\SourceScanner;

uses(InteractsWithCrmDatabase::class);

beforeEach(function (): void {
    config(['affiliate.governance_enabled' => true]);
});

function p6d1Admin(): User
{
    $user = User::factory()->create([
        'role' => UserRole::Admin,
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
    ]);

    test()->actingAs($user);

    return $user;
}

// ── Authorization ───────────────────────────────────────────────────────────────

it('admits only an active, non-deleted administrator to the governance page', function (
    UserRole $role,
    UserStatus $status,
    bool $deleted,
    bool $allowed,
) {
    $user = User::factory()->create(compact('role', 'status'));

    if ($deleted) {
        $user->delete();
    }

    test()->actingAs($user);

    expect(AffiliateProgramme::canAccess())->toBe($allowed)
        // Navigation follows access, never the reverse.
        ->and(AffiliateProgramme::shouldRegisterNavigation())->toBe($allowed);
})->with([
    'active admin' => [UserRole::Admin, UserStatus::Active, false, true],
    'active staff' => [UserRole::Staff, UserStatus::Active, false, false],
    'active customer' => [UserRole::Customer, UserStatus::Active, false, false],
    'suspended admin' => [UserRole::Admin, UserStatus::Suspended, false, false],
    'blocked admin' => [UserRole::Admin, UserStatus::Blocked, false, false],
    'deleted admin' => [UserRole::Admin, UserStatus::Active, true, false],
]);

it('refuses a guest', function () {
    expect(AffiliateProgramme::canAccess())->toBeFalse();
});

/**
 * The flag is fail-closed in both directions: switched off closes the page, and a
 * malformed value closes it too rather than being read as "on".
 */
it('closes the page when governance is disabled or the flag is malformed', function () {
    p6d1Admin();

    config(['affiliate.governance_enabled' => false]);
    expect(AffiliateProgramme::canAccess())->toBeFalse();

    config(['affiliate.governance_enabled' => 'peut-etre']);
    expect(AffiliateProgramme::canAccess())->toBeFalse();

    config(['affiliate.governance_enabled' => true]);
    expect(AffiliateProgramme::canAccess())->toBeTrue();
});

/**
 * The P6-B1 defect, asserted here so it cannot come back: a page whose `canAccess()`
 * reaches `Filament\Pages\Page::canAccess()` returns true for everyone. The trait must be
 * the effective implementation.
 */
it('resolves canAccess through the affiliate trait, not through Filament', function () {
    $method = new ReflectionMethod(AffiliateProgramme::class, 'canAccess');

    expect($method->getDeclaringClass()->getName())->toBe(AffiliateProgramme::class);

    // And with no user at all it says no — the Filament default would say yes.
    expect(AffiliateProgramme::canAccess())->toBeFalse();
});

// ── Governance actions go through the service ───────────────────────────────────

it('creates, edits and publishes a policy entirely through the authorities', function () {
    p6d1Admin();

    $page = Livewire::test(AffiliateProgramme::class);

    expect($page->instance()->current())->toBeNull()
        ->and($page->instance()->nextVersion())->toBe(1);

    $page->set('attributionWindowDays', 30)
        ->set('commissionBps', 1500)
        ->set('payableDelayDays', 14)
        ->set('payoutThresholdMinor', 10_000)
        ->set('payoutCurrency', 'XOF')
        ->call('createDraft');

    $history = $page->instance()->history();

    expect($history)->toHaveCount(1)
        ->and($history[0]->status)->toBe('draft')
        ->and($history[0]->version)->toBe(1);

    $page->call('updateDraft', $history[0]->id);
    $page->call('publish', $history[0]->id);

    $current = $page->instance()->current();

    expect($current)->not->toBeNull()
        ->and($current->version)->toBe(1)
        ->and($current->status)->toBe('active')
        ->and($current->defaultCommissionBps)->toBe(1500);

    // The persisted row was written by the authority, under the runtime identity.
    expect((int) Fx::owner()->selectOne("SELECT count(*) AS c FROM affiliate_program_policies WHERE status = 'active'")->c)->toBe(1);
});

it('never lets a non-admin reach a mutating action', function () {
    // The component is mounted by an administrator, then the acting user changes — the
    // shape a session hijack or a stale tab would take. Every action re-checks the Gate.
    p6d1Admin();
    Livewire::test(AffiliateProgramme::class)->assertOk();

    $staff = User::factory()->create(['role' => UserRole::Staff, 'status' => UserStatus::Active]);
    test()->actingAs($staff);

    // `mount()` is asserted DIRECTLY: Livewire's test harness converts the abort into a
    // response instead of letting it propagate, so wrapping `Livewire::test()` in a
    // try/catch would report a pass for the wrong reason.
    $refused = false;

    try {
        (new AffiliateProgramme)->mount();
    } catch (HttpException $exception) {
        $refused = $exception->getStatusCode() === 403;
    }

    expect($refused)->toBeTrue('a non-admin was able to mount the governance page')
        // ...and the Gate itself refuses, which is what every mutating action re-checks.
        ->and(AffiliateProgramme::canAccess())->toBeFalse();
});

it('refuses a mutating action once governance is switched off mid-session', function () {
    p6d1Admin();
    $page = Livewire::test(AffiliateProgramme::class);

    config(['affiliate.governance_enabled' => false]);

    $page->call('createDraft')->assertForbidden();
});

// ── The surface stays governance-only ───────────────────────────────────────────

/**
 * D-058 delivers immediate publication only. The page must not accept an effective
 * instant from the operator, and must not present scheduling as possible.
 */
it('exposes no way to schedule a publication', function () {
    p6d1Admin();

    // DECLARED properties only: Livewire's own inherited public properties are not this
    // gate's surface, and including them made an earlier version of this test fire on a
    // name that merely contained "date".
    $properties = array_values(array_map(
        static fn (ReflectionProperty $p): string => $p->getName(),
        array_filter(
            (new ReflectionClass(AffiliateProgramme::class))->getProperties(ReflectionProperty::IS_PUBLIC),
            static fn (ReflectionProperty $p): bool => $p->getDeclaringClass()->getName() === AffiliateProgramme::class,
        ),
    ));

    sort($properties);

    // The exact form surface. A new bindable property has to be declared here first.
    expect($properties)->toBe([
        'attributionWindowDays',
        'beforeVersion',
        'commissionBps',
        'cursorStack',
        'notice',
        'payableDelayDays',
        'payoutCurrency',
        'payoutThresholdMinor',
        'unavailable',
    ]);

    // No action accepts a timestamp either.
    foreach (['createDraft', 'updateDraft', 'publish'] as $action) {
        foreach ((new ReflectionMethod(AffiliateProgramme::class, $action))->getParameters() as $parameter) {
            expect((string) $parameter->getType())->toBe('int', "{$action} accepts a non-integer argument");
        }
    }
});

/**
 * A word hunt is the wrong instrument here: this page legitimately says "commission",
 * "attribution window" and "payout threshold" — they are policy FIELDS — and its Blade
 * comment names the things it refuses to do. What must be bounded is the set of ACTIONS
 * it offers, so that is what is asserted, as an exact inventory.
 */
it('offers exactly the governance actions and no lifecycle, code, touch or payout affordance', function () {
    p6d1Admin();

    Livewire::test(AffiliateProgramme::class)->assertOk();

    $actions = array_values(array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        array_filter(
            (new ReflectionClass(AffiliateProgramme::class))->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === AffiliateProgramme::class,
        ),
    ));

    sort($actions);

    // `canAccess` and `shouldRegisterNavigation` appear as DECLARED here because PHP
    // flattens trait methods into the using class — the very fact that let a
    // `parent::canAccess()` bypass the Gate in P6-B1.
    expect($actions)->toBe([
        'canAccess', 'createDraft', 'current', 'history', 'mount', 'nextPage',
        'nextVersion', 'previousPage', 'publish', 'shouldRegisterNavigation', 'updateDraft',
    ]);

    // The view wires only those handlers — read as CODE, so the Blade comment that
    // documents what this gate refuses to do does not trip its own alarm.
    $markup = SourceScanner::bladeMarkup(
        dirname(__DIR__, 2).'/resources/views/filament/pages/affiliate-programme.blade.php',
    );

    preg_match_all('/wire:click="([a-zA-Z]+)/', $markup, $matches);
    $handlers = array_values(array_unique($matches[1]));
    sort($handlers);

    expect($handlers)->toBe(['nextPage', 'previousPage', 'publish', 'updateDraft']);

    // And no affordance for a later gate leaked into the markup.
    //
    // `clic` is deliberately NOT on this list: it is a substring of `wire:click`, and a
    // naive scan would fire on the page's own pagination buttons — the same false
    // positive class as `ray(` inside `in_array(`. The handler inventory above is the
    // real guard against a click-capture affordance.
    foreach (['candidature', 'affilié', 'ledger', 'accrual', 'payout_items', 'commission_entries'] as $term) {
        expect(stripos($markup, $term))->toBeFalse("the governance view exposes: {$term}");
    }
});

it('bounds the history page and never exposes an unbounded listing', function () {
    p6d1Admin();

    $service = app(AffiliatePolicyService::class);

    foreach (range(1, 3) as $version) {
        $service->publish($service->createDraft($version, 30, 1500, 14, 10_000, 'XOF'));
    }

    expect(AffiliateProgramme::HISTORY_PAGE_SIZE)->toBeLessThanOrEqual(100);

    $page = Livewire::test(AffiliateProgramme::class);
    $history = $page->instance()->history();

    expect(count($history))->toBeLessThanOrEqual(AffiliateProgramme::HISTORY_PAGE_SIZE)
        // Deterministic newest-first ordering.
        ->and($history[0]->version)->toBe(3);
});

// ── Money and rate presentation ─────────────────────────────────────────────────

it('shows the rate as a percentage without ever dividing an amount', function () {
    p6d1Admin();

    $service = app(AffiliatePolicyService::class);
    $service->publish($service->createDraft(1, 30, 1500, 14, 10_000, 'XOF'));

    $policy = app(AffiliatePolicyService::class)->current();

    expect($policy->commissionPercentageLabel())->toBe('15 %')
        // Minor units with an explicit currency — never divided by 100 (XOF exponent 0).
        ->and($policy->payoutThresholdLabel())->toBe('10000 XOF')
        ->and($policy->payoutThresholdMinor)->toBe(10_000);
});

it('derives fractional percentages with integer arithmetic only', function () {
    $make = static fn (int $bps): string => (new AffiliatePolicy(
        id: 1, version: 1, status: 'active', attributionWindowDays: 30,
        defaultCommissionBps: $bps, payableDelayDays: 14,
        payoutThresholdMinor: 10_000, payoutCurrency: 'XOF',
    ))->commissionPercentageLabel();

    expect($make(1500))->toBe('15 %')
        ->and($make(1550))->toBe('15,5 %')
        ->and($make(1505))->toBe('15,05 %')
        ->and($make(0))->toBe('0 %')
        ->and($make(5000))->toBe('50 %');
});
