<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

it('adds exactly one migration with no code surface and no 000023', function () use ($root) {
    $migrations = glob($root.'/database/migrations/*.php') ?: [];
    expect($migrations)->toHaveCount(38);

    // 000022 exists
    $m22 = glob($root.'/database/migrations/2026_07_14_000022*.php') ?: [];
    expect($m22)->toHaveCount(1);

    // 000023 does not exist
    $m23 = glob($root.'/database/migrations/2026_07_14_000023*.php') ?: [];
    expect($m23)->toBe([]);

    // No Eloquent model, no service, no job, no command, no controller for rollups
    $appFiles = array_merge(
        glob($root.'/app/Models/Crm*Rollup*.php') ?: [],
        glob($root.'/app/Services/Crm*Rollup*.php') ?: [],
        glob($root.'/app/Jobs/Crm*Rollup*.php') ?: [],
        glob($root.'/app/Console/Commands/Crm*Rollup*.php') ?: [],
        glob($root.'/app/Http/Controllers/Crm*Rollup*.php') ?: [],
    );
    expect($appFiles)->toBe([]);
});

it('contains no session_replication_role bypass in P6-A1.1 tests', function () use ($root) {
    $testFiles = glob($root.'/tests/Feature/P6A11*.php') ?: [];
    expect($testFiles)->not->toBeEmpty();

    $contents = implode("\n", array_map('file_get_contents', $testFiles));
    expect($contents)->not->toContain('session_replication_role');
});

it('contains no variable_conflict directive in the migration', function () use ($root) {
    $migration = file_get_contents($root.'/database/migrations/2026_07_14_000022_create_crm_contact_commerce_rollups.php');
    expect($migration)->not->toContain('variable_conflict');
});

it('qualifies all table references in the migration function body', function () use ($root) {
    $migration = file_get_contents($root.'/database/migrations/2026_07_14_000022_create_crm_contact_commerce_rollups.php');

    // Extract the function body (between AS $$ and $$;)
    preg_match('/AS \$\$(.*?)\$\$/s', $migration, $matches);
    $body = $matches[1] ?? '';

    // All table references should use aliases or be fully qualified with public.
    // Unqualified bare table names in FROM/JOIN clauses are a sign of ambiguity risk.
    // The function should use public.table_name AS alias pattern
    expect($body)->toContain('public.crm_contacts')
        ->and($body)->toContain('public.crm_order_attributions')
        ->and($body)->toContain('public.orders')
        ->and($body)->toContain('public.payments')
        ->and($body)->toContain('public.refunds')
        ->and($body)->toContain('public.crm_contact_commerce_rollups');
});

it('uses p_ prefix for all PL/pgSQL parameters', function () use ($root) {
    $migration = file_get_contents($root.'/database/migrations/2026_07_14_000022_create_crm_contact_commerce_rollups.php');

    // Function parameters should have p_ prefix
    expect($migration)->toContain('p_contact_id BIGINT')
        ->and($migration)->toContain('p_currency VARCHAR');
});

it('uses ON CONFLICT ON CONSTRAINT for the named PK', function () use ($root) {
    $migration = file_get_contents($root.'/database/migrations/2026_07_14_000022_create_crm_contact_commerce_rollups.php');
    expect($migration)->toContain('ON CONFLICT ON CONSTRAINT crm_contact_commerce_rollups_pkey');
});

it('does not contain the unguaranteed refund_date_check', function () use ($root) {
    $migration = file_get_contents($root.'/database/migrations/2026_07_14_000022_create_crm_contact_commerce_rollups.php');
    expect($migration)->not->toContain('refund_date_check');
});

it('uses NUMERIC for intermediate calculation to prevent overflow', function () use ($root) {
    $migration = file_get_contents($root.'/database/migrations/2026_07_14_000022_create_crm_contact_commerce_rollups.php');
    expect($migration)->toContain('v_gross_num NUMERIC')
        ->and($migration)->toContain('v_refunded_num NUMERIC');
});

it('does not contain any PII column or email reference in the table', function () use ($root) {
    $migration = file_get_contents($root.'/database/migrations/2026_07_14_000022_create_crm_contact_commerce_rollups.php');

    // Extract CREATE TABLE statement
    preg_match('/CREATE TABLE crm_contact_commerce_rollups\s*\((.*?)\);/s', $migration, $matches);
    $tableDef = $matches[1] ?? '';

    expect($tableDef)->not->toContain('email')
        ->and($tableDef)->not->toContain('user_id')
        ->and($tableDef)->not->toContain('visitor_id')
        ->and($tableDef)->not->toContain('jsonb')
        ->and($tableDef)->not->toContain('json')
        ->and($tableDef)->not->toContain('float')
        ->and($tableDef)->not->toContain('real')
        ->and($tableDef)->not->toContain('double')
        ->and($tableDef)->not->toContain('decimal')
        ->and($tableDef)->not->toContain('numeric');
});
