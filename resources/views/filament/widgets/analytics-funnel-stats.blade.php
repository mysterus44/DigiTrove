@php($result = $this->result())

<x-filament-widgets::widget class="fi-analytics-funnel-stats">
    <x-filament::section heading="Tunnel">
        @if ($result === null)
            <p class="text-sm text-gray-600 dark:text-gray-300">Les données ne peuvent pas être chargées.</p>
        @else
            @if ($result->missingDays !== [])
                <p class="mb-3 text-sm text-amber-700 dark:text-amber-300">
                    {{ count($result->missingDays) }} jour(s) non calculé(s). Les séries conservent ces trous.
                </p>
            @endif

            @if ($result->currentDayProvisional)
                <p class="mb-3 text-sm text-amber-700 dark:text-amber-300">Le jour UTC courant est provisoire.</p>
            @endif

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    'Visiteurs' => $result->visitors,
                    'Sessions' => $result->sessions,
                    'Vues produit' => $result->productViews,
                    'Checkouts' => $result->checkouts,
                    'Achats' => $result->purchases,
                    'Nouveaux clients' => $result->newCustomers,
                ] as $label => $value)
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="text-lg font-semibold tabular-nums">{{ $this->formatInteger($value) }}</p>
                    </div>
                @endforeach
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Ajouts au panier</p>
                    <p class="text-lg font-semibold">Non suivi</p>
                </div>
            </div>

            <h3 class="mt-6 text-sm font-semibold">Ratios agrégés — non cohortés</h3>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div><p class="text-xs text-gray-500 dark:text-gray-400">Vues par session</p><p class="font-medium">{{ $this->formatRatio($result->ratios->viewsPerSession, false) }}</p></div>
                <div><p class="text-xs text-gray-500 dark:text-gray-400">Checkout par session</p><p class="font-medium">{{ $this->formatRatio($result->ratios->checkoutRatePerSession) }}</p></div>
                <div><p class="text-xs text-gray-500 dark:text-gray-400">Achat par session</p><p class="font-medium">{{ $this->formatRatio($result->ratios->purchaseRatePerSession) }}</p></div>
                <div><p class="text-xs text-gray-500 dark:text-gray-400">Achat par checkout</p><p class="font-medium">{{ $this->formatRatio($result->ratios->purchasePerCheckout) }}</p></div>
                <div><p class="text-xs text-gray-500 dark:text-gray-400">Nouveaux clients par achat</p><p class="font-medium">{{ $this->formatRatio($result->ratios->newCustomerShare) }}</p></div>
            </div>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Ratios descriptifs, non causaux et non plafonnés.</p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
