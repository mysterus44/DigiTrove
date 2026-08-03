<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\AuthorizesAnalyticsDashboard;
use App\Services\Analytics\Read\AnalyticsProductQuery;
use App\Services\Analytics\Read\Data\AnalyticsProductResult;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

final class AnalyticsProductTable extends Widget
{
    use AuthorizesAnalyticsDashboard;
    use InteractsWithPageFilters;

    protected string $view = 'filament.widgets.analytics-product-table';

    protected int|string|array $columnSpan = 'full';

    public int $page = 1;

    public int $perPage = 30;

    public function result(): ?AnalyticsProductResult
    {
        try {
            return app(AnalyticsProductQuery::class)->get(
                $this->currency(),
                $this->from(),
                $this->to(),
                $this->sort(),
                $this->page,
                $this->perPage,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    public function updatedPageFilters(): void
    {
        $this->page = 1;
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    public function nextPage(): void
    {
        $result = $this->result();
        $this->page = min($result?->lastPage ?? 1, $this->page + 1);
    }

    public function formatInteger(int $value): string
    {
        return number_format($value, 0, ',', ' ');
    }

    private function currency(): string
    {
        return is_string($this->pageFilters['currency'] ?? null) ? $this->pageFilters['currency'] : 'XOF';
    }

    private function sort(): string
    {
        return is_string($this->pageFilters['product_sort'] ?? null) ? $this->pageFilters['product_sort'] : 'revenue_desc';
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
