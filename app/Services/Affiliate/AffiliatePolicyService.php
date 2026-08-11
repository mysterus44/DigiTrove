<?php

declare(strict_types=1);

namespace App\Services\Affiliate;

use App\Services\Affiliate\Concerns\UsesAffiliateAuthority;
use Throwable;

/**
 * P6-D1 policy governance (D-058).
 *
 * Every method is a thin call into ONE bounded PostgreSQL authority. There is no query
 * builder here, no `DB::table('affiliate_…')`, no raw SELECT: the runtime holds no
 * privilege on those tables, so a direct read would fail anyway — and the point is that
 * it can never be added by accident later.
 *
 * This service governs the PROGRAMME. It knows nothing about affiliates, codes, touches,
 * attributions, commissions or payouts; those belong to P6-D1.1 and beyond.
 */
final class AffiliatePolicyService
{
    use UsesAffiliateAuthority;

    /** The policy in force right now, or null when the programme has never been published. */
    public function current(): ?AffiliatePolicy
    {
        $connection = $this->affiliateConnection();

        try {
            $row = $connection->selectOne('SELECT * FROM current_affiliate_program_policy()');
        } catch (Throwable $exception) {
            throw $this->affiliateRefusal($exception);
        }

        return $row === null ? null : new AffiliatePolicy(
            id: (int) $row->policy_id,
            version: (int) $row->version,
            status: 'active',
            attributionWindowDays: (int) $row->attribution_window_days,
            defaultCommissionBps: (int) $row->default_commission_bps,
            payableDelayDays: (int) $row->payable_delay_days,
            payoutThresholdMinor: (int) $row->payout_threshold_minor,
            payoutCurrency: (string) $row->payout_currency,
            effectiveFrom: $row->effective_from === null ? null : (string) $row->effective_from,
        );
    }

    /**
     * Bounded, deterministic history for the administration screen.
     *
     * @return list<AffiliatePolicy>
     */
    public function history(int $limit = 25, ?int $beforeVersion = null): array
    {
        $connection = $this->affiliateConnection();

        try {
            $rows = $connection->select(
                'SELECT * FROM list_affiliate_program_policies(?, ?)',
                [$limit, $beforeVersion],
            );
        } catch (Throwable $exception) {
            throw $this->affiliateRefusal($exception);
        }

        return array_map(static fn (object $row): AffiliatePolicy => new AffiliatePolicy(
            id: (int) $row->policy_id,
            version: (int) $row->version,
            status: (string) $row->status,
            attributionWindowDays: (int) $row->attribution_window_days,
            defaultCommissionBps: (int) $row->default_commission_bps,
            payableDelayDays: (int) $row->payable_delay_days,
            payoutThresholdMinor: (int) $row->payout_threshold_minor,
            payoutCurrency: (string) $row->payout_currency,
            effectiveFrom: $row->effective_from === null ? null : (string) $row->effective_from,
            effectiveUntil: $row->effective_until === null ? null : (string) $row->effective_until,
        ), $rows);
    }

    /**
     * Create the next draft.
     *
     * `$version` is passed through rather than computed here: it is the natural
     * idempotency identity, so a double-clicked form sends the same number twice and the
     * second attempt is refused by the database, deterministically.
     */
    public function createDraft(
        int $version,
        int $attributionWindowDays,
        int $commissionBps,
        int $payableDelayDays,
        int $payoutThresholdMinor,
        string $payoutCurrency,
    ): int {
        $connection = $this->affiliateConnection();

        try {
            $row = $connection->selectOne(
                'SELECT policy_id FROM create_affiliate_program_policy_draft(?, ?, ?, ?, ?, ?)',
                [$version, $attributionWindowDays, $commissionBps, $payableDelayDays, $payoutThresholdMinor, $payoutCurrency],
            );
        } catch (Throwable $exception) {
            throw $this->affiliateRefusal($exception);
        }

        if ($row === null) {
            throw AffiliateOperationException::because(AffiliateRefusalReason::Unavailable);
        }

        return (int) $row->policy_id;
    }

    public function updateDraft(
        int $policyId,
        int $attributionWindowDays,
        int $commissionBps,
        int $payableDelayDays,
        int $payoutThresholdMinor,
        string $payoutCurrency,
    ): void {
        $connection = $this->affiliateConnection();

        try {
            $connection->selectOne(
                'SELECT policy_id FROM update_affiliate_program_policy_draft(?, ?, ?, ?, ?, ?)',
                [$policyId, $attributionWindowDays, $commissionBps, $payableDelayDays, $payoutThresholdMinor, $payoutCurrency],
            );
        } catch (Throwable $exception) {
            throw $this->affiliateRefusal($exception);
        }
    }

    /**
     * Publish a draft. Immediate by decision (D-058): the caller never supplies an
     * effective instant, so nothing can be scheduled through this path.
     */
    public function publish(int $policyId): int
    {
        $connection = $this->affiliateConnection();

        try {
            $row = $connection->selectOne(
                'SELECT policy_id FROM publish_affiliate_program_policy(?)',
                [$policyId],
            );
        } catch (Throwable $exception) {
            throw $this->affiliateRefusal($exception);
        }

        if ($row === null) {
            throw AffiliateOperationException::because(AffiliateRefusalReason::Unavailable);
        }

        return (int) $row->policy_id;
    }

    /** The version number the next draft must claim. */
    public function nextVersion(): int
    {
        $history = $this->history(limit: 1);

        return $history === [] ? 1 : $history[0]->version + 1;
    }
}
