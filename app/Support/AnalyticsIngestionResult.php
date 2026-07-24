<?php

declare(strict_types=1);

namespace App\Support;

final readonly class AnalyticsIngestionResult
{
    public function __construct(
        public string $visitorId,
        public string $sessionId,
    ) {}
}
