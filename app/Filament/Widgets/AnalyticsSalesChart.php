<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\AuthorizesAnalyticsDashboard;
use App\Services\Analytics\Read\AnalyticsSalesQuery;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

final class AnalyticsSalesChart extends ChartWidget
{
    use AuthorizesAnalyticsDashboard;
    use InteractsWithPageFilters;

    protected ?string $heading = 'Ventes';

    protected ?string $description = 'Revenu net quotidien, en unités mineures.';

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'line';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        try {
            $currency = $this->currency();
            $result = app(AnalyticsSalesQuery::class)->get($currency, $this->from(), $this->to());
        } catch (\Throwable) {
            return ['datasets' => [], 'labels' => []];
        }

        return [
            'datasets' => [[
                'label' => 'Ventes nettes '.$currency,
                'data' => array_column($result->series, 'netRevenueMinor'),
                'borderColor' => '#0f766e',
                'backgroundColor' => '#99f6e4',
            ]],
            'labels' => array_column($result->series, 'day'),
        ];
    }

    private function currency(): string
    {
        return is_string($this->pageFilters['currency'] ?? null) ? $this->pageFilters['currency'] : 'XOF';
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
