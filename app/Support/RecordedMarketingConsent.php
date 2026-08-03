<?php

declare(strict_types=1);

namespace App\Support;

final readonly class RecordedMarketingConsent
{
    public function __construct(
        public string $eventPublicId,
        public bool $inserted,
    ) {}
}
