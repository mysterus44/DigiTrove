{{-- P6-D4 (D-069). Administration des versements affiliés.

     ⚠️ CET ÉCRAN NE DÉPLACE AUCUN ARGENT. Marquer un versement « payé » enregistre qu'un
     virement a eu lieu AILLEURS, contre une référence administrative. Aucun fournisseur,
     aucune coordonnée bancaire, aucun numéro Mobile Money — D-057 §12.

     Les montants sont affichés en UNITÉS MINEURES avec leur devise explicite, jamais
     divisés par 100 : le XOF a un exposant 0 et aucune table d'exposants n'est auditée
     dans ce dépôt. Aucun total multi-devises n'est calculé nulle part. --}}
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

    @if ($this->snapshotStale)
        <x-filament::section>
            <p class="text-sm text-warning-600">
                Ce versement a changé depuis son affichage. Rechargez-le avant de décider à nouveau.
            </p>
            <x-filament::button class="mt-2" wire:click="refreshSnapshot">Recharger</x-filament::button>
        </x-filament::section>
    @endif

    <x-filament::section heading="À verser">
        {{-- Le solde payable vient du ledger, jamais du montant nominal de la commission :
             un remboursement partiel a pu le réduire depuis l'accrual. --}}
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500">
                    <th class="py-1">Affilié</th>
                    <th class="py-1">Devise</th>
                    <th class="py-1">Commissions</th>
                    <th class="py-1">Solde payable</th>
                    <th class="py-1">Seuil</th>
                    <th class="py-1"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->candidates() as $candidate)
                    <tr class="border-t">
                        <td class="py-1 font-mono text-xs">{{ $candidate->affiliate_public_id }}</td>
                        <td class="py-1">{{ $candidate->currency }}</td>
                        <td class="py-1">{{ $candidate->commission_count }}</td>
                        <td class="py-1">{{ $candidate->payable_total_minor }} {{ $candidate->currency }}</td>
                        <td class="py-1">
                            {{ $candidate->threshold_minor ?? '—' }}
                            @if ($candidate->threshold_minor !== null) {{ $candidate->currency }} @endif
                        </td>
                        <td class="py-1 text-right">
                            @if ($candidate->is_eligible)
                                <x-filament::button
                                    size="xs"
                                    wire:click="requestPayout({{ $candidate->affiliate_id }}, '{{ $candidate->currency }}')">
                                    Demander le versement
                                </x-filament::button>
                            @else
                                <span class="text-xs text-gray-500">Sous le seuil</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-2 text-gray-500">Aucun solde payable.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="Versements">
        <div class="mb-3 flex flex-wrap gap-2 text-sm">
            <select wire:model.live="statusFilter" class="rounded border-gray-300 text-sm">
                <option value="">Tous les états</option>
                <option value="requested">Demandés</option>
                <option value="approved">Approuvés</option>
                <option value="paid">Payés</option>
                <option value="rejected">Refusés</option>
                <option value="cancelled">Annulés</option>
            </select>
        </div>

        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500">
                    <th class="py-1">Versement</th>
                    <th class="py-1">Affilié</th>
                    <th class="py-1">État</th>
                    <th class="py-1">Montant</th>
                    <th class="py-1">Lignes</th>
                    <th class="py-1">Demandé le</th>
                    <th class="py-1"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->payouts() as $payout)
                    <tr class="border-t">
                        <td class="py-1 font-mono text-xs">{{ $payout->public_id }}</td>
                        <td class="py-1 font-mono text-xs">{{ $payout->affiliate_public_id }}</td>
                        <td class="py-1">{{ $payout->payout_status }}</td>
                        <td class="py-1">{{ $payout->amount_minor }} {{ $payout->currency }}</td>
                        <td class="py-1">{{ $payout->item_count }}</td>
                        <td class="py-1">{{ $payout->requested_at }}</td>
                        <td class="py-1 text-right">
                            <x-filament::button size="xs" wire:click="open({{ $payout->payout_id }})">Ouvrir</x-filament::button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-2 text-gray-500">Aucun versement.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    @if ($this->payoutId !== null && $this->shownStatus !== null)
        <x-filament::section heading="Versement ouvert">
            {{-- Rendu depuis la CAPTURE, jamais depuis une relecture : c'est ce qui rend le
                 compare-and-swap significatif. --}}
            <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="text-gray-500">Affilié</dt>
                <dd class="font-mono text-xs">{{ $this->shownAffiliatePublicId }}</dd>
                <dt class="text-gray-500">État</dt>
                <dd>{{ $this->shownStatus }}</dd>
                <dt class="text-gray-500">Montant</dt>
                <dd>{{ $this->shownAmountMinor }} {{ $this->shownCurrency }}</dd>
                <dt class="text-gray-500">Seuil retenu</dt>
                <dd>{{ $this->shownThresholdMinor }} {{ $this->shownCurrency }}</dd>
                <dt class="text-gray-500">Demandé par (compte)</dt>
                <dd>{{ $this->shownRequestedBy ?? '—' }}</dd>
                <dt class="text-gray-500">Approuvé par (compte)</dt>
                <dd>{{ $this->shownApprovedBy ?? '—' }}</dd>
                <dt class="text-gray-500">Référence administrative</dt>
                <dd>{{ $this->shownReference ?? '—' }}</dd>
            </dl>

            @if (in_array('markPaid', $this->availableActions(), true))
                {{-- La référence n'est PAS un moyen de paiement : un numéro de bordereau ou
                     une référence interne, jamais un compte bancaire ni un numéro Mobile
                     Money. Ceux-là exigeraient leur propre gate revu. --}}
                <div class="mt-3 flex flex-wrap items-end gap-3">
                    <label class="text-sm">
                        <span class="block text-gray-500">Référence administrative du virement</span>
                        <input type="text" maxlength="64" wire:model="administrativeReference"
                               class="mt-1 rounded border-gray-300 text-sm" />
                    </label>
                </div>
            @endif

            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($this->availableActions() as $action)
                    <x-filament::button size="sm" wire:click="{{ $action }}">
                        @switch($action)
                            @case('approve') Approuver @break
                            @case('reject') Refuser @break
                            @case('cancel') Annuler @break
                            @case('markPaid') Marquer payé @break
                        @endswitch
                    </x-filament::button>
                @endforeach
            </div>

            @if (in_array('approve', $this->availableActions(), true))
                <p class="mt-2 text-xs text-gray-500">
                    Un versement doit être approuvé par un administrateur différent de celui qui l'a demandé.
                </p>
            @endif
        </x-filament::section>

        <x-filament::section heading="Commissions réglées">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500">
                        <th class="py-1">Commission</th>
                        <th class="py-1">Commande</th>
                        <th class="py-1">Ligne</th>
                        <th class="py-1">Montant</th>
                        <th class="py-1">État</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->items() as $item)
                        <tr class="border-t">
                            <td class="py-1 font-mono text-xs">{{ $item->commission_public_id }}</td>
                            <td class="py-1">#{{ $item->order_id }}</td>
                            <td class="py-1">#{{ $item->order_item_id }}</td>
                            <td class="py-1">{{ $item->amount_minor }} {{ $item->currency }}</td>
                            <td class="py-1">{{ $item->commission_status }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-2 text-gray-500">Aucune ligne.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>
    @endif
</x-filament-panels::page>
