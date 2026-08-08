<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\CrmCommerceRollupRefreshStatus;

/**
 * Immutable, PII-free outcome of processing one (contact, currency) refresh. It never
 * carries money, email or order identifiers — only the durable coordination state.
 */
final class CrmCommerceRollupRefreshResult
{
    public function __construct(
        public readonly int $contactId,
        public readonly string $currency,
        public readonly CrmCommerceRollupRefreshStatus $status,
        public readonly ?int $requestedGeneration,
        public readonly ?int $processedGeneration,
    ) {}
}
