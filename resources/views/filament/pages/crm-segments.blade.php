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

    <x-filament::section heading="Créer un segment">
        <form wire:submit="createSegment" class="flex flex-wrap items-end gap-3">
            <div class="grow">
                <label for="crm-segment-name" class="text-sm font-medium">Nom du segment</label>
                <input id="crm-segment-name" type="text" maxlength="120" wire:model="newSegmentName"
                    class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                <p class="mt-1 text-xs text-gray-500">1 à 120 caractères. La base revalide le nom.</p>
            </div>
            <x-filament::button type="submit">Créer</x-filament::button>
        </form>
    </x-filament::section>

    <x-filament::section heading="Segments">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left">
                    <th class="py-2">ID</th>
                    <th class="py-2">Nom</th>
                    <th class="py-2">Statut</th>
                    <th class="py-2">Version courante</th>
                    <th class="py-2">Génération courante</th>
                    <th class="py-2">Membres</th>
                    <th class="py-2">Publiée le</th>
                    <th class="py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->segments() as $segment)
                    <tr wire:key="crm-segment-{{ $segment['segment_id'] }}" class="border-t">
                        <td class="py-2">#{{ $segment['segment_id'] }}</td>
                        <td class="py-2">{{ $segment['name'] }}</td>
                        <td class="py-2">{{ $this->segmentStatusOptions()[$segment['status']] ?? $segment['status'] }}</td>
                        <td class="py-2">{{ $segment['current_version_number'] ?? '—' }}</td>
                        <td class="py-2">{{ $segment['current_generation_id'] === null ? '—' : '#'.$segment['current_generation_id'] }}</td>
                        <td class="py-2">{{ $segment['current_members_count'] ?? '—' }}</td>
                        <td class="py-2">{{ $segment['generation_published_at'] ?? '—' }}</td>
                        <td class="py-2">
                            <x-filament::button size="xs" wire:click="selectSegment({{ $segment['segment_id'] }})">
                                Détail
                            </x-filament::button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-4 text-gray-500">Aucun segment.</td></tr>
                @endforelse
            </tbody>
        </table>

        {{-- Keyset paging: no OFFSET anywhere. --}}
        <div class="mt-4 flex gap-2">
            <x-filament::button size="sm" color="gray" wire:click="previousPage">Précédent</x-filament::button>
            <x-filament::button size="sm" color="gray" wire:click="nextPage">Suivant</x-filament::button>
        </div>
    </x-filament::section>

    @php($segment = $this->selectedSegment())

    @if ($segment !== null)
        <x-filament::section heading="Segment #{{ $segment['segment_id'] }}">
            <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="font-medium">Nom</dt><dd>{{ $segment['name'] }}</dd>
                <dt class="font-medium">Statut</dt><dd>{{ $this->segmentStatusOptions()[$segment['status']] ?? $segment['status'] }}</dd>
                <dt class="font-medium">Version courante</dt><dd>{{ $segment['current_version_number'] ?? 'aucune' }}</dd>
                <dt class="font-medium">Génération courante</dt><dd>{{ $segment['current_generation_id'] === null ? 'aucune' : '#'.$segment['current_generation_id'] }}</dd>
                <dt class="font-medium">Membres courants</dt><dd>{{ $segment['current_members_count'] ?? '—' }}</dd>
                <dt class="font-medium">Publiée le</dt><dd>{{ $segment['generation_published_at'] ?? '—' }}</dd>
            </dl>
        </x-filament::section>

        {{-- ── Structured criteria builder ──────────────────────────────────────────
             There is NO JSON textarea, NO code editor and NO SQL input on this page.
             Every field, operator and enum value comes from a closed <select>, so the
             browser cannot submit a token the DSL allowlist does not contain. --}}
        <x-filament::section heading="Constructeur de critères">
            <p class="mb-3 text-xs text-gray-500">
                Les critères sont saisis de manière <strong>structurée</strong>. Aucune
                saisie JSON, SQL ou libre n'est possible. La définition est ensuite
                <strong>revalidée par PostgreSQL</strong>, qui reste l'autorité finale.
            </p>

            @if ($this->builderError !== null)
                <p class="mb-3 text-sm text-danger-600">{{ $this->builderError }}</p>
            @endif

            <div class="mb-4 flex items-end gap-3">
                <div>
                    <label for="crm-match-mode" class="text-sm font-medium">Correspondance</label>
                    <select id="crm-match-mode" wire:model="matchMode" class="mt-1 rounded-lg border-gray-300 text-sm">
                        <option value="all">Tous les critères (ET)</option>
                        <option value="any">N'importe quel critère (OU)</option>
                    </select>
                </div>
                <x-filament::button type="button" size="sm" color="gray" wire:click="addCriterion">
                    Ajouter un critère
                </x-filament::button>
            </div>

            @forelse ($this->criteria as $index => $criterion)
                @php($kind = $this->kindOf($criterion['field'] ?? ''))
                <div wire:key="crm-criterion-{{ $index }}" class="mb-3 flex flex-wrap items-end gap-2 border-t pt-3">
                    <div>
                        <label class="text-xs font-medium">Champ</label>
                        <select wire:model.live="criteria.{{ $index }}.field"
                            class="mt-1 rounded-lg border-gray-300 text-sm">
                            @foreach ($this->fieldOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="text-xs font-medium">Opérateur</label>
                        <select wire:model.live="criteria.{{ $index }}.operator"
                            class="mt-1 rounded-lg border-gray-300 text-sm">
                            @foreach ($this->operatorsFor($criterion['field'] ?? '') as $operator)
                                <option value="{{ $operator }}">{{ $this->operatorOptions()[$operator] ?? $operator }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Currency is mandatory on commerce.* and absent on contact.*:
                         a per-currency rollup is read as ONE row, never summed. --}}
                    @if ($kind === 'commerce_number' || $kind === 'commerce_date')
                        <div>
                            <label class="text-xs font-medium">Devise</label>
                            <input type="text" maxlength="3" pattern="[A-Z]{3}"
                                wire:model="criteria.{{ $index }}.currency"
                                class="mt-1 w-20 rounded-lg border-gray-300 text-sm uppercase" />
                        </div>
                    @endif

                    @if ($kind === 'commerce_number')
                        @if (($criterion['operator'] ?? '') === 'between')
                            <div>
                                <label class="text-xs font-medium">De (entier)</label>
                                <input type="number" step="1" wire:model="criteria.{{ $index }}.lower"
                                    class="mt-1 w-32 rounded-lg border-gray-300 text-sm" />
                            </div>
                            <div>
                                <label class="text-xs font-medium">À (entier)</label>
                                <input type="number" step="1" wire:model="criteria.{{ $index }}.upper"
                                    class="mt-1 w-32 rounded-lg border-gray-300 text-sm" />
                            </div>
                        @else
                            <div>
                                <label class="text-xs font-medium">Valeur (entier)</label>
                                <input type="number" step="1" wire:model="criteria.{{ $index }}.value"
                                    class="mt-1 w-32 rounded-lg border-gray-300 text-sm" />
                            </div>
                        @endif
                    @endif

                    @if ($kind === 'commerce_date' || $kind === 'contact_date')
                        @if (($criterion['operator'] ?? '') === 'between')
                            <div>
                                <label class="text-xs font-medium">De (UTC)</label>
                                <input type="datetime-local" step="1" wire:model="criteria.{{ $index }}.lower"
                                    class="mt-1 rounded-lg border-gray-300 text-sm" />
                            </div>
                            <div>
                                <label class="text-xs font-medium">À (UTC)</label>
                                <input type="datetime-local" step="1" wire:model="criteria.{{ $index }}.upper"
                                    class="mt-1 rounded-lg border-gray-300 text-sm" />
                            </div>
                        @else
                            <div>
                                <label class="text-xs font-medium">Date (UTC)</label>
                                <input type="datetime-local" step="1" wire:model="criteria.{{ $index }}.value"
                                    class="mt-1 rounded-lg border-gray-300 text-sm" />
                            </div>
                        @endif
                    @endif

                    @if ($kind === 'contact_enum')
                        <div>
                            <label class="text-xs font-medium">Valeurs</label>
                            <select multiple wire:model="criteria.{{ $index }}.values"
                                class="mt-1 rounded-lg border-gray-300 text-sm">
                                @foreach ($this->enumValuesFor($criterion['field'] ?? '') as $value)
                                    <option value="{{ $value }}">{{ $value }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <x-filament::button type="button" size="xs" color="danger"
                        wire:click="removeCriterion({{ $index }})">
                        Retirer
                    </x-filament::button>
                </div>
            @empty
                <p class="text-sm text-gray-500">Aucun critère. Ajoutez-en au moins un.</p>
            @endforelse

            <div class="mt-4">
                <x-filament::button type="button" wire:click="createVersion">
                    Créer une nouvelle version (brouillon)
                </x-filament::button>
                <p class="mt-2 text-xs text-gray-500">
                    Une version existante n'est <strong>jamais</strong> modifiée ni supprimée :
                    changer la définition crée toujours une nouvelle version.
                </p>
            </div>
        </x-filament::section>

        <x-filament::section heading="Historique des versions">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left">
                        <th class="py-2">Version</th><th class="py-2">Statut</th>
                        <th class="py-2">Définition</th>
                        <th class="py-2">Créée le</th><th class="py-2">Publiée le</th><th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->versions() as $version)
                        <tr wire:key="crm-version-{{ $version['version_id'] }}" class="border-t align-top">
                            <td class="py-2">v{{ $version['version_number'] }}</td>
                            <td class="py-2">{{ $this->versionStatusOptions()[$version['status']] ?? $version['status'] }}</td>
                            <td class="py-2">
                                {{-- Read-only rendering of an immutable record. --}}
                                <ul class="list-disc pl-4">
                                    @foreach ($this->describeDefinition($version['definition']) as $line)
                                        <li>{{ $line }}</li>
                                    @endforeach
                                </ul>
                            </td>
                            <td class="py-2">{{ $version['created_at'] }}</td>
                            <td class="py-2">{{ $version['published_at'] ?? '—' }}</td>
                            <td class="py-2">
                                @if ($version['status'] === 'draft')
                                    <x-filament::button size="xs"
                                        wire:click="publishVersion({{ $version['version_id'] }})"
                                        wire:confirm="Publier définitivement la version {{ $version['version_number'] }} ? Cette action est irréversible.">
                                        Publier
                                    </x-filament::button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-4 text-gray-500">Aucune version.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section heading="Génération">
            @php($generation = $this->watchedGeneration())

            @if ($generation === null)
                <p class="text-sm text-gray-500">Aucune génération à afficher.</p>
            @else
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <dt class="font-medium">Génération</dt><dd>#{{ $generation['generation_id'] }}</dd>
                    <dt class="font-medium">Version</dt><dd>#{{ $generation['segment_version_id'] }}</dd>
                    <dt class="font-medium">Statut</dt>
                    <dd>{{ $this->generationStatusOptions()[$generation['status']] ?? $generation['status'] }}</dd>
                    <dt class="font-medium">Borne de population (HWM)</dt><dd>{{ $generation['contact_id_high_water_mark'] }}</dd>
                    <dt class="font-medium">Curseur</dt><dd>{{ $generation['cursor_contact_id'] ?? '—' }}</dd>
                    <dt class="font-medium">Taille de lot</dt><dd>{{ $generation['batch_size'] }}</dd>
                    <dt class="font-medium">Membres</dt><dd>{{ $generation['members_count'] }}</dd>
                    {{-- A stable SQLSTATE class, never a raw exception message. --}}
                    <dt class="font-medium">Dernier code d'erreur</dt><dd>{{ $generation['last_error_code'] ?? '—' }}</dd>
                </dl>

                @if ($generation['status'] === 'failed')
                    <div class="mt-3">
                        <x-filament::button size="sm" color="warning"
                            wire:click="retryGeneration({{ $generation['generation_id'] }})"
                            wire:confirm="Relancer la génération #{{ $generation['generation_id'] }} ?">
                            Relancer
                        </x-filament::button>
                        <p class="mt-1 text-xs text-gray-500">
                            Seule une génération en échec peut être relancée, et jamais automatiquement.
                        </p>
                    </div>
                @endif
            @endif

            <div class="mt-4 border-t pt-3">
                @if ($this->rebuildEnabled())
                    <x-filament::button type="button" wire:click="rebuild"
                        wire:confirm="Démarrer une reconstruction du segment #{{ $segment['segment_id'] }} ?">
                        Reconstruire
                    </x-filament::button>
                @else
                    <x-filament::button type="button" disabled>Reconstruire</x-filament::button>
                    <p class="mt-1 text-xs text-warning-600">
                        Reconstruction désactivée par configuration. Le serveur refuse également
                        l'action : aucune génération ne peut être créée.
                    </p>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section heading="Membres courants">
            <p class="mb-3 text-xs text-gray-500">
                Membres de la <strong>génération publiée courante</strong> uniquement. Une
                génération remplacée n'est jamais une appartenance. L'appartenance à un
                segment n'est <strong>pas</strong> une autorisation d'envoi.
            </p>
            <ul class="text-sm">
                @forelse ($this->currentMembers() as $contactId)
                    <li wire:key="crm-member-{{ $contactId }}" class="border-t py-1">Contact #{{ $contactId }}</li>
                @empty
                    <li class="py-4 text-gray-500">Aucun membre courant.</li>
                @endforelse
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
