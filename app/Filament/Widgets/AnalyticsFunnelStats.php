<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\AuthorizesAnalyticsDashboard;
use App\Services\Analytics\Read\AnalyticsFunnelQuery;
use App\Services\Analytics\Read\Data\AnalyticsFunnel;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

final class AnalyticsFunnelStats extends Widget
{
    use AuthorizesAnalyticsDashboard;
    use InteractsWithPageFilters;

    protected string $view = 'filament.widgets.analytics-funnel-stats';

    protected int|string|array $columnSpan = 'full';

    public function result(): ?AnalyticsFunnel
    {
        try {
            return app(AnalyticsFunnelQuery::class)->get($this->from(), $this->to());
        } catch (\Throwable) {
            return null;
        }
    }

    public function formatInteger(int $value): string
    {
        return number_format($value, 0, ',', ' ');
    }

    public function formatRatio(?string $value, bool $percentage = true): string
    {
        if ($value === null) {
            return 'Indisponible';
        }

        $numeric = (float) $value;

        return number_format($percentage ? $numeric * 100 : $numeric, 2, ',', ' ').($percentage ? ' %' : '');
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
