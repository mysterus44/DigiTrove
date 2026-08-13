<?php

declare(strict_types=1);

namespace App\Services\Affiliate;

/**
 * The detail snapshot an administrator reads before acting.
 *
 * `activeCodeId` and `activeCode` are two halves of one fact and arrive from one row of
 * one authority call. Holding them together is the whole point: the id is the
 * compare-and-swap token for `rotateCode()`, and it is only meaningful as proof of intent
 * because it was read in the same breath as the value that was displayed.
 *
 * The timestamps are cumulative markers on the snapshot, not a history — `rejectedAt`
 * survives a later approval on purpose. The lifecycle ledger is the only history, and it
 * is never consulted to work out the current status.
 */
final readonly class AffiliateDetail
{
    public function __construct(
        public int $id,
        public string $publicId,
        public int $userId,
        public string $status,
        public ?string $appliedAt,
        public ?string $approvedAt,
        public ?string $rejectedAt,
        public ?string $suspendedAt,
        public ?string $closedAt,
        public ?int $reviewedByUserId,
        public ?int $activeCodeId,
        public ?string $activeCode,
    ) {}

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->affiliate_id,
            publicId: (string) $row->public_id,
            userId: (int) $row->user_id,
            status: (string) $row->affiliate_status,
            appliedAt: $row->applied_at === null ? null : (string) $row->applied_at,
            approvedAt: $row->approved_at === null ? null : (string) $row->approved_at,
            rejectedAt: $row->rejected_at === null ? null : (string) $row->rejected_at,
            suspendedAt: $row->suspended_at === null ? null : (string) $row->suspended_at,
            closedAt: $row->closed_at === null ? null : (string) $row->closed_at,
            reviewedByUserId: $row->reviewed_by_user_id === null ? null : (int) $row->reviewed_by_user_id,
            activeCodeId: $row->active_code_id === null ? null : (int) $row->active_code_id,
            activeCode: $row->active_code === null ? null : (string) $row->active_code,
        );
    }
}
