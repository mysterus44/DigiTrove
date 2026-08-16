<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Affiliate\AffiliateLifecycleService;
use App\Services\Affiliate\AffiliateOperationException;
use App\Services\Affiliate\AffiliateRefusalReason;
use App\Services\Affiliate\AffiliateReviewDecision;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

/**
 * P6-D1.1 service layer.
 *
 * These tests do NOT re-prove the state machine — PostgreSQL already does that, at length.
 * They prove the three things only the PHP layer can get wrong: that arguments reach the
 * authority unaltered, that refusals come back typed instead of raw, and above all that
 * the compare-and-swap token survives the trip.
 */
beforeEach(function () {
    config(['affiliate.governance_enabled' => true]);
});

function p6d11sUser(UserRole $role = UserRole::Customer): User
{
    return User::factory()->create(['role' => $role, 'status' => UserStatus::Active]);
}

function p6d11sService(): AffiliateLifecycleService
{
    return app(AffiliateLifecycleService::class);
}

/** An approved affiliate with one active code, built through the authorities themselves. */
function p6d11sActive(): array
{
    $admin = p6d11sUser(UserRole::Admin);
    $service = p6d11sService();
    $affiliate = $service->submit((int) p6d11sUser()->id);
    $service->review($affiliate->affiliateId, AffiliateReviewDecision::Approve, $admin);

    return ['id' => $affiliate->affiliateId, 'admin' => $admin];
}

/** Rotates by a route the service under test knows nothing about. */
function p6d11sExternalRotate(int $affiliateId, int $expectedCodeId, int $adminId): string
{
    return (string) Fx::owner()->selectOne(
        'SELECT issued_code FROM rotate_affiliate_code(?, ?, ?)',
        [$affiliateId, $expectedCodeId, $adminId],
    )->issued_code;
}

function p6d11sCodeCount(int $affiliateId): array
{
    $row = Fx::owner()->selectOne(
        'SELECT count(*) AS total, count(*) FILTER (WHERE is_active) AS active FROM affiliate_codes WHERE affiliate_id = ?',
        [$affiliateId],
    );

    return ['total' => (int) $row->total, 'active' => (int) $row->active];
}

// ── The test this whole gate exists for ─────────────────────────────────────────

/**
 * The administrator read code A. While the page sat open, A was rotated to B by someone
 * else. Pressing rotate must now be refused — NOT quietly turned into a second rotation
 * that mints C. If the service re-read the active code before calling, this test would
 * pass a fresh id, PostgreSQL would happily rotate, and the CAS would be dead code.
 */
it('refuses a rotation aimed at the code the administrator saw once it is no longer active', function () {
    $ctx = p6d11sActive();
    $service = p6d11sService();

    $seen = $service->detail($ctx['id']);
    $idA = $seen->activeCodeId;
    $codeA = $seen->activeCode;

    expect($idA)->not->toBeNull();

    // A -> B happens elsewhere. The service is never told.
    $codeB = p6d11sExternalRotate($ctx['id'], $idA, (int) $ctx['admin']->id);

    // The stale snapshot is used verbatim, exactly as a Filament action would replay it.
    expect(fn () => $service->rotateCode($ctx['id'], $idA, $ctx['admin']))
        ->toThrow(AffiliateOperationException::class);

    try {
        $service->rotateCode($ctx['id'], $idA, $ctx['admin']);
    } catch (AffiliateOperationException $exception) {
        expect($exception->reason)->toBe(AffiliateRefusalReason::StaleCodeRotation);
    }

    $after = $service->detail($ctx['id']);

    // Two codes, never three: the stale request minted nothing at all.
    expect(p6d11sCodeCount($ctx['id']))->toBe(['total' => 2, 'active' => 1])
        ->and($after->activeCode)->toBe($codeB)
        ->and($after->activeCode)->not->toBe($codeA)
        ->and($after->activeCodeId)->not->toBe($idA);
});

