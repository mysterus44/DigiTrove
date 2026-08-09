<x-filament-panels::page>
    @if ($this->unavailable)
        <x-filament::section>
            <p class="text-sm text-danger-600">Données CRM momentanément indisponibles.</p>
        </x-filament::section>
    @endif

    @if ($this->notice !== null)
        <x-filament::section>
            <p class="text-sm text-primary-600">{{ $this->notice }}</p>
        </x-filament::section>
    @endif

    <x-filament::section heading="Demander un export">
        <p class="mb-3 text-xs text-gray-500">
            Un export est un artefact <strong>privé, nominatif et audité</strong> : il
            n'est téléchargeable que par l'administrateur qui l'a demandé, et il expire.
            Un export n'est <strong>pas</strong> une autorisation d'envoi.
        </p>

        <x-filament::button type="button" wire:click="exportContacts"
            wire:confirm="Demander un export CSV de tous les contacts CRM ?">
            Exporter les contacts
        </x-filament::button>

        <div class="mt-4 flex flex-wrap items-end gap-3 border-t pt-4">
            <div>
                <label for="crm-export-segment" class="text-sm font-medium">Segment</label>
                <select id="crm-export-segment" wire:model="segmentId"
                    class="mt-1 rounded-lg border-gray-300 text-sm">
                    <option value="">Choisir un segment…</option>
                    @foreach ($this->segments() as $segment)
                        <option value="{{ $segment['segment_id'] }}">
                            #{{ $segment['segment_id'] }} — {{ $segment['name'] }}
                        </option>
                    @endforeach
                </select>
                {{-- The generation is frozen when the export is CREATED, so a rebuild
                     started afterwards can never leak into the produced file. --}}
                <p class="mt-1 text-xs text-gray-500">
                    La génération publiée courante est <strong>figée à la création</strong>
                    de l'export. Une reconstruction ultérieure ne modifie pas le fichier.
                </p>
            </div>

            <x-filament::button type="button" wire:click="exportSegmentMembers"
                wire:confirm="Demander un export CSV des membres courants de ce segment ?">
                Exporter les membres courants
            </x-filament::button>
        </div>
    </x-filament::section>

    <x-filament::section heading="Historique">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left">
                    <th class="py-2">Export</th>
                    <th class="py-2">Type</th>
                    <th class="py-2">Statut</th>
                    <th class="py-2">Lignes</th>
                    <th class="py-2">Demandé le</th>
                    <th class="py-2">Expire le</th>
                    <th class="py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->exports() as $export)
                    <tr wire:key="crm-export-{{ $export['export_id'] }}" class="border-t">
                        <td class="py-2">#{{ $export['export_id'] }}</td>
                        <td class="py-2">{{ $this->kindLabels()[$export['kind']] ?? $export['kind'] }}</td>
                        <td class="py-2">{{ $this->statusLabels()[$export['status']] ?? $export['status'] }}</td>
                        {{-- NULL is not zero: an unfinished export has no row count. --}}
                        <td class="py-2">{{ $export['row_count'] ?? '—' }}</td>
                        <td class="py-2">{{ $export['created_at'] }}</td>
                        <td class="py-2">{{ $export['expires_at'] }}</td>
                        <td class="py-2">
                            {{-- The storage path is NEVER rendered: the link carries the
                                 export id only, and the server re-decides on every hit. --}}
                            @if ($this->downloadable($export))
                                <a href="{{ $this->downloadUrl($export['export_id']) }}"
                                    class="text-primary-600 underline">Télécharger</a>
                            @else
                                <span class="text-gray-400">Indisponible</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-4 text-gray-500">Aucun export.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
