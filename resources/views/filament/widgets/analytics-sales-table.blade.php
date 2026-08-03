@php($result = $this->result())

<x-filament-widgets::widget class="fi-analytics-sales-table">
    <x-filament::section heading="Détail des ventes">
        @if ($result === null)
            <p class="text-sm text-gray-600 dark:text-gray-300">Les données ne peuvent pas être chargées.</p>
        @elseif ($result->items === [])
            <p class="text-sm text-gray-600 dark:text-gray-300">Aucune donnée calculée pour cette période.</p>
        @else
            @if ($result->missingDays !== [])
                <p class="mb-3 text-sm text-amber-700 dark:text-amber-300">
                    {{ count($result->missingDays) }} jour(s) non calculé(s). Les zéros ne sont pas inventés.
                </p>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="px-3 py-2 font-medium">Jour</th>
                            <th class="px-3 py-2 font-medium">Devise</th>
                            <th class="px-3 py-2 text-right font-medium">Commandes</th>
                            <th class="px-3 py-2 text-right font-medium">Brut</th>
                            <th class="px-3 py-2 text-right font-medium">Remboursements</th>
                            <th class="px-3 py-2 text-right font-medium">Net</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($result->items as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="whitespace-nowrap px-3 py-2">{{ $row->day }}</td>
                                <td class="px-3 py-2">{{ $row->currency }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $this->formatInteger($row->ordersCount) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $this->formatInteger($row->grossRevenueMinor) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $this->formatInteger($row->refundsMinor) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $this->formatInteger($row->netRevenueMinor) }}</td>
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
