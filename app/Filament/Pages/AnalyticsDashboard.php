<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AnalyticsOverviewStats;
use App\Filament\Widgets\AnalyticsSalesChart;
use App\Filament\Widgets\AnalyticsSalesTable;
use App\Services\Analytics\Read\AnalyticsOverviewQuery;
use App\Support\AnalyticsDashboardConfig;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;

final class AnalyticsDashboard extends Dashboard
{
    use HasFiltersForm;

    protected static string $routePath = 'analytics';

    protected static ?string $navigationLabel = 'Analytique';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Analytique';

    public static function canAccess(): bool
    {
        return AnalyticsDashboardConfig::available()
            && Gate::allows('viewGlobalAnalytics');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    public function persistsFiltersInSession(): bool
    {
        return false;
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('from')
                ->label('Du')
                ->native(false)
                ->maxDate(now('UTC')->toDateString())
                ->default(now('UTC')->subDays(AnalyticsDashboardConfig::defaultDays() - 1)->toDateString())
                ->required(),
            DatePicker::make('to')
                ->label('Au')
                ->native(false)
                ->maxDate(now('UTC')->toDateString())
                ->default(now('UTC')->toDateString())
                ->required(),
            Select::make('currency')
                ->label('Devise')
                ->options(fn (): array => $this->currencyOptions())
                ->default('XOF')
                ->required(),
        ]);
    }

    /** @return array<class-string> */
    public function getWidgets(): array
    {
        return [
            AnalyticsOverviewStats::class,
            AnalyticsSalesChart::class,
            AnalyticsSalesTable::class,
        ];
    }

    /** @return array<string, string> */
    private function currencyOptions(): array
    {
        try {
            $currencies = array_keys(app(AnalyticsOverviewQuery::class)->get()->salesByCurrency);
        } catch (\Throwable) {
            return ['XOF' => 'XOF'];
        }

        if ($currencies === []) {
            return ['XOF' => 'XOF'];
        }

        return array_combine($currencies, $currencies);
    }
}
