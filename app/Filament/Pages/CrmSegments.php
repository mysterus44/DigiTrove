<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AuthorizesCrmAdmin;
use App\Services\Crm\CrmAdminReadService;
use App\Services\Crm\CrmSegmentService;
use App\Support\CrmConfig;
use App\Support\CrmSegmentDefinitionBuilder;
use App\Support\CrmSegmentDefinitionException;
use BackedEnum;
use Filament\Pages\Page;
use Throwable;

/**
 * P6-B0 CRM Segments. Admin-only, fail-closed, and physically unable to reach the
 * segment tables: every read and every mutation goes through the P6-A2 / P6-B0.1
 * EXECUTE-only authorities.
 *
 * THE DEFINITION IS NEVER AUTHORED AS TEXT. There is no JSON textarea, no code editor
 * and no SQL input anywhere on this page: the operator picks a field, an operator and a
 * typed value from closed lists, and CrmSegmentDefinitionBuilder assembles the DSL V1
 * envelope. PostgreSQL then validates it again — the PHP layer is a shaping convenience,
 * never the authority.
 *
 * Membership is NOT send eligibility. This page shows who is in a segment; it offers no
 * campaign, no e-mail and no export action of any kind.
 */
final class CrmSegments extends Page
{
    use AuthorizesCrmAdmin;

    protected static string $routePath = 'crm-segments';

    protected static ?string $navigationLabel = 'Segments CRM';

    protected static string|\UnitEnum|null $navigationGroup = 'CRM';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?int $navigationSort = 31;

    protected static ?string $title = 'Segments CRM';

    protected string $view = 'filament.pages.crm-segments';

    public const MEMBERS_PAGE_SIZE = 25;

    public ?int $selectedSegmentId = null;

    /** @var list<int|null> Keyset cursor stack, so "previous" needs no OFFSET. */
    public array $cursorStack = [];

    public ?int $afterSegmentId = null;

    public string $newSegmentName = '';

    public string $matchMode = 'all';

    /** @var list<array<string, mixed>> Structured builder rows — never free text. */
    public array $criteria = [];

    public ?int $watchedGenerationId = null;

    public bool $unavailable = false;

    public ?string $notice = null;

    public ?string $builderError = null;

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function segmentStatusOptions(): array
    {
        return ['active' => 'Actif', 'archived' => 'Archivé'];
    }

    public function generationStatusOptions(): array
    {
        return ['ready' => 'En attente', 'running' => 'En cours', 'published' => 'Publiée', 'failed' => 'Échouée'];
    }

    public function versionStatusOptions(): array
    {
        return ['draft' => 'Brouillon', 'published' => 'Publiée'];
    }

    /** Field => human label, restricted to the nine allowlisted DSL V1 fields. */
    public function fieldOptions(): array
    {
        return [
            'commerce.net_revenue_minor' => 'Commerce — revenu net (unités mineures)',
            'commerce.gross_revenue_minor' => 'Commerce — revenu brut (unités mineures)',
            'commerce.refunded_amount_minor' => 'Commerce — montant remboursé (unités mineures)',
            'commerce.acquired_orders_count' => 'Commerce — commandes acquises',
            'commerce.first_acquired_at' => 'Commerce — première acquisition',
            'commerce.last_acquired_at' => 'Commerce — dernière acquisition',
            'contact.created_at' => 'Contact — date de création',
            'contact.status' => 'Contact — statut',
            'contact.origin' => 'Contact — origine',
        ];
    }

    public function operatorOptions(): array
    {
        return [
            'eq' => 'égal à', 'neq' => 'différent de', 'gt' => 'supérieur à',
            'gte' => 'supérieur ou égal à', 'lt' => 'inférieur à', 'lte' => 'inférieur ou égal à',
            'between' => 'compris entre', 'before' => 'avant', 'after' => 'après',
            'in' => 'parmi', 'not_in' => 'hors de',
        ];
    }

    /** @return list<string> */
    public function operatorsFor(string $field): array
    {
        try {
            return CrmSegmentDefinitionBuilder::operatorsFor($field);
        } catch (Throwable) {
            return [];
        }
    }

    public function kindOf(string $field): string
    {
        try {
            return CrmSegmentDefinitionBuilder::kindOf($field);
        } catch (Throwable) {
            return 'unknown';
        }
    }

