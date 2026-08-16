<?php

declare(strict_types=1);

namespace App\Services\Affiliate;

use App\Models\User;
use App\Services\Affiliate\Concerns\UsesAffiliateAuthority;
use Throwable;

/**
 * P6-D1.1 affiliate lifecycle and codes (D-059).
 *
 * Every method is one call into one bounded PostgreSQL authority. There is no query
 * builder, no `DB::table('affiliates')`, no Eloquent model: the runtime role holds no
 * privilege on the affiliate tables, so a direct read would be refused at the database —
 * and the absence of a model means one cannot be introduced here out of habit.
 *
 * The state machine lives in PostgreSQL and is NOT mirrored in PHP. This service does not
 * decide whether an application may be approved or whether an affiliate may be
 * reactivated; it forwards the request and translates the refusal. Duplicating those rules
 * here would create a second authority free to quietly disagree with the first.
 */
final class AffiliateLifecycleService
{
    use UsesAffiliateAuthority;

    /**
     * Apply, or apply again after a rejection.
     *
     * One method, because PostgreSQL has one authority: `affiliates.user_id` is UNIQUE, so
     * a second application is a TRANSITION on the existing row, never a second row. A
     * separate `reapply()` would suggest otherwise.
     */
    public function submit(int $userId): AffiliateTransition
    {
        return $this->transition('SELECT * FROM submit_affiliate_application(?)', [$userId]);
    }

    /** Approve or reject a pending application — one decision, two outcomes. */
    public function review(
        int $affiliateId,
        AffiliateReviewDecision $decision,
        User $actor,
        ?string $reasonCode = null,
    ): AffiliateTransition {
        return $this->transition(
            'SELECT * FROM review_affiliate_application(?, ?, ?, ?)',
            [$affiliateId, $decision->value, (int) $actor->id, $reasonCode],
        );
    }

    public function suspend(int $affiliateId, User $actor, ?string $reasonCode = null): AffiliateTransition
    {
        return $this->transition(
            'SELECT * FROM suspend_affiliate(?, ?, ?)',
            [$affiliateId, (int) $actor->id, $reasonCode],
        );
    }

    /** Reactivation always mints a NEW code; the retired one stays reserved for ever. */
    public function reactivate(int $affiliateId, User $actor): AffiliateTransition
    {
        return $this->transition(
            'SELECT * FROM reactivate_affiliate(?, ?)',
            [$affiliateId, (int) $actor->id],
        );
    }

    public function close(int $affiliateId, User $actor, ?string $reasonCode = null): AffiliateTransition
    {
        return $this->transition(
            'SELECT * FROM close_affiliate(?, ?, ?)',
            [$affiliateId, (int) $actor->id, $reasonCode],
        );
    }

    /**
     * Replace the active code, but only if it is still the one the administrator saw.
     *
     * `$expectedActiveCodeId` comes from the `AffiliateDetail` that was displayed. It is
     * required, not nullable, has no default, and is forwarded UNCHANGED. This method must
     * never look up the current active code first: a lock proves the ORDER of two
     * rotations, it does not prove the second one is still wanted. Re-reading here would
     * turn every stale request into a silent extra rotation — the exact defect the
     * compare-and-swap and its dedicated `AF001` SQLSTATE exist to make impossible.
     */
    public function rotateCode(int $affiliateId, int $expectedActiveCodeId, User $actor): string
    {
        $connection = $this->affiliateConnection();

        try {
            $row = $connection->selectOne(
                'SELECT * FROM rotate_affiliate_code(?, ?, ?)',
                [$affiliateId, $expectedActiveCodeId, (int) $actor->id],
            );
        } catch (Throwable $exception) {
            throw $this->affiliateRefusal($exception);
        }

        if ($row === null) {
            throw AffiliateOperationException::because(AffiliateRefusalReason::Unavailable);
        }

        return (string) $row->issued_code;
    }

    /**
     * Bounded administration list.
     *
     * @return list<AffiliateSummary>
     */
    public function list(?string $status = null, int $limit = 25, ?int $afterId = null): array
    {
        return array_map(
            static fn (object $row): AffiliateSummary => AffiliateSummary::fromRow($row),
            $this->read('SELECT * FROM list_affiliates(?, ?, ?)', [$status, $limit, $afterId]),
        );
    }

    /** The snapshot an administrator acts from — including the rotation token. */
    public function detail(int $affiliateId): ?AffiliateDetail
    {
        $connection = $this->affiliateConnection();

        try {
            $row = $connection->selectOne('SELECT * FROM get_affiliate(?)', [$affiliateId]);
        } catch (Throwable $exception) {
            throw $this->affiliateRefusal($exception);
        }

        return $row === null ? null : AffiliateDetail::fromRow($row);
    }

    /**
     * The transition history. It is history ONLY: the current status comes from
     * `detail()`, never from the newest event here.
     *
     * @return list<object>
     */
    public function history(int $affiliateId, int $limit = 25, ?int $beforeId = null): array
    {
        return $this->read(
            'SELECT * FROM list_affiliate_lifecycle_events(?, ?, ?)',
            [$affiliateId, $limit, $beforeId],
        );
    }

    /**
     * Every code the affiliate has ever held, active or retired.
     *
     * @return list<object>
     */
    public function codes(int $affiliateId, int $limit = 25): array
    {
        return $this->read('SELECT * FROM list_affiliate_codes(?, ?)', [$affiliateId, $limit]);
    }

    /** @param  list<mixed>  $bindings */
    private function transition(string $sql, array $bindings): AffiliateTransition
    {
        $connection = $this->affiliateConnection();

        try {
            $row = $connection->selectOne($sql, $bindings);
        } catch (Throwable $exception) {
            throw $this->affiliateRefusal($exception);
        }

        if ($row === null) {
            throw AffiliateOperationException::because(AffiliateRefusalReason::Unavailable);
        }

        return new AffiliateTransition(
            affiliateId: (int) $row->affiliate_id,
            status: (string) ($row->affiliate_status ?? ''),
            issuedCode: ($row->issued_code ?? null) === null ? null : (string) $row->issued_code,
        );
    }

    /**
     * @param  list<mixed>  $bindings
     * @return list<object>
     */
    private function read(string $sql, array $bindings): array
    {
        $connection = $this->affiliateConnection();

        try {
            return $connection->select($sql, $bindings);
        } catch (Throwable $exception) {
            throw $this->affiliateRefusal($exception);
        }
    }
}
