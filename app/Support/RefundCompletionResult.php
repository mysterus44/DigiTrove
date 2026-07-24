<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The outcome of finalising a succeeded refund (P4-C2, D-035).
 */
final readonly class RefundCompletionResult
{
    public function __construct(
        public int $refundId,
        public string $orderStatus,
        public bool $isReplay,
        public int $revokedGrantCount,
    ) {}
}