it('rotates when the named code is still the one on screen, and stays repeatable', function () {
    $ctx = p6d11sActive();
    $service = p6d11sService();

    $first = $service->detail($ctx['id']);
    $codeB = $service->rotateCode($ctx['id'], $first->activeCodeId, $ctx['admin']);

    $second = $service->detail($ctx['id']);

    expect($second->activeCode)->toBe($codeB)
        ->and($second->activeCodeId)->not->toBe($first->activeCodeId)
        ->and(p6d11sCodeCount($ctx['id']))->toBe(['total' => 2, 'active' => 1]);

    // A fresh, legitimate read rotates again. Only staleness is refused, not rotation.
    $codeC = $service->rotateCode($ctx['id'], $second->activeCodeId, $ctx['admin']);
    $third = $service->detail($ctx['id']);

    expect($third->activeCode)->toBe($codeC)
        ->and([$codeB, $codeC])->not->toContain($first->activeCode)
        ->and(p6d11sCodeCount($ctx['id']))->toBe(['total' => 3, 'active' => 1]);
});

it('refuses a code id belonging to another affiliate and touches neither of them', function () {
    $one = p6d11sActive();
    $two = p6d11sActive();
    $service = p6d11sService();

    $detailOne = $service->detail($one['id']);
    $detailTwo = $service->detail($two['id']);

    // The id is real — it simply is not this affiliate's. The CAS scopes its UPDATE by
    // affiliate, so a harvested id cannot reach across.
    expect(fn () => $service->rotateCode($one['id'], $detailTwo->activeCodeId, $one['admin']))
        ->toThrow(AffiliateOperationException::class);

    expect($service->detail($one['id'])->activeCodeId)->toBe($detailOne->activeCodeId)
        ->and($service->detail($two['id'])->activeCodeId)->toBe($detailTwo->activeCodeId)
        ->and(p6d11sCodeCount($one['id']))->toBe(['total' => 1, 'active' => 1])
        ->and(p6d11sCodeCount($two['id']))->toBe(['total' => 1, 'active' => 1]);
});

it('refuses a code that has already been retired', function () {
    $ctx = p6d11sActive();
    $service = p6d11sService();

    $idA = $service->detail($ctx['id'])->activeCodeId;
    $service->rotateCode($ctx['id'], $idA, $ctx['admin']);

    try {
        $service->rotateCode($ctx['id'], $idA, $ctx['admin']);
        expect(false)->toBeTrue('a retired code was accepted as a rotation token');
    } catch (AffiliateOperationException $exception) {
        expect($exception->reason)->toBe(AffiliateRefusalReason::StaleCodeRotation);
    }

    expect(p6d11sCodeCount($ctx['id']))->toBe(['total' => 2, 'active' => 1]);
});

// ── Error contract ──────────────────────────────────────────────────────────────

it('keeps the stale-rotation refusal distinct from the concurrent-publication one', function () {
    $stale = AffiliateRefusalReason::StaleCodeRotation->message();
    $publication = AffiliateRefusalReason::ConcurrentPublication->message();

    expect($stale)->not->toBe($publication)
        // The administrator is rotating a code; a message about policies would be a lie.
        ->and(strtolower($stale))->not->toContain('polic')
        ->and(strtolower($stale))->not->toContain('publish')
        // And nothing internal leaks outward.
        ->and($stale)->not->toContain('AF001')
        ->and($stale)->not->toContain('40001')
        ->and(strtolower($stale))->not->toContain('sqlstate')
        ->and(strtolower($stale))->not->toContain('rotate_affiliate_code')
        ->and(strtolower($stale))->not->toContain('constraint')
        // P6-D1's wording is untouched.
        ->and($publication)->toBe('Another policy was published at the same time. Reload and try again.');
});

// ── State machine, seen from PHP ────────────────────────────────────────────────

