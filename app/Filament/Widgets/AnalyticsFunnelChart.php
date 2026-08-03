<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\AuthorizesAnalyticsDashboard;
use App\Services\Analytics\Read\AnalyticsFunnelQuery;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

final class AnalyticsFunnelChart extends ChartWidget
{
    use AuthorizesAnalyticsDashboard;
    use InteractsWithPageFilters;

    protected ?string $heading = 'Évolution du tunnel';

    protected ?string $description = 'Volumes quotidiens agrégés et non cohortés.';

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
            $result = app(AnalyticsFunnelQuery::class)->get($this->from(), $this->to());
        } catch (\Throwable) {
            return ['datasets' => [], 'labels' => []];
        }

        return [
            'datasets' => [
                $this->dataset('Sessions', 'sessions', '#2563eb', $result->dailySeries),
                $this->dataset('Vues produit', 'productViews', '#0f766e', $result->dailySeries),
                $this->dataset('Checkouts', 'checkouts', '#b45309', $result->dailySeries),
                $this->dataset('Achats', 'purchases', '#be123c', $result->dailySeries),
            ],
            'labels' => array_column($result->dailySeries, 'day'),
        ];
    }

    /**
     * @param  list<object>  $series
     * @return array<string, mixed>
     */
    private function dataset(string $label, string $property, string $color, array $series): array
    {
        return [
            'label' => $label,
            'data' => array_column($series, $property),
            'borderColor' => $color,
            'backgroundColor' => $color,
            'spanGaps' => false,
        ];
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
