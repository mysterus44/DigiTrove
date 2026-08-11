{{-- À VALIDER (P6-D1, D-058). Écrit hors environnement de test — à rendre/prouver dès qu'un
     environnement PHP 8.4 + Filament est disponible. Gouvernance de la POLITIQUE seulement :
     aucun affilié, code, touche, commission ou payout ici. --}}
<x-filament-panels::page>
    @if ($this->unavailable)
        <x-filament::section>
            <p class="text-sm text-danger-600">Gouvernance d'affiliation momentanément indisponible.</p>
        </x-filament::section>
    @endif

    @if ($this->notice !== null)
        <x-filament::section>
            <p class="text-sm text-primary-600">{{ $this->notice }}</p>
        </x-filament::section>
    @endif

    <x-filament::section heading="Politique en vigueur">
        @php($current = $this->current())
        @if ($current === null)
            <p class="text-sm text-gray-500">Aucune politique n'est publiée. Le programme n'est pas actif.</p>
        @else
            <dl class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-3">
                <div><dt class="text-gray-500">Version</dt><dd>{{ $current->version }}</dd></div>
                <div><dt class="text-gray-500">Commission</dt><dd>{{ $current->commissionPercentageLabel() }}</dd></div>
                <div><dt class="text-gray-500">Fenêtre d'attribution</dt><dd>{{ $current->attributionWindowDays }} j</dd></div>
                <div><dt class="text-gray-500">Délai payable</dt><dd>{{ $current->payableDelayDays }} j</dd></div>
                <div><dt class="text-gray-500">Seuil de versement</dt><dd>{{ $current->payoutThresholdLabel() }}</dd></div>
                <div><dt class="text-gray-500">En vigueur depuis</dt><dd>{{ $current->effectiveFrom }}</dd></div>
            </dl>
        @endif
    </x-filament::section>

    <x-filament::section heading="Nouveau brouillon (version {{ $this->nextVersion() }})">
        {{-- Entrées structurées uniquement : ni date d'effet, ni modèle d'attribution, ni
             versement automatique. La base revalide chaque valeur. --}}
        <form wire:submit="createDraft" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="text-sm">Fenêtre d'attribution (jours)
                <input type="number" min="1" max="365" wire:model="attributionWindowDays" class="mt-1 block w-full rounded border-gray-300" />
            </label>
            <label class="text-sm">Commission (points de base, 1500 = 15 %)
                <input type="number" min="0" max="5000" wire:model="commissionBps" class="mt-1 block w-full rounded border-gray-300" />
            </label>
            <label class="text-sm">Délai payable (jours)
                <input type="number" min="0" max="365" wire:model="payableDelayDays" class="mt-1 block w-full rounded border-gray-300" />
            </label>
            <label class="text-sm">Seuil de versement (unités mineures)
                <input type="number" min="0" wire:model="payoutThresholdMinor" class="mt-1 block w-full rounded border-gray-300" />
            </label>
            <label class="text-sm">Devise (ISO 3 lettres)
                <input type="text" maxlength="3" wire:model="payoutCurrency" class="mt-1 block w-full rounded border-gray-300 uppercase" />
            </label>
            <div class="flex items-end">
                <x-filament::button type="submit">Créer le brouillon</x-filament::button>
            </div>
        </form>
    </x-filament::section>

    <x-filament::section heading="Historique des politiques">
        <ul class="divide-y divide-gray-100">
            @forelse ($this->history() as $policy)
                <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                    <span>
                        <strong>v{{ $policy->version }}</strong> · {{ $policy->status }} ·
                        {{ $policy->commissionPercentageLabel() }} · {{ $policy->attributionWindowDays }} j ·
                        seuil {{ $policy->payoutThresholdLabel() }}
                    </span>
                    <span class="flex gap-2">
                        @if ($policy->status === 'draft')
                            <x-filament::button size="sm" color="gray" wire:click="updateDraft({{ $policy->id }})">
                                Appliquer les valeurs du formulaire
                            </x-filament::button>
                            <x-filament::button size="sm" wire:click="publish({{ $policy->id }})">
                                Publier
                            </x-filament::button>
                        @endif
                    </span>
                </li>
            @empty
                <li class="py-2 text-sm text-gray-500">Aucune politique enregistrée.</li>
            @endforelse
        </ul>

        <div class="mt-3 flex gap-2">
            <x-filament::button size="sm" color="gray" wire:click="previousPage">Précédent</x-filament::button>
            <x-filament::button size="sm" color="gray" wire:click="nextPage">Suivant</x-filament::button>
        </div>
    </x-filament::section>
</x-filament-panels::page>
