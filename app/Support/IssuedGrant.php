<?php

declare(strict_types=1);

namespace App\Support;

use SensitiveParameter;

/**
 * One freshly issued download grant, carried in worker memory only (P4-C, D-035).
 *
 * The raw token exists ONLY here, on the way to the synchronous Mailable. It is
 * never persisted (only its SHA-256 digest is), never logged and never placed in
 * an exception or a queue payload.
 */
final readonly class IssuedGrant
{
    public function __construct(
        public int $grantId,
        public string $grantPublicId,
        public int $orderItemId,
        public int $productFileId,
        #[SensitiveParameter] public string $rawToken,
        public string $fileName,
    ) {}
}
