<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AuthorizesAffiliateAdmin;
use App\Models\User;
use App\Services\Affiliate\AffiliateDetail;
use App\Services\Affiliate\AffiliateLifecycleService;
use App\Services\Affiliate\AffiliateOperationException;
use App\Services\Affiliate\AffiliateReviewDecision;
use App\Services\Affiliate\AffiliateSummary;
use BackedEnum;
use Filament\Pages\Page;
use Throwable;

/**
 * P6-D1.1 affiliate lifecycle administration (D-059).
 *
 * Admin-only, fail-closed, and physically unable to reach the affiliate tables: every read
 * and every mutation goes through the EXECUTE-only PostgreSQL authorities via
 * `AffiliateLifecycleService`. There is no Eloquent model, so this is a custom Page rather
 * than a Resource — a Resource would demand a model and that model would be a hole in the
 * privilege frontier.
 *
 * THE SNAPSHOT IS THE POINT. When an affiliate is opened, its detail is captured into
 * component state: the status, the active code, and above all `expectedActiveCodeId`. The
 * page then RENDERS FROM THAT CAPTURE. Rotation sends the captured id back, so the request
 * means "replace the code I was looking at" and not "replace whatever is active now". If
 * this class ever re-read the active id at click time, a rotation performed by a second
 * administrator in the meantime would be silently overwritten instead of refused — which
 * is precisely what the compare-and-swap and its `AF001` SQLSTATE exist to prevent.
 *
 * NOT HERE, DELIBERATELY: no commission, payout, attribution, touch or refund figure; no
 * referral link, cookie or `?ref=`; no customer-facing surface. Those belong to P6-D2 and
 * beyond.
 */
final class AffiliateLifecycle extends Page
{
    use AuthorizesAffiliateAdmin;

    protected static string $routePath = 'affiliate-lifecycle';

    protected static ?string $navigationLabel = 'Affiliés';

    protected static string|\UnitEnum|null $navigationGroup = 'Affiliation';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 42;

    protected static ?string $title = 'Affiliés';

    protected string $view = 'filament.pages.affiliate-lifecycle';

    public const PAGE_SIZE = 25;

    /** Restricts the list; null shows every state. */
    public ?string $statusFilter = null;

    /**
     * The account an administrator is registering an application for.
     *
     * A numeric account id, because `users` has no public identifier and the runtime role
     * holds column privileges on `id, role, status, deleted_at` ONLY — it cannot read
     * `users.email`, so resolving an account by address is not merely undesirable here, it
     * is impossible. Eligibility is decided by the authority, never by this form.
     */
    public ?int $applicantUserId = null;

    // ── The captured snapshot ──────────────────────────────────────────────────────

    public ?int $affiliateId = null;

    public ?string $shownStatus = null;

    public ?string $shownActiveCode = null;

    /** The compare-and-swap token. Captured on open, sent back untouched on rotate. */
    public ?int $expectedActiveCodeId = null;

    public ?string $shownAppliedAt = null;

    public ?string $shownApprovedAt = null;

    public ?string $shownRejectedAt = null;

    public ?string $shownSuspendedAt = null;

    public ?string $shownClosedAt = null;

    /** Set when the database refused because the capture no longer describes reality. */
    public bool $snapshotStale = false;

    public bool $unavailable = false;

    public ?string $notice = null;

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    // ── Reads ──────────────────────────────────────────────────────────────────────

    /** @return list<AffiliateSummary> */
    public function affiliates(): array
    {
        return $this->safe(fn (AffiliateLifecycleService $s): array => $s->list(
            $this->statusFilter,
            self::PAGE_SIZE,
        )) ?? [];
    }

    /** @return list<object> The ledger — history, never the source of the current status. */
    public function lifecycleHistory(): array
    {
        if ($this->affiliateId === null) {
            return [];
        }

        return $this->safe(fn (AffiliateLifecycleService $s): array => $s->history(
            $this->affiliateId,
            self::PAGE_SIZE,
        )) ?? [];
    }

