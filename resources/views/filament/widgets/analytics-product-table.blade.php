@php($result = $this->result())

<x-filament-widgets::widget class="fi-analytics-product-table">
    <x-filament::section heading="Détail des produits">
        <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">
            Les vues sont globales ; les achats et revenus utilisent la devise sélectionnée.
        </p>
        <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">
            Les montants sont affichés en unités mineures de la devise sélectionnée. Aucune conversion de devise n’est appliquée.
        </p>

        @if ($result === null)
            <p class="text-sm text-gray-600 dark:text-gray-300">Les données ne peuvent pas être chargées.</p>
        @elseif ($result->rows === [])
            <p class="text-sm text-gray-600 dark:text-gray-300">Aucune donnée produit calculée pour cette période.</p>
        @else
            @if ($result->missingDays !== [])
                <p class="mb-3 text-sm text-amber-700 dark:text-amber-300">
                    {{ count($result->missingDays) }} jour(s) non calculé(s). Les zéros ne sont pas inventés.
                </p>
            @endif

            @if ($result->currentDayProvisional)
                <p class="mb-3 text-sm text-amber-700 dark:text-amber-300">Le jour UTC courant est provisoire.</p>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="px-3 py-2 font-medium">Produit</th>
                            <th class="px-3 py-2 text-right font-medium">Vues globales</th>
                            <th class="px-3 py-2 text-right font-medium">Achats {{ $result->currency }}</th>
                            <th class="px-3 py-2 text-right font-medium">Revenu {{ $result->currency }} — unités mineures</th>
                            <th class="px-3 py-2 text-right font-medium">Moyenne par achat {{ $result->currency }} — unités mineures</th>
                            <th class="px-3 py-2 text-right font-medium">Ajouts au panier</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($result->rows as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="whitespace-nowrap px-3 py-2">{{ $row->label }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $this->formatInteger($row->views) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $this->formatInteger($row->purchases) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $this->formatInteger($row->revenueMinor) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ $row->averageRevenuePerPurchaseMinor === null ? 'Indisponible' : $this->formatInteger($row->averageRevenuePerPurchaseMinor) }}
                                </td>
                                <td class="px-3 py-2 text-right">Non suivi</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex items-center justify-between gap-3">
                <x-filament::button color="gray" size="sm" icon="heroicon-o-chevron-left" wire:click="previousPage" :disabled="$result->page <= 1">
                    Précédent
                </x-filament::button>
                <span class="text-sm text-gray-600 dark:text-gray-300">Page {{ $result->page }} sur {{ $result->lastPage }}</span>
                <x-filament::button color="gray" size="sm" icon="heroicon-o-chevron-right" icon-position="after" wire:click="nextPage" :disabled="$result->page >= $result->lastPage">
                    Suivant
                </x-filament::button>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
