<?php

declare(strict_types=1);

use App\Jobs\ProcessCrmSegmentGeneration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

beforeEach(function (): void {
    config([
        'crm.foundation_enabled' => true,
        'crm.segment_rebuild.enabled' => true,
        'crm.segment_rebuild.processing_enabled' => true,
        'crm.segment_rebuild.batch_size' => 50,
        'cache.default' => 'array',
    ]);
});

function p6a2SweepDefinition(): array
{
    return Fx::definition([
        'field' => 'commerce.net_revenue_minor',
        'operator' => 'gte',
        'currency' => 'XOF',
        'value' => 1,
    ]);
}

function p6a2GenerationCount(): int
{
    return (int) Fx::owner()->selectOne('SELECT COUNT(*) AS c FROM crm_segment_generations')->c;
}

// ── Sweeper ─────────────────────────────────────────────────────────────────────

it('fails closed when rebuild processing is disabled and emits only a counter', function () {
    config(['crm.segment_rebuild.processing_enabled' => false]);
    Bus::fake();

    expect(Artisan::call('crm:sweep-segment-generations'))->toBe(Command::SUCCESS)
        ->and(trim(Artisan::output()))->toBe('dispatched=0');
    Bus::assertNothingDispatched();
});

it('dispatches one unique job per due generation', function () {
    ['segment_id' => $a] = Fx::createSegmentWithVersion(p6a2SweepDefinition());
    ['segment_id' => $b] = Fx::createSegmentWithVersion(p6a2SweepDefinition());
    $genA = (int) Fx::owner()->selectOne('SELECT * FROM start_crm_segment_generation(?, ?)', [$a, 10])->generation_id;
    $genB = (int) Fx::owner()->selectOne('SELECT * FROM start_crm_segment_generation(?, ?)', [$b, 10])->generation_id;
    Bus::fake();

    expect(Artisan::call('crm:sweep-segment-generations'))->toBe(Command::SUCCESS)
        ->and(trim(Artisan::output()))->toBe('dispatched=2');

    Bus::assertDispatchedTimes(ProcessCrmSegmentGeneration::class, 2);
    Bus::assertDispatched(ProcessCrmSegmentGeneration::class, fn ($job): bool => $job->generationId === $genA);
    Bus::assertDispatched(ProcessCrmSegmentGeneration::class, fn ($job): bool => $job->generationId === $genB);
});

it('never dispatches a published generation', function () {
    $contactId = Fx::contact();
    Fx::rollup($contactId, 'XOF', gross: 5000);
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion(p6a2SweepDefinition());
    Fx::buildGeneration($segmentId);
    Bus::fake();

    Artisan::call('crm:sweep-segment-generations');

    Bus::assertNothingDispatched();
});

// ── Operator rebuild command ────────────────────────────────────────────────────

it('previews without creating any generation by default', function () {
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion(p6a2SweepDefinition());
    Bus::fake();

    Artisan::call('crm:rebuild-segment', ['segmentId' => (string) $segmentId]);
    $output = Artisan::output();

    expect($output)->toContain('mode=preview')
        ->toContain('generations_started=0')
        ->and(p6a2GenerationCount())->toBe(0);
    Bus::assertNothingDispatched();
});

it('mutates nothing with --execute while the rebuild flag is disabled', function () {
    config(['crm.segment_rebuild.enabled' => false]);
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion(p6a2SweepDefinition());
    Bus::fake();

    Artisan::call('crm:rebuild-segment', ['segmentId' => (string) $segmentId, '--execute' => true]);

    expect(Artisan::output())->toContain('mode=disabled')
        ->and(p6a2GenerationCount())->toBe(0);
    Bus::assertNothingDispatched();
});

it('starts and dispatches a durable generation with --execute and the flag enabled', function () {
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion(p6a2SweepDefinition());
    Bus::fake();

    Artisan::call('crm:rebuild-segment', ['segmentId' => (string) $segmentId, '--execute' => true]);
    $output = Artisan::output();

    expect($output)->toContain('mode=execute')
        ->toContain('generations_started=1')
        ->toContain('dispatched=1')
        ->and(p6a2GenerationCount())->toBe(1);
    Bus::assertDispatchedTimes(ProcessCrmSegmentGeneration::class, 1);
});

it('still creates a durable generation when queue processing is disabled', function () {
    config(['crm.segment_rebuild.processing_enabled' => false]);
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion(p6a2SweepDefinition());
    Bus::fake();

    Artisan::call('crm:rebuild-segment', ['segmentId' => (string) $segmentId, '--execute' => true]);

    // The work is never lost: the generation waits durably in `ready`.
    expect(Artisan::output())->toContain('generations_started=1')
        ->toContain('dispatched=0')
        ->and(p6a2GenerationCount())->toBe(1);
    Bus::assertNothingDispatched();
});

it('rejects an invalid segment id or batch size without mutating anything', function () {
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion(p6a2SweepDefinition());

    expect(Artisan::call('crm:rebuild-segment', ['segmentId' => 'abc', '--execute' => true]))->toBe(Command::FAILURE);
    expect(Artisan::call('crm:rebuild-segment', ['segmentId' => (string) $segmentId, '--execute' => true, '--batch-size' => '101']))->toBe(Command::FAILURE);
    expect(Artisan::call('crm:rebuild-segment', ['segmentId' => (string) $segmentId, '--execute' => true, '--batch-size' => '0']))->toBe(Command::FAILURE);

    expect(p6a2GenerationCount())->toBe(0);
});
