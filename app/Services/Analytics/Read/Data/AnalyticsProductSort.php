<?php

namespace App\Services\Analytics\Read\Data;

enum AnalyticsProductSort: string
{
    case RevenueDesc = 'revenue_desc';
    case PurchasesDesc = 'purchases_desc';
    case ViewsDesc = 'views_desc';
    case ProductIdAsc = 'product_id_asc';

    public function orderBy(): string
    {
        return match ($this) {
            self::RevenueDesc => 'revenue_minor DESC, product_id ASC',
            self::PurchasesDesc => 'purchases DESC, product_id ASC',
            self::ViewsDesc => 'views DESC, product_id ASC',
            self::ProductIdAsc => 'product_id ASC',
        };
    }
}
