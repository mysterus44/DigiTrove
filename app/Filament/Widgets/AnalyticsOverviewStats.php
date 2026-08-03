<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\AuthorizesAnalyticsDashboard;
use App\Services\Analytics\Read\AnalyticsOverviewQuery;
use App\Services\Analytics\Read\Data\AnalyticsOverview;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class AnalyticsOverviewStats extends StatsOverviewWidget
{
    use AuthorizesAnalyticsDashboard;
    use InteractsWithPageFilters;

    protected ?string $heading = 'Vue d’ensemble';

    protected ?string $pollingInterval = null;

    /** @return array<Stat> */
    protected function getStats(): array
    {
        try {
            $overview = app(AnalyticsOverviewQuery::class)->get($this->from(), $this->to());
        } catch (\Throwable) {
            return [Stat::make('Analytique', 'Indisponible')->description('Les données ne peuvent pas être chargées.')];
        }

        if ($overview->coverageStart === null) {
            return [Stat::make('Vue d’ensemble', 'Aucune donnée calculée')->description('La période sélectionnée ne contient aucun rollup.')];
        }

        $description = $this->periodDescription($overview);
        $stats = [
            Stat::make('Visiteurs', $this->integer($overview->visitors))->description($description),
            Stat::make('Sessions', $this->integer($overview->sessions)),
            Stat::make('Vues produit', $this->integer($overview->productViews)),
            Stat::make('Ajouts au panier', $this->integer($overview->addToCarts)),
            Stat::make('Achats', $this->integer($overview->purchases)),
            Stat::make('Nouveaux clients', $this->integer($overview->newCustomers)),
        ];

        foreach ($overview->salesByCurrency as $summary) {
            $stats[] = Stat::make(
                'Ventes nettes '.$summary->currency,
                $this->integer($summary->netRevenueMinor).' unités mineures',
            )->description($this->integer($summary->ordersCount).' commande(s)');
        }

        return $stats;
    }

    private function from(): ?string
    {
        return is_string($this->pageFilters['from'] ?? null) ? $this->pageFilters['from'] : null;
    }

    private function to(): ?string
    {
        return is_string($this->pageFilters['to'] ?? null) ? $this->pageFilters['to'] : null;
    }

    private function integer(int $value): string
    {
        return number_format($value, 0, ',', ' ');
    }

    private function periodDescription(AnalyticsOverview $overview): string
    {
        if ($overview->currentDayProvisional) {
            return 'Période en cours, données provisoires.';
        }

        if ($overview->missingDays !== []) {
            return count($overview->missingDays).' jour(s) non calculé(s).';
        }

        return 'Période calculée.';
    }
}
