<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\RollupRefreshFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

function p6a12SchemaOwner(): Connection
{
    return DB::connection('pgsql_migration');
}

function p6a12Constraint(string $name): bool
{
    return (bool) p6a12SchemaOwner()->selectOne(
        'SELECT EXISTS(SELECT 1 FROM pg_constraint WHERE conname = ?) AS present',
        [$name],
    )->present;
}

it('keeps 000023 as exactly one migration while the frontier moved to 000024', function () {
    $root = dirname(__DIR__, 2);
    // P6-A1.3 (D-048) legitimately adds 000024; 000025 does not exist yet.
    expect(glob($root.'/database/migrations/*.php'))->toHaveCount(44)
        ->and(glob($root.'/database/migrations/2026_07_14_000023*.php'))->toHaveCount(1)
        ->and(glob($root.'/database/migrations/2026_07_14_000029*.php'))->toBe([]);
});

it('creates the outbox with exactly the durable coordination columns and no PII', function () {
    $columns = array_map(
        static fn (object $c): string => $c->column_name,
        p6a12SchemaOwner()->select("SELECT column_name FROM information_schema.columns WHERE table_name = 'crm_commerce_rollup_refresh_outbox' ORDER BY ordinal_position"),
    );

    sort($columns);
    $expected = [
        'attempt_count', 'available_at', 'contact_id', 'created_at', 'currency',
        'last_error_code', 'processed_generation', 'requested_generation',
        'terminal_at', 'terminal_reason', 'updated_at',
    ];
    expect($columns)->toBe($expected);

    foreach (['email', 'customer_email', 'name', 'phone', 'user_id', 'visitor_id', 'order_id', 'payment_id', 'amount_minor'] as $forbidden) {
        expect($columns)->not->toContain($forbidden);
    }
});

it('keys the outbox on (contact_id, currency) and enforces the generation and terminal invariants', function () {
    expect(p6a12Constraint('crm_commerce_rollup_refresh_outbox_pkey'))->toBeTrue()
        ->and(p6a12Constraint('crm_commerce_rollup_refresh_outbox_currency_check'))->toBeTrue()
        ->and(p6a12Constraint('crm_commerce_rollup_refresh_outbox_generation_order_check'))->toBeTrue()
        ->and(p6a12Constraint('crm_commerce_rollup_refresh_outbox_terminal_pairing_check'))->toBeTrue()
        ->and(p6a12Constraint('crm_commerce_rollup_refresh_outbox_terminal_reason_check'))->toBeTrue();

    $pk = array_map(
        static fn (object $r): string => $r->attname,
        p6a12SchemaOwner()->select("SELECT a.attname FROM pg_constraint c JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY(c.conkey) WHERE c.conname = 'crm_commerce_rollup_refresh_outbox_pkey' ORDER BY a.attnum"),
    );
    expect($pk)->toBe(['contact_id', 'currency']);
});

it('rejects a generation order violation where requested is below processed', function () {
    $contactId = Fx::contact();

    expect(fn () => p6a12SchemaOwner()->insert(
        'INSERT INTO crm_commerce_rollup_refresh_outbox (contact_id, currency, requested_generation, processed_generation, attempt_count, available_at, created_at, updated_at) VALUES (?, ?, 1, 2, 0, NOW(), NOW(), NOW())',
        [$contactId, 'XOF'],
    ))->toThrow(QueryException::class);
});

it('is owned by the restricted CRM executor', function () {
    $owner = (string) p6a12SchemaOwner()->selectOne(
        "SELECT tableowner FROM pg_tables WHERE tablename = 'crm_commerce_rollup_refresh_outbox'",
    )->tableowner;
    expect($owner)->toBe('digitrove_crm_executor');
});
