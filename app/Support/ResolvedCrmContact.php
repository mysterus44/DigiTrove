<?php

declare(strict_types=1);

namespace App\Support;

final readonly class ResolvedCrmContact
{
    public function __construct(
        public int $id,
        public string $publicId,
    ) {}
}
