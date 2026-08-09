<?php

declare(strict_types=1);

use App\Models\CrmContact;
use App\Models\CrmMarketingConsentEvent;

it('keeps CRM services behind PostgreSQL authorities and sensitive parameters', function () {
    $root = dirname(__DIR__, 2);
    $directory = $root.'/app/Services/Crm';
    $sources = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)))
        ->filter(fn (SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'php')
        ->map(fn (SplFileInfo $file): string => (string) file_get_contents($file->getPathname()))
        ->implode("\n");
    $p6a0Sources = collect([
        'Concerns/UsesCrmAuthority.php',
        'CrmContactResolver.php',
        'MarketingConsentRecorder.php',
        'MarketingConsentStatusQuery.php',
    ])->map(fn (string $path): string => (string) file_get_contents($directory.'/'.$path))->implode("\n");

    expect($sources)->toContain('public.resolve_crm_contact')
        ->and($sources)->toContain('public.record_crm_marketing_consent')
        ->and($sources)->toContain('public.has_current_marketing_consent')
        ->and($sources)->toContain('SensitiveParameter')
        ->and($sources)->not->toContain('DB::table(')
        ->and($sources)->not->toContain('customer_profiles')
        ->and($sources)->not->toContain('lifetime_value_minor')
        // The ban targets the LEGACY denormalised profile column `orders_count`, not
        // `acquired_orders_count` — the real, currency-scoped P6-A1.1 rollup column the
        // P6-B0 admin read layer legitimately surfaces. A bare substring test conflated
        // the two and would have forced the honest column to be renamed or hidden.
        ->and($sources)->not->toMatch('/(?<!acquired_)orders_count/')
        ->and($sources)->not->toContain('visitor_id')
        ->and($sources)->not->toContain('Mail::')
        ->and($sources)->not->toContain('Notification::')
        ->and($p6a0Sources)->not->toContain('dispatch(')
        ->and($sources)->not->toContain('env(')
        ->and($sources)->not->toContain('Log::');
});

it('hides CRM identifiers and disables mass assignment', function () {
    $contact = new CrmContact;
    $contact->setRawAttributes(['email' => 'private@example.test', 'public_id' => 'visible']);
    $event = new CrmMarketingConsentEvent;
    $event->setRawAttributes(['idempotency_hash' => str_repeat('a', 64), 'public_id' => 'visible']);

    expect($contact->toArray())->not->toHaveKey('email')
        ->and($event->toArray())->not->toHaveKey('idempotency_hash')
        ->and($contact->getGuarded())->toBe(['*'])
        ->and($event->getGuarded())->toBe(['*']);
});

it('adds no CRM route, UI, job, mail, campaign, segment or rollup', function () {
    $root = dirname(__DIR__, 2);
    $routes = (string) file_get_contents($root.'/routes/web.php')
        .(string) file_get_contents($root.'/routes/api.php');

    expect($routes)->not->toContain('crm')
        ->and(is_dir($root.'/app/Filament/Resources/Crm'))->toBeFalse()
        ->and(is_dir($root.'/app/Jobs/Crm'))->toBeFalse()
        ->and(is_dir($root.'/app/Mail/Crm'))->toBeFalse()
        ->and(file_exists($root.'/database/migrations/2026_07_14_000021_create_customer_segments.php'))->toBeFalse();
});
