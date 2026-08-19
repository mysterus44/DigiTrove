<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AuthorizesAffiliateAdmin;
use App\Services\Affiliate\AffiliateOperationException;
use App\Services\Affiliate\AffiliatePayoutService;
use BackedEnum;
use Filament\Pages\Page;
use Throwable;

/**
 * P6-D4 administrative payout administration (D-069).
 *
 * Admin-only, fail-closed, and physically unable to reach the affiliate tables: every read
 * and every mutation goes through the EXECUTE-only PostgreSQL authorities via
 * `AffiliatePayoutService`. No Eloquent model, so a custom Page and not a Resource — a
 * Resource would demand a model and that model would be a hole in the privilege frontier.
 *
 * ⚠️ THIS SCREEN MOVES NO MONEY. Marking a payout `paid` records that a transfer happened
 * ELSEWHERE, against an administrative reference. There is no provider, no bank detail and
 * no Mobile Money number anywhere in this gate (D-057 §12).
 *
 * THE SNAPSHOT IS THE POINT, exactly as in `AffiliateLifecycle`. Opening a payout captures
 * its status into component state and the page renders from that capture; every decision
 * sends the captured status back, so the request means "approve the payout I was looking at"
 * and not "approve whatever it is now". A colleague who acted in the meantime causes a
 * refusal (`AF002`), never a silent overwrite.
 *
 * AND THE SNAPSHOT IS NOT ENOUGH. Compare-and-swap protects against a race between two
 * administrators; it protects nothing against one administrator who requests a transfer and
 * approves it himself. The authority enforces two DISTINCT administrators, and this page
 * cannot weaken that — it only reports the refusal.
 *
 * NOT HERE, DELIBERATELY: no bank or Mobile Money detail, no provider, no clawback of an
 * already-paid payout, no customer-facing surface, no e-mail address. Affiliates are
 * identified by `public_id` and account id, like the other two affiliate screens.
 */
final class AffiliatePayouts extends Page
{
    use AuthorizesAffiliateAdmin;

    protected static string $routePath = 'affiliate-payouts';

    protected static ?string $navigationLabel = 'Versements';

    protected static string|\UnitEnum|null $navigationGroup = 'Affiliation';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 43;

    protected static ?string $title = 'Versements affiliés';

    protected string $view = 'filament.pages.affiliate-payouts';

    public const PAGE_SIZE = 25;

    /** Restricts the payout list; null shows every state. */
    public ?string $statusFilter = null;

    // ── The captured snapshot ──────────────────────────────────────────────────────

    public ?int $payoutId = null;

    /** The compare-and-swap token. Captured on open, sent back untouched on every decision. */
    public ?string $shownStatus = null;

    public ?int $shownAmountMinor = null;

    public ?string $shownCurrency = null;

    public ?int $shownThresholdMinor = null;

    public ?string $shownAffiliatePublicId = null;

    public ?int $shownRequestedBy = null;

    public ?int $shownApprovedBy = null;

    public ?string $shownReference = null;

    /** The reference an administrator types when recording an external transfer. */
    public ?string $administrativeReference = null;

    public bool $snapshotStale = false;

    public bool $unavailable = false;

    public ?string $notice = null;

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    // ── Reads ──────────────────────────────────────────────────────────────────────

    /** @return list<object> One row per (affiliate, currency) that is owed something. */
    public function candidates(): array
    {
        return $this->safe(fn (AffiliatePayoutService $s): array => $s->candidates(self::PAGE_SIZE)) ?? [];
    }

    /** @return list<object> */
    public function payouts(): array
    {
        return $this->safe(fn (AffiliatePayoutService $s): array => $s->payouts(
            $this->statusFilter,
            self::PAGE_SIZE,
        )) ?? [];
    }

    /** @return list<object> The commissions this payout settles. */
    public function items(): array
    {
        if ($this->payoutId === null) {
            return [];
        }

        return $this->safe(fn (AffiliatePayoutService $s): array => $s->items($this->payoutId)) ?? [];
    }

    /**
     * Which decisions the captured state suggests.
     *
     * A convenience for the operator, NOT a control: the authority owns the state machine
     * and is the only thing that can decide, because this capture may already be stale.
     *
     * @return list<string>
     */
    public function availableActions(): array
    {
        return match ($this->shownStatus) {
            'requested' => ['approve', 'reject', 'cancel'],
            'approved' => ['markPaid', 'cancel'],
            default => [],
        };
    }

    // ── Snapshot capture ───────────────────────────────────────────────────────────

    public function open(int $payoutId): void
    {
        $this->authorizeAffiliateAction();
        $this->notice = null;
        $this->administrativeReference = null;
        $this->payoutId = $payoutId;
        $this->capture();
    }

    /** Explicit refresh — the only way out of a stale snapshot. */
    public function refreshSnapshot(): void
    {
        $this->authorizeAffiliateAction();
        $this->notice = null;
        $this->capture();
    }

