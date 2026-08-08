<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\RollupRefreshFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

beforeEach(function (): void {
    config([
        'crm.foundation_enabled' => true,
        'crm.commerce_rollup_backfill.enabled' => true,
        'crm.commerce_rollup_refresh.processing_enabled' => false,
        'cache.default' => 'array',
    ]);
});

function p6a13RunCount(): int
{
    return (int) DB::connection('pgsql_migration')
        ->selectOne('SELECT COUNT(*) AS c FROM crm_commerce_rollup_backfill_runs')->c;
}

function p6a13Call(array $options = []): string
{
    Artisan::call('crm:backfill-commerce-rollups', $options);

    return Artisan::output();
}

// ── Dry-run par défaut ──────────────────────────────────────────────────────────

it('is a read-only dry-run by default and mutates nothing', function () {
    $contactId = Fx::contact();
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], $contactId);
    $generationBefore = (int) Fx::outbox($contactId, 'XOF')->requested_generation;

    $output = p6a13Call();

    expect($output)->toContain('mode=dry-run')
        ->toContain('candidate_pairs_previewed=1')
        ->toContain('mutations=0')
        // No run row, no generation bump, no rollup.
        ->and(p6a13RunCount())->toBe(0)
        ->and((int) Fx::outbox($contactId, 'XOF')->requested_generation)->toBe($generationBefore)
        ->and(Fx::rollup($contactId, 'XOF'))->toBeNull();
});

it('dry-runs even when the backfill flag is enabled, until --execute is passed', function () {
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], Fx::contact());

    expect(p6a13Call())->toContain('mode=dry-run');
    expect(p6a13RunCount())->toBe(0);
});

it('bounds the dry-run preview by max-batches', function () {
    foreach (range(1, 3) as $ignored) {
        Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], Fx::contact());
    }

    $output = p6a13Call(['--batch-size' => '1', '--max-batches' => '2']);

    expect($output)->toContain('candidate_pairs_previewed=2');
});

// ── Double barrière ─────────────────────────────────────────────────────────────

it('mutates nothing with --execute while the feature flag is disabled', function () {
    config(['crm.commerce_rollup_backfill.enabled' => false]);
    $contactId = Fx::contact();
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], $contactId);
    $generationBefore = (int) Fx::outbox($contactId, 'XOF')->requested_generation;

    $output = p6a13Call(['--execute' => true]);

    expect($output)->toContain('mode=disabled')
        ->toContain('enqueued_pairs=0')
        ->and(p6a13RunCount())->toBe(0)
        ->and((int) Fx::outbox($contactId, 'XOF')->requested_generation)->toBe($generationBefore);
});

// ── Exécution explicite ─────────────────────────────────────────────────────────

it('creates a run and enqueues historical pairs with --execute and the flag enabled', function () {
    $contactId = Fx::contact();
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], $contactId);
    $generationBefore = (int) Fx::outbox($contactId, 'XOF')->requested_generation;

    $output = p6a13Call(['--execute' => true]);

    expect($output)->toContain('mode=execute')
        ->toContain('enqueued_pairs=1')
        ->toContain('status=completed')
        ->and(p6a13RunCount())->toBe(1)
        ->and((int) Fx::outbox($contactId, 'XOF')->requested_generation)->toBe($generationBefore + 1);
});

it('stops at max-batches and resumes the same durable run from its cursor', function () {
    $c1 = Fx::contact();
    $c2 = Fx::contact();
    $c3 = Fx::contact();
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], $c1);
    Fx::attribute(Fx::paidOrder(4000, 'XOF')['order'], $c2);
    Fx::attribute(Fx::paidOrder(3000, 'XOF')['order'], $c3);

    $first = p6a13Call(['--execute' => true, '--batch-size' => '1', '--max-batches' => '1']);
    expect($first)->toContain('enqueued_pairs=1')->toContain('status=ready');

    preg_match('/run_id=(\d+)/', $first, $matches);
    $runId = (int) $matches[1];

    $second = p6a13Call(['--execute' => true, '--run' => (string) $runId, '--batch-size' => '1', '--max-batches' => '10']);
    expect($second)->toContain('run_id='.$runId)
        // Two remaining pairs, then the completing empty batch.
        ->toContain('enqueued_pairs=2')
        ->toContain('status=completed')
        // Still exactly ONE durable run: resuming never starts a second one.
        ->and(p6a13RunCount())->toBe(1);
});

it('reports the separate P6-A1.2 drain flag without ever bypassing it', function () {
    config(['crm.commerce_rollup_refresh.processing_enabled' => false]);
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], Fx::contact());

    $output = p6a13Call(['--execute' => true]);

    // The backfill legitimately fills the outbox even while the drain is disabled.
    expect($output)->toContain('rollup_refresh_processing_enabled=0')
        ->toContain('enqueued_pairs=1');
});

it('rejects out-of-range options without mutating anything', function () {
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], Fx::contact());

    foreach ([['--batch-size' => '0'], ['--batch-size' => '101'], ['--max-batches' => '0'], ['--max-batches' => '101']] as $options) {
        Artisan::call('crm:backfill-commerce-rollups', $options + ['--execute' => true]);
        expect(Artisan::output())->toContain('backfill=invalid_options');
    }

    expect(p6a13RunCount())->toBe(0);
});

it('fails cleanly on an invalid run identifier', function () {
    expect(Artisan::call('crm:backfill-commerce-rollups', ['--execute' => true, '--run' => 'abc']))
        ->toBe(Command::FAILURE);
});
