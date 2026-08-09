<x-filament-panels::page>
    @if ($this->unavailable)
        <x-filament::section>
            <p class="text-sm text-danger-600">Données CRM momentanément indisponibles.</p>
        </x-filament::section>
    @endif

    {{-- Exact e-mail search. wire:model (deferred) keeps the address in Livewire state
         only: it is never placed in the URL, so it cannot leak through history,
         referrers or access logs. --}}
    <x-filament::section heading="Recherche">
        <form wire:submit="search" class="flex flex-wrap items-end gap-3">
            <div class="grow">
                <label for="crm-email" class="text-sm font-medium">Adresse e-mail exacte</label>
                <input id="crm-email" type="email" wire:model="email" autocomplete="off"
                    class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                <p class="mt-1 text-xs text-gray-500">
                    Correspondance <strong>exacte</strong> uniquement : aucune recherche partielle ni approximative.
                    Un contact anonymisé ne peut jamais être retrouvé par son ancienne adresse.
                </p>
            </div>
            <x-filament::button type="submit">Rechercher</x-filament::button>
            <x-filament::button type="button" color="gray" wire:click="clearSearch">Effacer</x-filament::button>
        </form>

        @if ($this->searchMissed)
            <p class="mt-3 text-sm text-warning-600">Aucun contact ne correspond exactement à cette adresse.</p>
        @endif
    </x-filament::section>

    <x-filament::section heading="Contacts">
        <div class="flex flex-wrap gap-3">
            <select wire:model.live="statusFilter" wire:change="resetPaging" class="rounded-lg border-gray-300 text-sm">
                <option value="">Tous les statuts</option>
                @foreach ($this->statusOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>

            <select wire:model.live="originFilter" wire:change="resetPaging" class="rounded-lg border-gray-300 text-sm">
                <option value="">Toutes les origines</option>
                @foreach ($this->originOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <table class="mt-4 w-full text-sm">
            <thead>
                <tr class="text-left">
                    <th class="py-2">ID</th>
                    <th class="py-2">E-mail</th>
                    <th class="py-2">Statut</th>
                    <th class="py-2">Origine</th>
                    <th class="py-2">Créé le</th>
                    <th class="py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->contacts() as $contact)
                    <tr wire:key="crm-contact-{{ $contact['contact_id'] }}" class="border-t">
                        <td class="py-2">{{ $contact['contact_id'] }}</td>
                        <td class="py-2">{{ $this->emailLabel($contact['email']) }}</td>
                        <td class="py-2">{{ $this->statusOptions()[$contact['status']] ?? $contact['status'] }}</td>
                        <td class="py-2">{{ $this->originOptions()[$contact['origin']] ?? $contact['origin'] }}</td>
                        <td class="py-2">{{ $contact['created_at'] }}</td>
                        <td class="py-2">
                            <x-filament::button size="xs" wire:click="select({{ $contact['contact_id'] }})">
                                Détail
                            </x-filament::button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-4 text-gray-500">Aucun contact.</td></tr>
                @endforelse
            </tbody>
        </table>

        {{-- Keyset paging: no OFFSET anywhere. --}}
        <div class="mt-4 flex gap-2">
            <x-filament::button size="sm" color="gray" wire:click="previousPage">Précédent</x-filament::button>
            <x-filament::button size="sm" color="gray" wire:click="nextPage">Suivant</x-filament::button>
        </div>
    </x-filament::section>

    @php($contact = $this->selectedContact())

    @if ($contact !== null)
        <x-filament::section heading="Identité">
            <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="font-medium">Contact</dt><dd>#{{ $contact['contact_id'] }}</dd>
                <dt class="font-medium">E-mail</dt><dd>{{ $this->emailLabel($contact['email']) }}</dd>
                <dt class="font-medium">Statut</dt><dd>{{ $contact['status'] }}</dd>
                <dt class="font-medium">Origine</dt><dd>{{ $contact['origin'] }}</dd>
                <dt class="font-medium">Créé le</dt><dd>{{ $contact['created_at'] }}</dd>
                @if ($contact['anonymized_at'] !== null)
                    <dt class="font-medium">Anonymisé le</dt><dd>{{ $contact['anonymized_at'] }}</dd>
                @endif
            </dl>
        </x-filament::section>

        <x-filament::section heading="Consentement marketing">
            <p class="mb-3 text-xs text-gray-500">
                Le <strong>consentement</strong> n'est pas l'appartenance à un segment, et
                l'<strong>appartenance à un segment</strong> n'est pas une autorisation d'envoi.
                Aucune action d'envoi n'existe ici.
            </p>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left">
                        <th class="py-2">Action</th><th class="py-2">Canal</th><th class="py-2">Finalité</th>
                        <th class="py-2">Source</th><th class="py-2">Politique</th><th class="py-2">Enregistré le</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->consentEvents() as $event)
                        <tr wire:key="crm-consent-{{ $event['event_id'] }}" class="border-t">
                            <td class="py-2">{{ $event['action'] }}</td>
                            <td class="py-2">{{ $event['channel'] }}</td>
                            <td class="py-2">{{ $event['purpose'] }}</td>
                            <td class="py-2">{{ $event['source'] }}</td>
                            <td class="py-2">{{ $event['policy_version'] }}</td>
                            <td class="py-2">{{ $event['recorded_at'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-4 text-gray-500">Aucun évènement de consentement.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section heading="Faits commerce">
            {{-- One row PER CURRENCY. There is deliberately no total line: summing
                 across currencies would require an FX rate and is never done. --}}
            <p class="mb-3 text-xs text-gray-500">
                Une ligne par devise. Aucun total multi-devises n'est calculé.
            </p>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left">
                        <th class="py-2">Devise</th><th class="py-2">Commandes acquises</th>
                        <th class="py-2">Brut</th><th class="py-2">Remboursé</th><th class="py-2">Net</th>
                        <th class="py-2">Première</th><th class="py-2">Dernière</th><th class="py-2">Rafraîchi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->commerceRollups() as $rollup)
                        <tr wire:key="crm-rollup-{{ $rollup['currency'] }}" class="border-t">
                            <td class="py-2 font-medium">{{ $rollup['currency'] }}</td>
                            <td class="py-2">{{ $rollup['acquired_orders_count'] }}</td>
                            <td class="py-2">{{ $this->money($rollup['gross_revenue_minor'], $rollup['currency']) }}</td>
                            <td class="py-2">{{ $this->money($rollup['refunded_amount_minor'], $rollup['currency']) }}</td>
                            <td class="py-2">{{ $this->money($rollup['net_revenue_minor'], $rollup['currency']) }}</td>
                            <td class="py-2">{{ $rollup['first_acquired_at'] }}</td>
                            <td class="py-2">{{ $rollup['last_acquired_at'] }}</td>
                            <td class="py-2">{{ $rollup['refreshed_at'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-4 text-gray-500">Aucune acquisition.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section heading="Segments actuels">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left">
                        <th class="py-2">Segment</th><th class="py-2">Nom</th>
                        <th class="py-2">Génération</th><th class="py-2">Publiée le</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->segmentMemberships() as $membership)
                        <tr wire:key="crm-membership-{{ $membership['segment_id'] }}" class="border-t">
                            <td class="py-2">#{{ $membership['segment_id'] }}</td>
                            <td class="py-2">{{ $membership['name'] }}</td>
                            <td class="py-2">#{{ $membership['generation_id'] }}</td>
                            <td class="py-2">{{ $membership['generation_published_at'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-4 text-gray-500">Aucune appartenance courante.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>
    @endif
</x-filament-panels::page>
