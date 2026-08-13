<?php

declare(strict_types=1);

namespace App\Services\Affiliate;

/**
 * One row of the administration list.
 *
 * Deliberately WITHOUT the active code id: the list is a browsing projection, and a
 * rotation token must come from the detail snapshot an administrator actually read. The
 * authority withholds the id for the same reason, so this class could not carry it even if
 * someone wanted it to.
 */
final readonly class AffiliateSummary
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
            activeCode: $row->active_code === null ? null : (string) $row->active_code,
        );
    }
}
