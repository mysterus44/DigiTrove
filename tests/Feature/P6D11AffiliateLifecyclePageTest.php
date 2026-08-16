<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Pages\AffiliateLifecycle;
use App\Filament\Pages\AffiliateProgramme;
use App\Models\User;
use App\Services\Affiliate\AffiliateLifecycleService;
use App\Services\Affiliate\AffiliateRefusalReason;
use App\Services\Affiliate\AffiliateReviewDecision;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\SegmentFixtures as Fx;
use Tests\Support\SourceScanner;

uses(InteractsWithCrmDatabase::class);

beforeEach(function (): void {
    config(['affiliate.governance_enabled' => true]);
});

function p6d11pAdmin(): User
{
    $user = User::factory()->create([
        'role' => UserRole::Admin,
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
    ]);

    test()->actingAs($user);

    return $user;
}

function p6d11pCustomer(): User
{
    return User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active]);
}

/** An approved affiliate holding one active code, built through the authorities. */
function p6d11pActive(): int
{
    $admin = auth()->user();
    $service = app(AffiliateLifecycleService::class);
    $affiliate = $service->submit((int) p6d11pCustomer()->id);
    $service->review($affiliate->affiliateId, AffiliateReviewDecision::Approve, $admin);

    return $affiliate->affiliateId;
}

/** Rotates by a route the page knows nothing about — a second administrator, in effect. */
function p6d11pExternalRotate(int $affiliateId, int $expectedCodeId, int $adminId): string
{
    return (string) Fx::owner()->selectOne(
        'SELECT issued_code FROM rotate_affiliate_code(?, ?, ?)',
        [$affiliateId, $expectedCodeId, $adminId],
    )->issued_code;
}

function p6d11pCodes(int $affiliateId): array
{
    $row = Fx::owner()->selectOne(
        'SELECT count(*) AS total, count(*) FILTER (WHERE is_active) AS active FROM affiliate_codes WHERE affiliate_id = ?',
        [$affiliateId],
    );

    return ['total' => (int) $row->total, 'active' => (int) $row->active];
}

// ── Authorization ───────────────────────────────────────────────────────────────

it('admits only an active, non-deleted administrator to the lifecycle page', function (
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

    expect(AffiliateLifecycle::canAccess())->toBe($allowed)
        ->and(AffiliateLifecycle::shouldRegisterNavigation())->toBe($allowed);
})->with([
    'active admin' => [UserRole::Admin, UserStatus::Active, false, true],
    'active staff' => [UserRole::Staff, UserStatus::Active, false, false],
    'active customer' => [UserRole::Customer, UserStatus::Active, false, false],
    'suspended admin' => [UserRole::Admin, UserStatus::Suspended, false, false],
    'blocked admin' => [UserRole::Admin, UserStatus::Blocked, false, false],
    'deleted admin' => [UserRole::Admin, UserStatus::Active, true, false],
]);

it('refuses a guest outright', function () {
    expect(AffiliateLifecycle::canAccess())->toBeFalse();
});

/**
 * Asserted against `mount()` DIRECTLY. Livewire's harness converts an abort into a
 * response, so wrapping `Livewire::test()` in a try/catch would report a pass for entirely
 * the wrong reason — a mistake this repository has already made once.
 */
it('closes the page itself, not merely the navigation entry', function () {
    p6d11pAdmin();
    Livewire::test(AffiliateLifecycle::class)->assertOk();

    test()->actingAs(User::factory()->create(['role' => UserRole::Staff, 'status' => UserStatus::Active]));

    $refused = false;

    try {
        (new AffiliateLifecycle)->mount();
    } catch (HttpException $exception) {
        $refused = $exception->getStatusCode() === 403;
    }

    expect($refused)->toBeTrue();
});

// ── One flag for the whole affiliate surface ────────────────────────────────────

it('opens and closes lifecycle and policy governance on the same switch', function () {
    p6d11pAdmin();

    expect(AffiliateLifecycle::canAccess())->toBeTrue()
        ->and(AffiliateProgramme::canAccess())->toBeTrue();

    config(['affiliate.governance_enabled' => false]);

    // One programme, one switch: no state exists where half of it runs.
    expect(AffiliateLifecycle::canAccess())->toBeFalse()
        ->and(AffiliateProgramme::canAccess())->toBeFalse();

    // A malformed flag closes the door rather than opening it.
    config(['affiliate.governance_enabled' => 'peut-etre']);
    expect(AffiliateLifecycle::canAccess())->toBeFalse();
});

