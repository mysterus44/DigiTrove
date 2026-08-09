<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AuthorizesCrmAdmin;
use App\Services\Crm\CrmAdminReadService;
use App\Support\CrmMoneyPresenter;
use BackedEnum;
use Filament\Pages\Page;

/**
 * P6-B0 CRM Contacts. Admin-only, fail-closed, and physically unable to reach CRM
 * tables: every read goes through CrmAdminReadService, i.e. the EXECUTE-only
 * authorities.
 *
 * The e-mail search box is deliberately NOT synchronised to the URL (`#[Url]` is
 * absent) so a personal address never lands in a query string, a browser history
 * entry, a referrer header or an access log.
 */
final class CrmContacts extends Page
{
    use AuthorizesCrmAdmin;

    protected static string $routePath = 'crm-contacts';

    protected static ?string $navigationLabel = 'Contacts CRM';

    protected static string|\UnitEnum|null $navigationGroup = 'CRM';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Contacts CRM';

    protected string $view = 'filament.pages.crm-contacts';

    /** Search state lives in Livewire only — never in the query string. */
    public string $email = '';

    public ?string $statusFilter = null;

    public ?string $originFilter = null;

    public ?int $selectedContactId = null;

    /** @var list<int> Keyset cursor stack, so "previous" needs no OFFSET. */
    public array $cursorStack = [];

    public ?int $afterContactId = null;

    public bool $unavailable = false;

    public bool $searchMissed = false;

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    /** Allowlisted filter values, mirroring the real crm_contacts CHECK constraints. */
    public function statusOptions(): array
    {
        return ['active' => 'Actif', 'anonymized' => 'Anonymisé'];
    }

    public function originOptions(): array
    {
        return ['guest_order' => 'Commande invité', 'verified_account' => 'Compte vérifié'];
    }

    /** @return list<array<string, mixed>> */
    public function contacts(): array
    {
        $this->authorizeCrmAction();

        try {
            return app(CrmAdminReadService::class)->listContacts(
                $this->afterContactId,
                $this->allowlisted($this->statusFilter, array_keys($this->statusOptions())),
                $this->allowlisted($this->originFilter, array_keys($this->originOptions())),
            );
        } catch (\Throwable) {
            $this->unavailable = true;

            return [];
        }
    }

    /** Exact normalised e-mail only — the authority does the matching, never PHP. */
    public function search(): void
    {
        $this->authorizeCrmAction();
        $this->searchMissed = false;

        $needle = trim($this->email);

        if ($needle === '') {
            $this->selectedContactId = null;

            return;
        }

        try {
            $found = app(CrmAdminReadService::class)->findContactByExactEmail($needle);
        } catch (\Throwable) {
            $this->unavailable = true;

            return;
        }

        if ($found === null) {
            // No hit: never fall back to a partial or fuzzy lookup.
            $this->selectedContactId = null;
            $this->searchMissed = true;

            return;
        }

        $this->selectedContactId = $found['contact_id'];
    }

    public function clearSearch(): void
    {
        $this->email = '';
        $this->searchMissed = false;
        $this->selectedContactId = null;
    }

    public function nextPage(): void
    {
        $contacts = $this->contacts();

        if ($contacts === []) {
            return;
        }

        $this->cursorStack[] = $this->afterContactId;
        $this->afterContactId = $contacts[array_key_last($contacts)]['contact_id'];
    }

    public function previousPage(): void
    {
        if ($this->cursorStack === []) {
            $this->afterContactId = null;

            return;
        }

        $this->afterContactId = array_pop($this->cursorStack);
    }

    public function resetPaging(): void
    {
        $this->cursorStack = [];
        $this->afterContactId = null;
    }

    public function select(int $contactId): void
    {
        $this->authorizeCrmAction();
        $this->selectedContactId = $contactId;
    }

    /** @return array<string, mixed>|null */
    public function selectedContact(): ?array
    {
        if ($this->selectedContactId === null) {
            return null;
        }

        $this->authorizeCrmAction();

        try {
            return app(CrmAdminReadService::class)->contact($this->selectedContactId);
        } catch (\Throwable) {
            $this->unavailable = true;

            return null;
        }
    }

    /** @return list<array<string, mixed>> */
    public function consentEvents(): array
    {
        return $this->selectedContactId === null
            ? []
            : $this->safe(fn (CrmAdminReadService $s): array => $s->consentEvents($this->selectedContactId));
    }

    /** @return list<array<string, mixed>> One row per currency; never summed. */
    public function commerceRollups(): array
    {
        return $this->selectedContactId === null
            ? []
            : $this->safe(fn (CrmAdminReadService $s): array => $s->commerceRollups($this->selectedContactId));
    }

    /** @return list<array<string, mixed>> Current published memberships only. */
    public function segmentMemberships(): array
    {
        return $this->selectedContactId === null
            ? []
            : $this->safe(fn (CrmAdminReadService $s): array => $s->segmentMemberships($this->selectedContactId));
    }

    public function money(int $minor, string $currency): string
    {
        return CrmMoneyPresenter::format($minor, $currency);
    }

    /** Display label for a contact whose address no longer exists. */
    public function emailLabel(?string $email): string
    {
        return $email ?? 'Anonymisé';
    }

    /** @param callable(CrmAdminReadService):list<array<string, mixed>> $reader */
    private function safe(callable $reader): array
    {
        $this->authorizeCrmAction();

        try {
            return $reader(app(CrmAdminReadService::class));
        } catch (\Throwable) {
            $this->unavailable = true;

            return [];
        }
    }

    /** @param list<string> $allowed */
    private function allowlisted(?string $value, array $allowed): ?string
    {
        return in_array($value, $allowed, true) ? $value : null;
    }
}