    /** @return list<string> */
    public function enumValuesFor(string $field): array
    {
        return CrmSegmentDefinitionBuilder::ENUM_VALUES[$field] ?? [];
    }

    // ── Reads ────────────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function segments(): array
    {
        return $this->safe(fn (CrmSegmentService $s): array => $s->listSegments(
            $this->afterSegmentId,
            CrmAdminReadService::PAGE_SIZE,
        ));
    }

    /** @return array<string, mixed>|null */
    public function selectedSegment(): ?array
    {
        if ($this->selectedSegmentId === null) {
            return null;
        }

        $this->authorizeCrmAction();

        try {
            return app(CrmSegmentService::class)->segment($this->selectedSegmentId);
        } catch (Throwable) {
            $this->unavailable = true;

            return null;
        }
    }

    /**
     * Version history via the B0.1 authority. The definition is returned so it can be
     * RENDERED through the structured reader — never re-opened as an editable blob.
     *
     * @return list<array<string, mixed>>
     */
    public function versions(): array
    {
        if ($this->selectedSegmentId === null) {
            return [];
        }

        $this->authorizeCrmAction();

        try {
            return app(CrmAdminReadService::class)->segmentVersions($this->selectedSegmentId);
        } catch (Throwable) {
            $this->unavailable = true;

            return [];
        }
    }

    /** @return array<string, mixed>|null */
    public function watchedGeneration(): ?array
    {
        $generationId = $this->watchedGenerationId ?? $this->selectedSegment()['current_generation_id'] ?? null;

        if ($generationId === null) {
            return null;
        }

        $this->authorizeCrmAction();

        try {
            return app(CrmSegmentService::class)->generation($generationId);
        } catch (Throwable) {
            $this->unavailable = true;

            return null;
        }
    }

    /** @return list<int> Members of the CURRENT published generation only. */
    public function currentMembers(): array
    {
        if ($this->selectedSegmentId === null) {
            return [];
        }

        return $this->safe(fn (CrmSegmentService $s): array => $s->currentMembers(
            $this->selectedSegmentId,
            null,
            self::MEMBERS_PAGE_SIZE,
        ));
    }