it('refuses a mutation once the flag is turned off under an open page', function () {
    p6d11pAdmin();
    $affiliateId = p6d11pActive();

    $page = Livewire::test(AffiliateLifecycle::class)->call('open', $affiliateId);

    config(['affiliate.governance_enabled' => false]);

    $page->call('suspend')->assertForbidden();

    expect((string) Fx::owner()->selectOne('SELECT status FROM affiliates WHERE id = ?', [$affiliateId])->status)
        ->toBe('active');
});

// ── Gate revalidation between render and action ─────────────────────────────────

it('refuses an action from an administrator who stopped being eligible after render', function () {
    $admin = p6d11pAdmin();
    $affiliateId = p6d11pActive();

    // TWO pages opened while the account is still active — a 403 leaves no valid Livewire
    // snapshot, so each refused action needs its own component, and both must be mounted
    // BEFORE the account is blocked (mounting afterwards is refused too, which would prove
    // something weaker than what this test is about).
    $suspendPage = Livewire::test(AffiliateLifecycle::class)->call('open', $affiliateId);
    $rotatePage = Livewire::test(AffiliateLifecycle::class)->call('open', $affiliateId);

    // Now the account is blocked while both pages sit open. Access was granted at render;
    // the actions must be refused anyway.
    $admin->forceFill(['status' => UserStatus::Blocked])->save();
    test()->actingAs($admin->fresh());

    $suspendPage->call('suspend')->assertForbidden();
    $rotatePage->call('rotateCode')->assertForbidden();

    expect((string) Fx::owner()->selectOne('SELECT status FROM affiliates WHERE id = ?', [$affiliateId])->status)
        ->toBe('active')
        ->and(p6d11pCodes($affiliateId))->toBe(['total' => 1, 'active' => 1]);
});

// ── THE gate test: the compare-and-swap must survive the interface ──────────────

/**
 * The administrator opened the page while code A was active. A second administrator
 * rotated A to B. Pressing rotate from the stale page must be REFUSED.
 *
 * This test fails loudly if anyone ever replaces the captured component state with a fresh
 * `detail()->activeCodeId` at click time: the rotation would then succeed, mint a third
 * code, and both the notice and the code count below would be wrong.
 */
it('refuses a rotation launched from a page that no longer reflects the database', function () {
    $admin = p6d11pAdmin();
    $affiliateId = p6d11pActive();

    $page = Livewire::test(AffiliateLifecycle::class)->call('open', $affiliateId);

    $idA = $page->get('expectedActiveCodeId');
    $codeA = $page->get('shownActiveCode');

    expect($idA)->not->toBeNull()->and($codeA)->not->toBeNull();

    // A -> B, elsewhere. The component is never refreshed.
    $codeB = p6d11pExternalRotate($affiliateId, $idA, (int) $admin->id);

    $page->call('rotateCode');

    expect($page->get('expectedActiveCodeId'))->toBe($idA)
        ->and($page->get('notice'))->toBe(AffiliateRefusalReason::StaleCodeRotation->message())
        ->and($page->get('snapshotStale'))->toBeTrue()
        // Two codes, never three: the stale click minted nothing.
        ->and(p6d11pCodes($affiliateId))->toBe(['total' => 2, 'active' => 1]);

    // Nothing internal reached the operator.
    $notice = $page->get('notice');
    expect($notice)->not->toContain('AF001')
        ->and(strtolower($notice))->not->toContain('sqlstate')
        ->and(strtolower($notice))->not->toContain('rotate_affiliate_code')
        ->and(strtolower($notice))->not->toContain('constraint');

    // Only an explicit refresh re-arms the page, and it now describes B.
    $page->call('refreshSnapshot');

    expect($page->get('shownActiveCode'))->toBe($codeB)
        ->and($page->get('expectedActiveCodeId'))->not->toBe($idA)
        ->and($page->get('snapshotStale'))->toBeFalse();
});

