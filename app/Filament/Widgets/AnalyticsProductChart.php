<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\AuthorizesAnalyticsDashboard;
use App\Services\Analytics\Read\AnalyticsProductQuery;
use App\Services\Analytics\Read\Data\AnalyticsProductSort;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

final class AnalyticsProductChart extends ChartWidget
{
    use AuthorizesAnalyticsDashboard;
    use InteractsWithPageFilters;

    protected ?string $heading = 'Produits';

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'bar';
    }

    public function getDescription(): ?string
    {
        return $this->productSort() === AnalyticsProductSort::ProductIdAsc
            ? '10 premiers identifiants produit, avec leurs vues globales.'
            : 'Top 10 produits selon la métrique sélectionnée.';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        try {
            $sort = $this->productSort();
            $result = app(AnalyticsProductQuery::class)->get(
                $this->currency(),
                $this->from(),
                $this->to(),
                $sort->value,
                1,
                10,
            );
        } catch (\Throwable) {
            return ['datasets' => [], 'labels' => []];
        }

        [$label, $property, $color] = match ($sort) {
            AnalyticsProductSort::RevenueDesc => ['Revenu '.$result->currency.' — unités mineures', 'revenueMinor', '#0f766e'],
            AnalyticsProductSort::PurchasesDesc => ['Achats '.$result->currency, 'purchases', '#b45309'],
            AnalyticsProductSort::ViewsDesc => ['Vues globales', 'views', '#2563eb'],
            AnalyticsProductSort::ProductIdAsc => ['Vues globales — tri par identifiant', 'views', '#52525b'],
        };

        return [
            'datasets' => [[
                'label' => $label,
                'data' => array_column($result->rows, $property),
                'backgroundColor' => $color,
            ]],
            'labels' => array_column($result->rows, 'label'),
        ];
    }

    private function currency(): string
    {
        return is_string($this->pageFilters['currency'] ?? null) ? $this->pageFilters['currency'] : 'XOF';
    }

    private function sort(): string
    {
        return is_string($this->pageFilters['product_sort'] ?? null) ? $this->pageFilters['product_sort'] : 'revenue_desc';
    }

    private function productSort(): AnalyticsProductSort
    {
        return AnalyticsProductSort::tryFrom($this->sort()) ?? AnalyticsProductSort::RevenueDesc;
    }

    private function from(): ?string
    {
        return is_string($this->pageFilters['from'] ?? null) ? $this->pageFilters['from'] : null;
    }

    private function to(): ?string
    {
        return is_string($this->pageFilters['to'] ?? null) ? $this->pageFilters['to'] : null;
    }
}
