<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\CrmOrderAttributionReason;
use App\Enums\CrmOrderAttributionSource;
use App\Enums\CrmOrderAttributionStatus;

final readonly class CrmOrderAttributionResult
{
    public function __construct(
        public int $orderId,
        public CrmOrderAttributionStatus $status,
        public ?CrmOrderAttributionReason $reason,
        public ?string $contactPublicId,
        public ?CrmOrderAttributionSource $source,
    ) {}
}