it('rotates when the page still reflects the database', function () {
    p6d11pAdmin();
    $affiliateId = p6d11pActive();

    $page = Livewire::test(AffiliateLifecycle::class)->call('open', $affiliateId);
    $idA = $page->get('expectedActiveCodeId');
    $codeA = $page->get('shownActiveCode');

    $page->call('rotateCode');

    expect($page->get('snapshotStale'))->toBeFalse()
        ->and($page->get('shownActiveCode'))->not->toBe($codeA)
        ->and($page->get('expectedActiveCodeId'))->not->toBe($idA)
        ->and(p6d11pCodes($affiliateId))->toBe(['total' => 2, 'active' => 1]);

    // Still repeatable from the freshly captured state.
    $page->call('rotateCode');

    expect(p6d11pCodes($affiliateId))->toBe(['total' => 3, 'active' => 1]);
});

it('refuses a token belonging to another affiliate and leaves both untouched', function () {
    p6d11pAdmin();
    $one = p6d11pActive();
    $two = p6d11pActive();

    $pageTwo = Livewire::test(AffiliateLifecycle::class)->call('open', $two);
    $tokenOfTwo = $pageTwo->get('expectedActiveCodeId');

    $pageOne = Livewire::test(AffiliateLifecycle::class)->call('open', $one);
    $tokenOfOne = $pageOne->get('expectedActiveCodeId');

    // Component state is client-supplied; a harvested token must still be worthless. The
    // CAS scopes its update by affiliate, so possessing an id is not authorisation.
    $pageOne->set('expectedActiveCodeId', $tokenOfTwo)->call('rotateCode');

    expect($pageOne->get('notice'))->toBe(AffiliateRefusalReason::StaleCodeRotation->message())
        ->and(p6d11pCodes($one))->toBe(['total' => 1, 'active' => 1])
        ->and(p6d11pCodes($two))->toBe(['total' => 1, 'active' => 1])
        ->and((int) Fx::owner()->selectOne('SELECT id FROM affiliate_codes WHERE affiliate_id = ? AND is_active', [$one])->id)
        ->toBe($tokenOfOne);
});

// ── The lifecycle, driven from the page ─────────────────────────────────────────

it('carries an application from submission through to closure', function () {
    p6d11pAdmin();
    $customerId = (int) p6d11pCustomer()->id;

    $page = Livewire::test(AffiliateLifecycle::class)
        ->set('applicantUserId', $customerId)
        ->call('submitApplication');

    $affiliateId = $page->get('affiliateId');

    expect($affiliateId)->not->toBeNull()
        ->and($page->get('shownStatus'))->toBe('pending')
        ->and($page->get('expectedActiveCodeId'))->toBeNull()
        ->and($page->instance()->availableActions())->toBe(['approve', 'reject']);

    $page->call('reject');
    expect($page->get('shownStatus'))->toBe('rejected')
        ->and(p6d11pCodes($affiliateId)['active'])->toBe(0)
        ->and($page->instance()->availableActions())->toBe(['reapply']);

    $page->call('reapply');
    expect($page->get('shownStatus'))->toBe('pending')
        // The refusal marker survives the new application: the snapshot accumulates.
        ->and($page->get('shownRejectedAt'))->not->toBeNull()
        // …and the affiliate row is the same one, never a second.
        ->and($page->get('affiliateId'))->toBe($affiliateId);

    $page->call('approve');
    expect($page->get('shownStatus'))->toBe('active')
        ->and($page->get('shownActiveCode'))->not->toBeNull()
        ->and($page->get('expectedActiveCodeId'))->not->toBeNull()
        ->and($page->instance()->availableActions())->toBe(['rotate', 'suspend', 'close']);

    $codeBeforeSuspension = $page->get('shownActiveCode');

    $page->call('suspend');
    expect($page->get('shownStatus'))->toBe('suspended')
        ->and($page->get('shownActiveCode'))->toBeNull()
        ->and($page->get('expectedActiveCodeId'))->toBeNull()
        ->and(p6d11pCodes($affiliateId))->toBe(['total' => 1, 'active' => 0])
        ->and($page->instance()->availableActions())->toBe(['reactivate', 'close']);

    $page->call('reactivate');
    expect($page->get('shownStatus'))->toBe('active')
        // Reactivation mints a new code; the retired one stays reserved.
        ->and($page->get('shownActiveCode'))->not->toBe($codeBeforeSuspension)
        ->and($page->get('shownApprovedAt'))->not->toBeNull()
        ->and(p6d11pCodes($affiliateId))->toBe(['total' => 2, 'active' => 1]);

    $page->call('closeAffiliate');
    expect($page->get('shownStatus'))->toBe('closed')
        ->and($page->get('shownClosedAt'))->not->toBeNull()
        ->and(p6d11pCodes($affiliateId)['active'])->toBe(0)
        // `closed` is terminal: the page offers nothing further.
        ->and($page->instance()->availableActions())->toBe([]);

    // A replay from the closed state is refused and says so in plain language.
    $page->call('reactivate');
    expect($page->get('shownStatus'))->toBe('closed')
        ->and($page->get('notice'))->toBe(AffiliateRefusalReason::NotADraft->message());
});

