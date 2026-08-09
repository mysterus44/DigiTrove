<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AuthorizesCrmAdmin;
use App\Jobs\GenerateCrmExport;
use App\Services\Crm\CrmExportService;
use App\Services\Crm\CrmSegmentService;
use App\Support\CrmConfig;
use BackedEnum;
use Filament\Pages\Page;
use Throwable;

/**
 * P6-B1 CRM Exports. Admin-only, fail-closed, and physically unable to reach
 * `crm_exports`: every read and write goes through the EXECUTE-only authorities.
 *
 * The page NEVER renders a storage path, a raw exception or any send-eligibility signal.
 * An export is an audited artefact, not a mailing list.
 */
final class CrmExports extends Page
{
    use AuthorizesCrmAdmin;

    protected static string $routePath = 'crm-exports';

    protected static ?string $navigationLabel = 'Exports CRM';

    protected static string|\UnitEnum|null $navigationGroup = 'CRM';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?int $navigationSort = 32;

    protected static ?string $title = 'Exports CRM';

    protected string $view = 'filament.pages.crm-exports';

    public ?int $segmentId = null;

    public ?string $notice = null;

    public bool $unavailable = false;

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    /**
     * The export capability has its OWN switch: the CRM foundation being enabled is not
     * enough to expose an export surface. Implemented as the trait's hook, never as an
     * override of `canAccess()` — see AuthorizesCrmAdmin for why that distinction is
     * load-bearing.
     */
    protected static function crmGateExtraCondition(): bool
    {
        try {
            return CrmConfig::exportsEnabled();
        } catch (Throwable) {
            return false;
        }
    }

    public function kindLabels(): array
    {
        return [
            'crm_contacts' => 'Contacts CRM',
            'segment_current_members' => 'Membres courants d\'un segment',
        ];
    }

    public function statusLabels(): array
    {
        return [
            'queued' => 'En attente',
            'running' => 'En cours',
            'completed' => 'Terminé',
            'failed' => 'Échoué',
            'expired' => 'Expiré',
        ];
    }

    /** Operator-facing sentences; the stored token is never shown raw. */
    public function reasonLabels(): array
    {
        return [
            'row_limit_exceeded' => 'Trop de lignes : aucun fichier n\'a été produit.',
            'storage_unavailable' => 'Stockage indisponible.',
            'integrity_failure' => 'Contrôle d\'intégrité échoué.',
            'generation_missing' => 'Génération introuvable.',
        ];
    }

    /** @return list<array<string, mixed>> */
    public function exports(): array
    {
        $this->authorizeCrmAction();

        try {
            return app(CrmExportService::class)->list();
        } catch (Throwable) {
            $this->unavailable = true;

            return [];
        }
    }

    /**
     * Segments available for a member export, read through the P6-A2 authority.
     *
     * The segment export lives HERE rather than on the Segments page on purpose: the
     * P6-B0 security contract proves that gate ships no export affordance at all, and
     * that proof is worth more than the convenience of a second button. Every audited
     * export therefore has exactly one entry point.
     *
     * @return list<array<string, mixed>>
     */
    public function segments(): array
    {
        $this->authorizeCrmAction();

        try {
            return app(CrmSegmentService::class)->listSegments(null, 100);
        } catch (Throwable) {
            $this->unavailable = true;

            return [];
        }
    }

    public function exportContacts(): void
    {
        $this->request('crm_contacts', null);
    }

    public function exportSegmentMembers(): void
    {
        $this->authorizeCrmAction();

        if ($this->segmentId === null || $this->segmentId < 1) {
            $this->notice = 'Sélectionnez un segment.';

            return;
        }

        $this->request('segment_current_members', $this->segmentId);
    }

    /** A completed, unexpired export owned by the current user may be downloaded. */
    public function downloadable(array $export): bool
    {
        if ($export['status'] !== 'completed' || $export['requested_by_user_id'] !== auth()->id()) {
            return false;
        }

        try {
            return $export['expires_at'] !== null && now()->lessThan($export['expires_at']);
        } catch (Throwable) {
            return false;
        }
    }

    public function downloadUrl(int $exportId): string
    {
        return url('/admin/crm-exports/'.$exportId.'/download');
    }

    private function request(string $kind, ?int $segmentId): void
    {
        $this->authorizeCrmAction();
        $this->notice = null;

        try {
            CrmConfig::assertExportsEnabled();
        } catch (Throwable) {
            $this->notice = 'Les exports CRM sont désactivés.';

            return;
        }

        $userId = auth()->id();

        if (! is_int($userId)) {
            $this->notice = 'Export impossible pour le moment.';

            return;
        }

        try {
            $export = app(CrmExportService::class)->create($kind, $userId, $segmentId);
        } catch (Throwable) {
            $this->notice = 'Export refusé. Vérifiez que le segment possède une génération publiée.';

            return;
        }

        // The row is durable either way; dispatching only makes it run sooner.
        if ($this->processingEnabled()) {
            GenerateCrmExport::dispatch($export['export_id']);
            $this->notice = 'Export #'.$export['export_id'].' demandé et mis en file.';

            return;
        }

        $this->notice = 'Export #'.$export['export_id'].' enregistré (traitement désactivé, il attendra).';
    }

    private function processingEnabled(): bool
    {
        try {
            return CrmConfig::exportProcessingEnabled();
        } catch (Throwable) {
            return false;
        }
    }
}
