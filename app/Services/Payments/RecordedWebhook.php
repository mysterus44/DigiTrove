<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * The result of persisting (or resolving a replay of) a webhook event
 * (P3-D4, D-034). Carries no payload, no secret and no model — only what the
 * orchestrator needs to decide whether to counter-verify.
 */
final readonly class RecordedWebhook
{
    public function __construct(
        public int $id,
        public bool $isReplay,
        public bool $signatureVerified,
        public string $processingStatus,
    ) {}

    public function isTerminal(): bool
    {
        return in_array($this->processingStatus, ['processed', 'ignored', 'failed'], true);
    }
}
