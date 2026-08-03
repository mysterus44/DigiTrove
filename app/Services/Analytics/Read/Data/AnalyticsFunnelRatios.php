<?php

namespace App\Services\Analytics\Read\Data;

final readonly class AnalyticsFunnelRatios
{
    public function __construct(
        public ?string $viewsPerSession,
        public ?string $checkoutRatePerSession,
        public ?string $purchaseRatePerSession,
        public ?string $purchasePerCheckout,
        public ?string $newCustomerShare,
    ) {}
}
