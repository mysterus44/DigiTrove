<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

final readonly class AuthorizedDownloadAttempt
{
    public function __construct(
        public string $grantPublicId,
        public string $rawAttemptToken,
        public CarbonImmutable $expiresAt,
    ) {}
}
