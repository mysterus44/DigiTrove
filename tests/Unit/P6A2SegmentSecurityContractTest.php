<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

function p6a2AppSource(): string
{
    $paths = [
        app_path('Services/Crm/CrmSegmentService.php'),
        app_path('Services/Crm/CrmSegmentGenerationDispatcher.php'),
        app_path('Jobs/ProcessCrmSegmentGeneration.php'),
        app_path('Console/Commands/RebuildCrmSegment.php'),
        app_path('Console/Commands/SweepCrmSegmentGenerations.php'),
    ];

    return implode("\n", array_map(static fn (string $p): string => file_get_contents($p), $paths));
}

function p6a2MigrationSource(): string
{
    return file_get_contents(
        base_path('database/migrations/2026_07_14_000025_create_typed_versioned_crm_segments.php'),
    );
}

it('builds no SQL from the definition anywhere in the application layer', function () {
    $source = p6a2AppSource();

    expect($source)->not->toMatch('/\bDB::raw\b/')
        ->not->toMatch('/\bwhereRaw\b/')
        ->not->toMatch('/\bhavingRaw\b/')
        ->not->toMatch('/\bselectRaw\b/')
        ->not->toMatch('/\bunprepared\b/')
        ->not->toMatch('/\beval\s*\(/')
        // Criteria are never interpreted in PHP.
        ->not->toContain("'criteria'")
        ->not->toContain('->criteria');
});

it('never interpolates a definition into a SQL string', function () {
    // Every authority call uses bound placeholders and a jsonb cast.
    expect(p6a2AppSource())->toContain('?::jsonb')
        ->and(p6a2AppSource())->not->toMatch('/SELECT[^\n]*\$\{/')
        ->and(p6a2AppSource())->not->toMatch('/"SELECT[^"]*\.\s*\$/');
});

it('never reads PII, commerce tables or marketing consent from the application layer', function () {
    $source = p6a2AppSource();

    expect($source)->not->toContain('customer_email')
        ->not->toContain('normalized_email')
        ->not->toContain('visitor_id')
        ->not->toContain('crm_marketing_consent_events')
        ->not->toMatch('/(?:from|join|into|update)\s+(?:public\.)?(?:orders|payments|refunds|crm_segments|crm_segment_versions|crm_segment_generations|crm_segment_generation_members|analytics_\w+)\b/i');
});

it('keeps the matcher free of consent, analytics and raw commerce sources', function () {
    $migration = p6a2MigrationSource();

    // Extract the matcher body only.
    preg_match('/CREATE OR REPLACE FUNCTION public\.crm_segment_contact_matches_v1(.*?)\n            \$\$;/s', $migration, $matches);
    $matcher = $matches[1] ?? '';

    expect($matcher)->not->toBe('')
        ->and($matcher)->toContain('public.crm_contacts')
        ->and($matcher)->toContain('public.crm_contact_commerce_rollups')
        // Membership is never a function of consent, analytics or raw commerce.
        ->and($matcher)->not->toContain('crm_marketing_consent_events')
        ->and($matcher)->not->toContain('public.orders')
        ->and($matcher)->not->toContain('public.payments')
        ->and($matcher)->not->toContain('public.refunds')
        ->and($matcher)->not->toContain('analytics_')
        ->and($matcher)->not->toContain('public.users')
        ->and($matcher)->not->toContain('visitor');
});

it('uses no dynamic SQL, trigger bypass or FX in the migration', function () {
    $migration = p6a2MigrationSource();

    expect($migration)->not->toContain('session_replication_role')
        ->not->toContain('DISABLE TRIGGER')
        ->not->toContain('variable_conflict')
        ->not->toContain('CREATE ROLE')
        ->not->toMatch('/EXECUTE\s+format\s*\(/i')
        ->not->toMatch('/EXECUTE\s+v_/i')
        ->not->toMatch('/\bexchange_rate\b/i')
        ->not->toMatch('/\bfx_\w+/i')
        // No floating point ever touches money.
        ->not->toMatch('/\bDOUBLE PRECISION\b/i')
        ->not->toMatch('/\bREAL\b/');
});

it('adds no segment UI, route or Filament resource in this gate', function () {
    expect(glob(app_path('Filament/**/*Segment*.php')) ?: [])->toBe([])
        ->and(glob(app_path('Http/Controllers/*Segment*.php')) ?: [])->toBe([]);

    $routes = implode("\n", array_map(
        static fn (string $p): string => file_exists($p) ? file_get_contents($p) : '',
        [base_path('routes/web.php'), base_path('routes/api.php')],
    ));
    expect(mb_strtolower($routes))->not->toContain('segment');
});

it('keeps every commerce criterion currency-scoped in the validator', function () {
    $migration = p6a2MigrationSource();

    // Commerce criteria always require a currency key; contact criteria never accept one.
    expect($migration)->toContain("v_expected := ARRAY['currency', 'field', 'operator', 'value']")
        ->toContain("v_expected := ARRAY['field', 'operator', 'values']")
        ->toContain("'^[A-Z]{3}\$'");
});