    // ── Mutations ──────────────────────────────────────────────────────────────────

    /**
     * Reserve everything an affiliate is owed in one currency.
     *
     * The reservation happens now, not at approval: two administrators drafting at the same
     * moment must not both grab the same commission.
     */
    public function requestPayout(int $affiliateId, string $currency): void
    {
        $this->authorizeAffiliateAction();
        $this->notice = null;

        $actor = auth()->user();

        if ($actor === null) {
            abort(403);
        }

        try {
            $result = app(AffiliatePayoutService::class)->request($affiliateId, $currency, (int) $actor->getAuthIdentifier());
        } catch (AffiliateOperationException $exception) {
            $this->notice = $exception->reason->message();

            return;
        } catch (Throwable) {
            $this->unavailable = true;

            return;
        }

        $this->notice = match ($result['status']) {
            'requested' => 'Demande de versement créée.',
            'nothing_payable' => 'Aucune commission payable pour cet affilié.',
            'below_threshold' => 'Le total payable est sous le seuil de la politique active.',
            'unsupported_currency' => 'La politique active ne définit pas de seuil dans cette devise.',
            'no_active_policy' => 'Aucune politique active : le seuil est indéterminable.',
            default => 'La demande n’a pas pu être créée.',
        };

        if ($result['payout_id'] !== null) {
            $this->payoutId = $result['payout_id'];
            $this->capture();
        }
    }

    public function approve(): void
    {
        $this->decide('approved', 'Versement approuvé.');
    }

    public function reject(): void
    {
        $this->decide('rejected', 'Versement refusé. Les commissions redeviennent payables.');
    }

    public function cancel(): void
    {
        $this->decide('cancelled', 'Versement annulé. Les commissions redeviennent payables.');
    }

    /** Record that a transfer was made OUTSIDE this system, against its reference. */
    public function markPaid(): void
    {
        $this->decide('paid', 'Versement enregistré comme payé.');
    }

    // ── Internals ──────────────────────────────────────────────────────────────────

    /**
     * Every decision sends the CAPTURED status back. Nothing here re-reads the current one:
     * that question belongs to the authority, which answers by accepting or refusing.
     */
    private function decide(string $target, string $success): void
    {
        $this->authorizeAffiliateAction();
        $this->notice = null;

        if ($this->payoutId === null || $this->shownStatus === null) {
            return;
        }

        $actor = auth()->user();

        if ($actor === null) {
            abort(403);
        }

        try {
            $status = app(AffiliatePayoutService::class)->transition(
                $this->payoutId,
                $this->shownStatus,
                $target,
                (int) $actor->getAuthIdentifier(),
                $this->administrativeReference,
            );
        } catch (AffiliateOperationException $exception) {
            $this->notice = $exception->reason->message();
            // The capture is left untouched on purpose: the operator must look again before
            // deciding again. Re-reading silently here would restore exactly the behaviour
            // the compare-and-swap exists to forbid.
            $this->snapshotStale = true;

            return;
        } catch (Throwable) {
            $this->unavailable = true;

            return;
        }

        $this->notice = match ($status) {
            'transitioned' => $success,
            'missing_administrative_reference' => 'Renseignez la référence administrative du virement.',
            'same_administrator_forbidden' => 'Un versement doit être approuvé par un second administrateur.',
            'illegal_transition' => 'Cette transition n’est pas autorisée depuis cet état.',
            'no_such_payout' => 'Ce versement n’existe plus.',
            default => 'La décision n’a pas pu être enregistrée.',
        };

        if ($status === 'transitioned') {
            $this->administrativeReference = null;
        }

        $this->capture();
    }

    /** Freeze what the administrator is about to act on. */
    private function capture(): void
    {
        $this->snapshotStale = false;

        $rows = $this->safe(fn (AffiliatePayoutService $s): array => $s->payouts(null, 500)) ?? [];

        foreach ($rows as $row) {
            if ((int) $row->payout_id === $this->payoutId) {
                $this->shownStatus = (string) $row->payout_status;
                $this->shownAmountMinor = (int) $row->amount_minor;
                $this->shownCurrency = (string) $row->currency;
                $this->shownThresholdMinor = (int) $row->threshold_minor_snapshot;
                $this->shownAffiliatePublicId = (string) $row->affiliate_public_id;
                $this->shownRequestedBy = $row->requested_by_user_id === null ? null : (int) $row->requested_by_user_id;
                $this->shownApprovedBy = $row->approved_by_user_id === null ? null : (int) $row->approved_by_user_id;
                $this->shownReference = $row->administrative_reference === null ? null : (string) $row->administrative_reference;

                return;
            }
        }

        $this->shownStatus = null;
    }

    /**
     * @template T
     *
     * @param  callable(AffiliatePayoutService):T  $read
     * @return T|null
     */
    private function safe(callable $read): mixed
    {
        try {
            return $read(app(AffiliatePayoutService::class));
        } catch (Throwable) {
            $this->unavailable = true;

            return null;
        }
    }
}
