{{-- P6-D1.1 (D-059). Administration du cycle de vie affilié. Aucune commission, aucun
     versement, aucune attribution, aucun lien de parrainage : la page ne connaît que
     l'état, les codes et l'historique. Tout passe par les autorités PostgreSQL. --}}
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

    <x-filament::section heading="Enregistrer une candidature">
        {{-- Identifiant de compte exact : `users` n'a pas d'identifiant public et le rôle
             runtime ne peut pas lire `users.email`. L'autorité décide de l'éligibilité. --}}
        <div class="flex flex-wrap items-end gap-3">
            <label class="text-sm">
                <span class="block text-gray-500">Compte client (identifiant)</span>
                <input type="number" min="1" wire:model="applicantUserId" class="mt-1 rounded border-gray-300 text-sm" />
            </label>
            <x-filament::button wire:click="submitApplication">Enregistrer la candidature</x-filament::button>
        </div>
    </x-filament::section>

    <x-filament::section heading="Affiliés">
        <div class="mb-3 flex flex-wrap gap-2 text-sm">
            @foreach ([null => 'Tous', 'pending' => 'En attente', 'active' => 'Actifs', 'rejected' => 'Refusés', 'suspended' => 'Suspendus', 'closed' => 'Clôturés'] as $value => $label)
                <button type="button" wire:click="$set('statusFilter', {{ $value === null ? 'null' : "'".$value."'" }})"
                        class="rounded px-2 py-1 {{ $this->statusFilter === $value ? 'bg-primary-600 text-white' : 'bg-gray-100' }}">{{ $label }}</button>
            @endforeach
        </div>

        <table class="w-full text-left text-sm">
            <thead class="text-gray-500">
                <tr><th>#</th><th>Compte</th><th>Statut</th><th>Code actif</th><th>Candidature</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($this->affiliates() as $affiliate)
                    <tr class="border-t">
                        <td>{{ $affiliate->id }}</td>
                        <td>{{ $affiliate->userId }}</td>
                        <td>{{ $affiliate->status }}</td>
                        {{-- Valeur seulement : la liste ne détient aucun jeton de rotation. --}}
                        <td>{{ $affiliate->activeCode ?? '—' }}</td>
                        <td>{{ $affiliate->appliedAt ?? '—' }}</td>
                        <td><x-filament::button size="xs" wire:click="open({{ $affiliate->id }})">Ouvrir</x-filament::button></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-3 text-gray-500">Aucun affilié pour ce filtre.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    @if ($this->affiliateId !== null)
        {{-- ÉTAT OBSERVÉ — figé à l'ouverture. Ce qui est affiché et ce qui sera envoyé
             viennent de la même capture ; c'est ce qui rend la rotation vérifiable. --}}
        <x-filament::section heading="Affilié #{{ $this->affiliateId }} — état observé">
            @if ($this->snapshotStale)
                <p class="mb-3 text-sm text-danger-600">
                    Cet écran ne reflète plus la base. Actualisez avant d'agir de nouveau.
                </p>
            @endif

            <dl class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-3">
                <div><dt class="text-gray-500">Statut</dt><dd>{{ $this->shownStatus ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">Code actif</dt><dd>{{ $this->shownActiveCode ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">Candidature</dt><dd>{{ $this->shownAppliedAt ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">Approbation</dt><dd>{{ $this->shownApprovedAt ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">Refus</dt><dd>{{ $this->shownRejectedAt ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">Suspension</dt><dd>{{ $this->shownSuspendedAt ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">Clôture</dt><dd>{{ $this->shownClosedAt ?? '—' }}</dd></div>
            </dl>

            {{-- Les horodatages ci-dessus sont des marqueurs cumulatifs, pas un historique :
                 un refus reste visible après une approbation ultérieure. Le ledger, plus
                 bas, est la seule chronologie. --}}

            <div class="mt-4 flex flex-wrap gap-2">
                <x-filament::button color="gray" wire:click="refreshSnapshot">Actualiser</x-filament::button>

                @foreach ($this->availableActions() as $action)
                    @if ($action === 'approve')
                        <x-filament::button wire:click="approve">Approuver</x-filament::button>
                    @elseif ($action === 'reject')
                        <x-filament::button color="danger" wire:click="reject">Refuser</x-filament::button>
                    @elseif ($action === 'reapply')
                        <x-filament::button wire:click="reapply">Nouvelle candidature</x-filament::button>
                    @elseif ($action === 'rotate')
                        <x-filament::button wire:click="rotateCode">Faire tourner le code</x-filament::button>
                    @elseif ($action === 'suspend')
                        <x-filament::button color="warning" wire:click="suspend">Suspendre</x-filament::button>
                    @elseif ($action === 'reactivate')
                        <x-filament::button wire:click="reactivate">Réactiver</x-filament::button>
                    @elseif ($action === 'close')
                        <x-filament::button color="danger" wire:click="closeAffiliate">Clôturer</x-filament::button>
                    @endif
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section heading="Historique du cycle de vie">
            <table class="w-full text-left text-sm">
                <thead class="text-gray-500"><tr><th>De</th><th>Vers</th><th>Événement</th><th>Acteur</th><th>Motif</th><th>Date</th></tr></thead>
                <tbody>
                    @forelse ($this->lifecycleHistory() as $event)
                        <tr class="border-t">
                            <td>{{ $event->from_status ?? '—' }}</td>
                            <td>{{ $event->to_status }}</td>
                            <td>{{ $event->event_kind }}</td>
                            <td>{{ $event->actor_user_id ?? '—' }}</td>
                            <td>{{ $event->reason_code ?? '—' }}</td>
                            <td>{{ $event->occurred_at }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-3 text-gray-500">Aucun événement.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section heading="Codes">
            {{-- Consultation seule : un code retiré reste réservé pour toujours, il ne peut
                 être ni réactivé, ni supprimé, ni modifié, ni choisi. --}}
            <table class="w-full text-left text-sm">
                <thead class="text-gray-500"><tr><th>Code</th><th>État</th><th>Désactivé le</th></tr></thead>
                <tbody>
                    @forelse ($this->codeHistory() as $code)
                        <tr class="border-t">
                            <td>{{ $code->code }}</td>
                            <td>{{ $code->is_active ? 'actif' : 'retiré' }}</td>
                            <td>{{ $code->deactivated_at ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-3 text-gray-500">Aucun code émis.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>
    @endif
</x-filament-panels::page>