    /**
     * Render a stored definition as readable rows. It is a PRESENTATION of an immutable
     * record: nothing here feeds back into the builder, so a published version can never
     * be reopened for editing.
     *
     * @return list<string>
     */
    public function describeDefinition(?string $definition): array
    {
        if ($definition === null) {
            return [];
        }

        try {
            $decoded = json_decode($definition, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($decoded) || ! is_array($decoded['criteria'] ?? null)) {
            return [];
        }

        $lines = [];

        foreach ($decoded['criteria'] as $criterion) {
            if (! is_array($criterion)) {
                continue;
            }

            $lines[] = $this->describeCriterion($criterion);
        }

        return $lines;
    }

    // ── Lifecycle actions ────────────────────────────────────────────────────────

    public function selectSegment(int $segmentId): void
    {
        $this->authorizeCrmAction();
        $this->selectedSegmentId = $segmentId;
        $this->watchedGenerationId = null;
        $this->notice = null;
        $this->builderError = null;
    }

    public function createSegment(): void
    {
        $this->authorizeCrmAction();
        $this->notice = null;

        $name = trim($this->newSegmentName);

        // Mirrors crm_segments_name_check (1..120 after btrim). The authority re-checks.
        if ($name === '' || mb_strlen($name) > 120) {
            $this->notice = 'Le nom du segment doit contenir entre 1 et 120 caractères.';

            return;
        }

        try {
            $segment = app(CrmSegmentService::class)->createSegment($name);
        } catch (Throwable) {
            $this->unavailable = true;

            return;
        }

        $this->newSegmentName = '';
        $this->selectedSegmentId = $segment['segment_id'];
        $this->notice = 'Segment #'.$segment['segment_id'].' créé.';
    }

    public function addCriterion(): void
    {
        $this->authorizeCrmAction();

        if (count($this->criteria) >= CrmSegmentDefinitionBuilder::MAX_CRITERIA) {
            $this->builderError = 'Un maximum de '.CrmSegmentDefinitionBuilder::MAX_CRITERIA.' critères est autorisé.';

            return;
        }

        $this->criteria[] = [
            'field' => 'contact.status',
            'operator' => 'in',
            'currency' => '',
            'value' => '',
            'lower' => '',
            'upper' => '',
            'values' => [],
        ];
    }

    public function removeCriterion(int $index): void
    {
        $this->authorizeCrmAction();
        unset($this->criteria[$index]);
        $this->criteria = array_values($this->criteria);
    }

    /**
     * Keep a row coherent when its field changes: the operator and the typed inputs of
     * the previous kind are dropped rather than carried over into a shape where they
     * would be meaningless.
     */
    public function updatedCriteria(mixed $value, ?string $key = null): void
    {
        // Livewire passes a null key when the WHOLE array is replaced at once, and a
        // dotted path when a single cell changes. Only the latter can be a field switch.
        if ($key === null || ! str_ends_with($key, '.field')) {
            return;
        }

        $index = (int) explode('.', $key)[0];

        if (! isset($this->criteria[$index]) || ! is_string($value)) {
            return;
        }

        $operators = $this->operatorsFor($value);

        $this->criteria[$index]['operator'] = $operators[0] ?? '';
        $this->criteria[$index]['currency'] = '';
        $this->criteria[$index]['value'] = '';
        $this->criteria[$index]['lower'] = '';
        $this->criteria[$index]['upper'] = '';
        $this->criteria[$index]['values'] = [];
    }

    /**
     * Build the DSL and hand it to PostgreSQL. The builder only SHAPES the payload; the
     * authority `create_crm_segment_version` → `validate_crm_segment_definition_v1` is
     * what actually decides, so a definition this class wrongly accepted is still
     * refused there and no version row is created.
     */
    public function createVersion(): void
    {
        $this->authorizeCrmAction();
        $this->notice = null;
        $this->builderError = null;

        if ($this->selectedSegmentId === null) {
            $this->builderError = 'Sélectionnez un segment.';

            return;
        }

        try {
            $definition = CrmSegmentDefinitionBuilder::build($this->matchMode, array_values($this->criteria));
        } catch (CrmSegmentDefinitionException $exception) {
            $this->builderError = $this->builderMessage($exception->reason());

            return;
        }

        try {
            $version = app(CrmSegmentService::class)->createVersion($this->selectedSegmentId, $definition);
        } catch (Throwable) {
            // The authority refused (or is unavailable). No SQLSTATE, no driver text.
            $this->builderError = 'La définition a été refusée par la base. Vérifiez les critères saisis.';

            return;
        }

        $this->notice = 'Version '.$version['version_number'].' créée en brouillon.';
    }

    /** Publishing is explicit and irreversible: a version is never edited in place. */
    public function publishVersion(int $versionId): void
    {
        $this->authorizeCrmAction();
        $this->notice = null;
        $this->builderError = null;

        try {
            $status = app(CrmSegmentService::class)->publishVersion($versionId);
        } catch (Throwable) {
            $this->notice = 'Publication refusée : une génération est peut-être en cours.';

            return;
        }

        $this->notice = 'Version #'.$versionId.' : '.$status.'.';
    }

    /** The REAL P6-A2 flag — the same one `crm:rebuild-segment` obeys. */
    public function rebuildEnabled(): bool
    {
        try {
            return CrmConfig::segmentRebuildEnabled();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Start a durable generation. Disabling the flag does not merely grey out a button:
     * the server refuses here as well, so no generation row can be created.
     */
    public function rebuild(): void
    {
        $this->authorizeCrmAction();
        $this->notice = null;

        if ($this->selectedSegmentId === null) {
            return;
        }

        try {
            CrmConfig::assertSegmentRebuildEnabled();
        } catch (Throwable) {
            $this->notice = 'La reconstruction des segments est désactivée.';

            return;
        }

        try {
            $generation = app(CrmSegmentService::class)->startGeneration(
                $this->selectedSegmentId,
                CrmConfig::segmentRebuildBatchSize(),
            );
        } catch (Throwable) {
            $this->notice = 'Reconstruction impossible pour le moment.';

            return;
        }

        $this->watchedGenerationId = $generation['generation_id'];
        $this->notice = 'Génération #'.$generation['generation_id'].' démarrée ('.$generation['status'].').';
    }

    /**
     * Retry is operator-driven and allowed ONLY on a failed generation. There is no
     * automatic retry anywhere in this page.
     */
    public function retryGeneration(int $generationId): void
    {
        $this->authorizeCrmAction();
        $this->notice = null;

        try {
            $generation = app(CrmSegmentService::class)->generation($generationId);
        } catch (Throwable) {
            $this->unavailable = true;

            return;
        }

        if ($generation === null || $generation['status'] !== 'failed') {
            $this->notice = 'Seule une génération en échec peut être relancée.';

            return;
        }

        try {
            $status = app(CrmSegmentService::class)->retryGeneration($generationId);
        } catch (Throwable) {
            $this->notice = 'Relance impossible pour le moment.';

            return;
        }

        $this->watchedGenerationId = $generationId;
        $this->notice = 'Génération #'.$generationId.' relancée ('.$status.').';
    }

    // ── Paging ───────────────────────────────────────────────────────────────────

    public function nextPage(): void
    {
        $segments = $this->segments();

        if ($segments === []) {
            return;
        }

        $this->cursorStack[] = $this->afterSegmentId;
        $this->afterSegmentId = $segments[array_key_last($segments)]['segment_id'];
    }

    public function previousPage(): void
    {
        if ($this->cursorStack === []) {
            $this->afterSegmentId = null;

            return;
        }

        $this->afterSegmentId = array_pop($this->cursorStack);
    }

    // ── Internals ────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $criterion */
    private function describeCriterion(array $criterion): string
    {
        $field = is_string($criterion['field'] ?? null) ? $criterion['field'] : '?';
        $operator = is_string($criterion['operator'] ?? null) ? $criterion['operator'] : '?';
        $label = $this->fieldOptions()[$field] ?? $field;
        $line = $label.' '.($this->operatorOptions()[$operator] ?? $operator);

        if (isset($criterion['lower'], $criterion['upper'])) {
            $line .= ' '.$this->scalar($criterion['lower']).' … '.$this->scalar($criterion['upper']);
        } elseif (array_key_exists('value', $criterion)) {
            $line .= ' '.$this->scalar($criterion['value']);
        } elseif (is_array($criterion['values'] ?? null)) {
            $line .= ' ['.implode(', ', array_map($this->scalar(...), $criterion['values'])).']';
        }

        // Currency is always explicit on a commerce criterion; it is never inferred.
        if (is_string($criterion['currency'] ?? null)) {
            $line .= ' ('.$criterion['currency'].')';
        }

        return $line;
    }

    private function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '?';
    }

    /** Stable reason code => operator-facing sentence. No database text ever appears. */
    private function builderMessage(string $reason): string
    {
        return match ($reason) {
            'criteria_required' => 'Ajoutez au moins un critère.',
            'too_many_criteria' => 'Trop de critères (50 au maximum).',
            'invalid_match_mode' => 'Mode de correspondance invalide.',
            'missing_field', 'missing_operator' => 'Champ et opérateur obligatoires.',
            'unknown_field' => 'Champ inconnu.',
            'invalid_operator' => 'Opérateur invalide pour ce champ.',
            // An empty input and a malformed one are the same operator mistake, so the
            // builder reports ONE kind-accurate reason for both rather than a generic
            // "missing" code this screen would have to disambiguate by guessing.
            'invalid_currency' => 'Devise obligatoire au format ISO à trois lettres majuscules.',
            'invalid_integer' => 'Valeur entière requise (aucune décimale, aucune notation scientifique).',
            'integer_out_of_range' => 'Valeur entière hors des bornes autorisées.',
            'invalid_timestamp' => 'Date UTC absolue requise (aucune expression relative).',
            'inverted_bounds' => 'La borne inférieure dépasse la borne supérieure.',
            'values_required' => 'Sélectionnez au moins une valeur.',
            'unknown_enum_value' => 'Valeur inconnue pour ce champ.',
            'duplicate_enum_value' => 'Valeur sélectionnée en double.',
            default => 'Critère invalide.',
        };
    }

    /**
     * @param  callable(CrmSegmentService):list<mixed>  $reader
     * @return list<mixed>
     */
    private function safe(callable $reader): array
    {
        $this->authorizeCrmAction();

        try {
            return $reader(app(CrmSegmentService::class));
        } catch (Throwable) {
            $this->unavailable = true;

            return [];
        }
    }
}