    /** @return list<object> Every code ever held, active or retired. */
    public function codeHistory(): array
    {
        if ($this->affiliateId === null) {
            return [];
        }

        return $this->safe(fn (AffiliateLifecycleService $s): array => $s->codes(
            $this->affiliateId,
            self::PAGE_SIZE,
        )) ?? [];
    }

    /**
     * Which mutations the captured state suggests.
     *
     * A convenience for the operator, NOT a control: the authority decides, and it is the
     * only thing that can, because this capture may already be out of date.
     *
     * @return list<string>
     */
    public function availableActions(): array
    {
        return match ($this->shownStatus) {
            'pending' => ['approve', 'reject'],
            'rejected' => ['reapply'],
            'active' => ['rotate', 'suspend', 'close'],
            'suspended' => ['reactivate', 'close'],
            default => [],
        };
    }

    // ── Snapshot capture ───────────────────────────────────────────────────────────

    /** Open an affiliate and freeze what the administrator is about to act on. */
    public function open(int $affiliateId): void
    {
        $this->authorizeAffiliateAction();
        $this->notice = null;
        $this->affiliateId = $affiliateId;
        $this->capture();
    }

    /** Explicit refresh — the way out of a stale snapshot. */
    public function refreshSnapshot(): void
    {
        $this->authorizeAffiliateAction();
        $this->notice = null;
        $this->capture();
    }

    // ── Mutations ──────────────────────────────────────────────────────────────────

    public function submitApplication(): void
    {
        if ($this->applicantUserId === null) {
            $this->notice = 'Indiquez le compte client concerné.';

            return;
        }

        $transition = $this->mutate(
            fn (AffiliateLifecycleService $s) => $s->submit($this->applicantUserId),
            'Candidature enregistrée.',
        );

        if ($transition !== null) {
            // A re-application transitions the existing row; it never creates a second one.
            $this->affiliateId = $transition->affiliateId;
            $this->applicantUserId = null;
            $this->capture();
        }
    }

    public function approve(): void
    {
        $this->review(AffiliateReviewDecision::Approve, 'Candidature approuvée. Un code a été émis.');
    }

    public function reject(): void
    {
        $this->review(AffiliateReviewDecision::Reject, 'Candidature refusée.');
    }

    /** Re-application after a refusal — the same authority as the first application. */
    public function reapply(): void
    {
        $userId = $this->currentUserId();

        if ($userId === null) {
            return;
        }

        if ($this->mutate(
            fn (AffiliateLifecycleService $s) => $s->submit($userId),
            'Nouvelle candidature enregistrée.',
        ) !== null) {
            $this->capture();
        }
    }

    public function suspend(): void
    {
        $this->guarded(
            fn (AffiliateLifecycleService $s, int $id, $actor) => $s->suspend($id, $actor),
            'Affilié suspendu. Son code a été désactivé.',
        );
    }

    public function reactivate(): void
    {
        $this->guarded(
            fn (AffiliateLifecycleService $s, int $id, $actor) => $s->reactivate($id, $actor),
            'Affilié réactivé. Un nouveau code a été émis.',
        );
    }

    public function closeAffiliate(): void
    {
        $this->guarded(
            fn (AffiliateLifecycleService $s, int $id, $actor) => $s->close($id, $actor),
            'Affilié clôturé définitivement.',
        );
    }

    /**
     * Replace the code the administrator has on screen.
     *
     * `$this->expectedActiveCodeId` is component state captured when the affiliate was
     * opened. It is sent as-is. Nothing in this method asks the database which code is
     * active — that question is the authority's to answer, by accepting or refusing.
     */
    public function rotateCode(): void
    {
        $this->authorizeAffiliateAction();
        $this->notice = null;

        if ($this->affiliateId === null || $this->expectedActiveCodeId === null) {
            $this->notice = 'Aucun code actif à faire tourner.';

            return;
        }

        $actor = auth()->user();

        if ($actor === null) {
            abort(403);
        }

        try {
            $issued = app(AffiliateLifecycleService::class)->rotateCode(
                $this->affiliateId,
                $this->expectedActiveCodeId,
                $actor,
            );
        } catch (AffiliateOperationException $exception) {
            $this->notice = $exception->reason->message();
            // The capture is left untouched on purpose: the operator must look again
            // before acting again. Silently re-reading here would restore the very
            // behaviour the compare-and-swap exists to forbid.
            $this->snapshotStale = true;

            return;
        } catch (Throwable) {
            $this->unavailable = true;

            return;
        }

        $this->notice = 'Nouveau code émis : '.$issued;
        $this->capture();
    }

