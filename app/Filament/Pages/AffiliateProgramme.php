<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AuthorizesAffiliateAdmin;
use App\Services\Affiliate\AffiliateOperationException;
use App\Services\Affiliate\AffiliatePolicy;
use App\Services\Affiliate\AffiliatePolicyService;
use BackedEnum;
use Filament\Pages\Page;
use Throwable;

/**
 * À VALIDER (P6-D1, D-058). Écrit hors environnement de test (pas de PHP 8.4 / PostgreSQL
 * disponibles) : à prouver avec `php artisan test` dès qu'un environnement capable existe.
 *
 * Affiliate PROGRAMME governance. Admin-only, fail-closed, and physically unable to reach
 * the affiliate tables: every read and every mutation goes through the P6-D1 EXECUTE-only
 * PostgreSQL authorities, exactly as CrmSegments does for the CRM authorities.
 *
 * This page governs the PROGRAMME POLICY only (D-058, Q1=A). It knows nothing about
 * affiliates, codes, touches, attributions, commissions or payouts — those belong to
 * P6-D1.1 and beyond and MUST NOT appear here.
 *
 * TWO THINGS IT DELIBERATELY DOES NOT OFFER:
 *  - No effective date input. Publication is immediate by decision; the caller never
 *    supplies an instant, so nothing can be scheduled (the draft's effective_from is a
 *    placeholder the authority overwrites).
 *  - No attribution-model or manual-payout control. Those are fixed by the authority, so an
 *    operator cannot smuggle in an unreviewed model or an automatic payout.
 */
final class AffiliateProgramme extends Page
{
    use AuthorizesAffiliateAdmin;

    protected static string $routePath = 'affiliate-programme';

    protected static ?string $navigationLabel = 'Programme d\'affiliation';

    protected static string|\UnitEnum|null $navigationGroup = 'Affiliation';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = 41;

    protected static ?string $title = 'Programme d\'affiliation';

    protected string $view = 'filament.pages.affiliate-programme';

    public const HISTORY_PAGE_SIZE = 25;

    // ── Draft form state — structured inputs only, never free text ──────────────────

    public int $attributionWindowDays = 30;

    public int $commissionBps = 1500;

    public int $payableDelayDays = 14;

    public int $payoutThresholdMinor = 10000;

    public string $payoutCurrency = 'XOF';

    /** Newest-first keyset cursor for history paging. */
    public ?int $beforeVersion = null;

    /** @var list<int|null> */
    public array $cursorStack = [];

    public bool $unavailable = false;

    public ?string $notice = null;

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    // ── Reads ──────────────────────────────────────────────────────────────────────

    /** The policy in force right now, or null when the programme has never been published. */
    public function current(): ?AffiliatePolicy
    {
        return $this->safe(static fn (AffiliatePolicyService $s): ?AffiliatePolicy => $s->current());
    }

    /** @return list<AffiliatePolicy> Bounded, newest-first history. */
    public function history(): array
    {
        return $this->safe(fn (AffiliatePolicyService $s): array => $s->history(
            self::HISTORY_PAGE_SIZE,
            $this->beforeVersion,
        )) ?? [];
    }

    /** The version number the next draft must claim, shown read-only in the form. */
    public function nextVersion(): int
    {
        return $this->safe(static fn (AffiliatePolicyService $s): int => $s->nextVersion()) ?? 1;
    }

    // ── Actions ────────────────────────────────────────────────────────────────────

    /**
     * Create the next draft. The version is computed server-side and passed through as the
     * natural idempotency identity — a double-clicked button sends the same number twice and
     * the second attempt is refused deterministically by the database.
     */
    public function createDraft(): void
    {
        $this->authorizeAffiliateAction();
        $this->notice = null;

        try {
            $service = app(AffiliatePolicyService::class);
            $version = $service->nextVersion();
            $id = $service->createDraft(
                $version,
                $this->attributionWindowDays,
                $this->commissionBps,
                $this->payableDelayDays,
                $this->payoutThresholdMinor,
                $this->payoutCurrency,
            );
        } catch (AffiliateOperationException $exception) {
            $this->notice = $exception->reason->message();

            return;
        } catch (Throwable) {
            $this->unavailable = true;

            return;
        }

        $this->notice = 'Brouillon #'.$id.' (version '.$version.') créé.';
    }

    public function updateDraft(int $policyId): void
    {
        $this->authorizeAffiliateAction();
        $this->notice = null;

        try {
            app(AffiliatePolicyService::class)->updateDraft(
                $policyId,
                $this->attributionWindowDays,
                $this->commissionBps,
                $this->payableDelayDays,
                $this->payoutThresholdMinor,
                $this->payoutCurrency,
            );
        } catch (AffiliateOperationException $exception) {
            $this->notice = $exception->reason->message();

            return;
        } catch (Throwable) {
            $this->unavailable = true;

            return;
        }

        $this->notice = 'Brouillon #'.$policyId.' mis à jour.';
    }

    /** Publishing is explicit and immediate: it closes the predecessor and opens the successor in one atomic transition. */
    public function publish(int $policyId): void
    {
        $this->authorizeAffiliateAction();
        $this->notice = null;

        try {
            app(AffiliatePolicyService::class)->publish($policyId);
        } catch (AffiliateOperationException $exception) {
            $this->notice = $exception->reason->message();

            return;
        } catch (Throwable) {
            $this->unavailable = true;

            return;
        }

        $this->notice = 'Politique #'.$policyId.' publiée et en vigueur.';
    }

    // ── Paging ─────────────────────────────────────────────────────────────────────

    public function nextPage(): void
    {
        $page = $this->history();

        if ($page === []) {
            return;
        }

        $this->cursorStack[] = $this->beforeVersion;
        $this->beforeVersion = $page[array_key_last($page)]->version;
    }

    public function previousPage(): void
    {
        if ($this->cursorStack === []) {
            $this->beforeVersion = null;

            return;
        }

        $this->beforeVersion = array_pop($this->cursorStack);
    }

    // ── Internals ──────────────────────────────────────────────────────────────────

    /**
     * Run a reader through the authority, closing over failures rather than leaking a
     * driver message. Returns null on failure so callers apply their own fallback.
     *
     * @template T
     *
     * @param  callable(AffiliatePolicyService):T  $reader
     * @return T|null
     */
    private function safe(callable $reader): mixed
    {
        $this->authorizeAffiliateAction();

        try {
            return $reader(app(AffiliatePolicyService::class));
        } catch (Throwable) {
            $this->unavailable = true;

            return null;
        }
    }
}
