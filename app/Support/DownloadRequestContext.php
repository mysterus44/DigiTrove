<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

final readonly class DownloadRequestContext
{
    public function __construct(
        public ?string $ipHash,
        public ?int $ipHashKeyVersion,
        public ?string $userAgent,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $ip = $request->ip();
        $ipHash = is_string($ip) && $ip !== ''
            ? hash_hmac('sha256', $ip, DeliveryConfig::ipHashKey())
            : null;

        $agent = $request->userAgent();
        $agent = is_string($agent) ? trim($agent) : null;
        if ($agent === '') {
            $agent = null;
        }
        if ($agent !== null) {
            $agent = mb_substr($agent, 0, 500);
        }

        return new self(
            ipHash: $ipHash,
            ipHashKeyVersion: $ipHash === null ? null : DeliveryConfig::ipHashKeyVersion(),
            userAgent: $agent,
        );
    }
}