    // ── Internals ──────────────────────────────────────────────────────────────────

    private function review(AffiliateReviewDecision $decision, string $success): void
    {
        $this->guarded(
            fn (AffiliateLifecycleService $s, int $id, $actor) => $s->review($id, $decision, $actor),
            $success,
        );
    }

    /** The account behind the open affiliate, read from the captured detail. */
    private function currentUserId(): ?int
    {
        $detail = $this->readDetail();

        return $detail?->userId;
    }

    /**
     * Run a mutation that needs the open affiliate and the acting administrator.
     *
     * @param  callable(AffiliateLifecycleService, int, User):mixed  $mutation
     */
    private function guarded(callable $mutation, string $success): void
    {
        if ($this->affiliateId === null) {
            return;
        }

        $affiliateId = $this->affiliateId;

        if ($this->mutate(
            function (AffiliateLifecycleService $s) use ($mutation, $affiliateId) {
                $actor = auth()->user();

                if ($actor === null) {
                    abort(403);
                }

                return $mutation($s, $affiliateId, $actor);
            },
            $success,
        ) !== null) {
            $this->capture();
        }
    }

    /**
     * Authorize, call, translate. The Gate is re-checked here — immediately before the
     * call — and not merely at page load: an administrator suspended while the page sat
     * open must be refused on the action, not on the next navigation.
     *
     * @param  callable(AffiliateLifecycleService):mixed  $mutation
     */
    private function mutate(callable $mutation, string $success): mixed
    {
        $this->authorizeAffiliateAction();
        $this->notice = null;

        try {
            $result = $mutation(app(AffiliateLifecycleService::class));
        } catch (AffiliateOperationException $exception) {
            $this->notice = $exception->reason->message();

            return null;
        } catch (Throwable) {
            $this->unavailable = true;

            return null;
        }

        $this->notice = $success;

        return $result;
    }

    /** Refresh the frozen state from the authority, token included. */
    private function capture(): void
    {
        $detail = $this->readDetail();
        $this->snapshotStale = false;

        if ($detail === null) {
            $this->shownStatus = null;
            $this->shownActiveCode = null;
            $this->expectedActiveCodeId = null;

            return;
        }

        $this->shownStatus = $detail->status;
        $this->shownActiveCode = $detail->activeCode;
        $this->expectedActiveCodeId = $detail->activeCodeId;
        $this->shownAppliedAt = $detail->appliedAt;
        $this->shownApprovedAt = $detail->approvedAt;
        $this->shownRejectedAt = $detail->rejectedAt;
        $this->shownSuspendedAt = $detail->suspendedAt;
        $this->shownClosedAt = $detail->closedAt;
    }

    private function readDetail(): ?AffiliateDetail
    {
        if ($this->affiliateId === null) {
            return null;
        }

        return $this->safe(fn (AffiliateLifecycleService $s): ?AffiliateDetail => $s->detail($this->affiliateId));
    }

    /**
     * Run a reader through the authority, closing over failures rather than leaking a
     * driver message.
     *
     * @template T
     *
     * @param  callable(AffiliateLifecycleService):T  $reader
     * @return T|null
     */
    private function safe(callable $reader): mixed
    {
        $this->authorizeAffiliateAction();

        try {
            return $reader(app(AffiliateLifecycleService::class));
        } catch (Throwable) {
            $this->unavailable = true;

            return null;
        }
    }
}