it('closes a suspended affiliate as well as an active one', function () {
    p6d11pAdmin();
    $affiliateId = p6d11pActive();

    $page = Livewire::test(AffiliateLifecycle::class)->call('open', $affiliateId);
    $page->call('suspend')->call('closeAffiliate');

    expect($page->get('shownStatus'))->toBe('closed')
        ->and(p6d11pCodes($affiliateId)['active'])->toBe(0);
});

// ── What the page shows ─────────────────────────────────────────────────────────

it('separates the cumulative snapshot from the ledger and the code history', function () {
    p6d11pAdmin();
    $customerId = (int) p6d11pCustomer()->id;

    $page = Livewire::test(AffiliateLifecycle::class)
        ->set('applicantUserId', $customerId)
        ->call('submitApplication')
        ->call('reject')
        ->call('reapply')
        ->call('approve');

    $history = $page->instance()->lifecycleHistory();
    $codes = $page->instance()->codeHistory();

    // Four transitions in the ledger, while the snapshot has only one slot per marker.
    expect($history)->toHaveCount(4)
        ->and($page->get('shownStatus'))->toBe('active')
        ->and($page->get('shownRejectedAt'))->not->toBeNull()
        // Approval minted exactly one code, and rotation is NOT a lifecycle event.
        ->and($codes)->toHaveCount(1)
        ->and((bool) $codes[0]->is_active)->toBeTrue();

    $page->call('rotateCode');

    expect($page->instance()->lifecycleHistory())->toHaveCount(4)
        ->and($page->instance()->codeHistory())->toHaveCount(2);
});

it('shows the code value in the list without ever handing out a rotation token', function () {
    p6d11pAdmin();
    $affiliateId = p6d11pActive();

    $page = Livewire::test(AffiliateLifecycle::class);
    $summary = collect($page->instance()->affiliates())->firstWhere('id', $affiliateId);

    expect($summary->activeCode)->not->toBeNull()
        // A rotation cannot start from the list: the id simply is not there to be clicked.
        ->and(property_exists($summary, 'activeCodeId'))->toBeFalse();
});

// ── Boundaries ──────────────────────────────────────────────────────────────────

it('reaches the affiliate tables only through the service', function () {
    $source = file_get_contents(app_path('Filament/Pages/AffiliateLifecycle.php'));

    foreach (['DB::table(', 'DB::select(', 'DB::statement(', 'affiliate_codes', 'affiliate_lifecycle_events'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }

    // And no Eloquent model was smuggled in to satisfy Filament.
    expect(glob(app_path('Models/*Affiliate*.php')))->toBe([]);
});

it('exposes no finance, referral or customer surface', function () {
    // CODE only. The class docblock states in plain French that no commission, payout or
    // attribution appears here — a naive scan would flag that sentence and the file would
    // trip its own alarm for documenting its own restraint.
    $sources = strtolower(
        SourceScanner::phpCode(app_path('Filament/Pages/AffiliateLifecycle.php'))
        .SourceScanner::bladeMarkup(resource_path('views/filament/pages/affiliate-lifecycle.blade.php')),
    );

    // P6-D2/D3/D4 territory: none of it may appear before the gate that justifies it.
    foreach (['commission', 'payout', 'attribution', 'refund', 'accrual', '?ref=', 'cookie'] as $forbidden) {
        expect($sources)->not->toContain($forbidden);
    }
});