it('carries every transition through to the authority and reports the new state', function () {
    $admin = p6d11sUser(UserRole::Admin);
    $service = p6d11sService();
    $userId = (int) p6d11sUser()->id;

    $submitted = $service->submit($userId);
    expect($submitted->status)->toBe('pending')->and($submitted->issuedCode)->toBeNull();

    $rejected = $service->review($submitted->affiliateId, AffiliateReviewDecision::Reject, $admin, 'incomplete');
    expect($rejected->status)->toBe('rejected')->and($rejected->issuedCode)->toBeNull();

    // Re-application is a transition on the same row, not a second affiliate.
    expect($service->submit($userId)->affiliateId)->toBe($submitted->affiliateId);

    $approved = $service->review($submitted->affiliateId, AffiliateReviewDecision::Approve, $admin);
    expect($approved->status)->toBe('active')->and($approved->issuedCode)->not->toBeNull();

    expect($service->suspend($submitted->affiliateId, $admin, 'fraud_suspicion')->status)->toBe('suspended');

    $reactivated = $service->reactivate($submitted->affiliateId, $admin);
    expect($reactivated->status)->toBe('active')
        // Reactivation mints a new code rather than reviving the old one.
        ->and($reactivated->issuedCode)->not->toBe($approved->issuedCode);

    expect($service->close($submitted->affiliateId, $admin)->status)->toBe('closed');

    $detail = $service->detail($submitted->affiliateId);

    // The snapshot keeps the rejection marker even though the affiliate was later
    // approved: it is a cumulative marker, not a history. The ledger holds the history.
    expect($detail->status)->toBe('closed')
        ->and($detail->rejectedAt)->not->toBeNull()
        ->and($detail->approvedAt)->not->toBeNull()
        ->and($detail->activeCodeId)->toBeNull()
        ->and($detail->activeCode)->toBeNull()
        ->and($service->history($submitted->affiliateId))->toHaveCount(7)
        ->and($service->codes($submitted->affiliateId))->toHaveCount(2);
});

it('translates every replayed transition into a typed refusal, never a raw query error', function () {
    $ctx = p6d11sActive();
    $service = p6d11sService();
    $admin = $ctx['admin'];

    $service->close($ctx['id'], $admin);

    foreach ([
        fn () => $service->review($ctx['id'], AffiliateReviewDecision::Approve, $admin),
        fn () => $service->suspend($ctx['id'], $admin),
        fn () => $service->reactivate($ctx['id'], $admin),
        fn () => $service->close($ctx['id'], $admin),
        fn () => $service->rotateCode($ctx['id'], 999_999, $admin),
    ] as $replay) {
        expect($replay)->toThrow(AffiliateOperationException::class)
            ->and($replay)->not->toThrow(QueryException::class);
    }
});

// ── Read model and boundaries ───────────────────────────────────────────────────

it('returns the rotation token beside the value it identifies, in one call', function () {
    $ctx = p6d11sActive();
    $detail = p6d11sService()->detail($ctx['id']);

    $row = Fx::owner()->selectOne(
        'SELECT id, code FROM affiliate_codes WHERE affiliate_id = ? AND is_active',
        [$ctx['id']],
    );

    expect($detail->activeCodeId)->toBe((int) $row->id)
        ->and($detail->activeCode)->toBe((string) $row->code);

    // The list stays a browsing projection: no rotation may start from it.
    $summary = collect(p6d11sService()->list())->firstWhere('id', $ctx['id']);

    expect($summary->activeCode)->toBe((string) $row->code)
        ->and(property_exists($summary, 'activeCodeId'))->toBeFalse();
});

it('refuses to run inside an ambient transaction', function () {
    $ctx = p6d11sActive();

    // An authority owns its own transactional boundary. A caller must not be able to widen
    // it and leave a half-applied lifecycle hanging on someone else's rollback.
    DB::beginTransaction();

    try {
        expect(fn () => p6d11sService()->suspend($ctx['id'], $ctx['admin']))
            ->toThrow(AffiliateOperationException::class);
    } finally {
        DB::rollBack();
    }
});

it('refuses every operation while the affiliate flag is off', function () {
    $ctx = p6d11sActive();
    config(['affiliate.governance_enabled' => false]);

    $service = p6d11sService();

    expect(fn () => $service->detail($ctx['id']))->toThrow(AffiliateOperationException::class)
        ->and(fn () => $service->list())->toThrow(AffiliateOperationException::class)
        ->and(fn () => $service->suspend($ctx['id'], $ctx['admin']))->toThrow(AffiliateOperationException::class);
});
