<?php

declare(strict_types=1);

use App\Jobs\ProcessCrmSegmentGeneration;
use App\Support\CrmConfig;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config([
        'crm.foundation_enabled' => true,
        'crm.segment_rebuild.enabled' => true,
        'crm.segment_rebuild.processing_enabled' => true,
        'crm.segment_rebuild.batch_size' => 50,
        'cache.default' => 'array',
    ]);
});

it('is a unique, after-commit, id-only job with a finite TTL beyond its retries', function () {
    $job = new ProcessCrmSegmentGeneration(42);
    $payload = serialize($job);
    $maximumDeclaredRetryHorizon = $job->tries * ($job->timeout + max($job->backoff()));

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->generationId)->toBe(42)
        ->and($job->uniqueId())->toBe('42')
        ->and($job->uniqueFor)->toBe(3600)
        ->and($job->uniqueFor)->toBeGreaterThan($maximumDeclaredRetryHorizon)
        ->and($job->afterCommit)->toBeTrue()
        ->and($job->queue)->toBe('crm')
        // ID-only payload: no definition, no contact ids, no money, no e-mail.
        ->and($payload)->not->toContain('@')
        ->and(mb_strtolower($payload))->not->toContain('email')
        ->and(mb_strtolower($payload))->not->toContain('definition')
        ->and(mb_strtolower($payload))->not->toContain('criteria')
        ->and(mb_strtolower($payload))->not->toContain('contact')
        ->and(mb_strtolower($payload))->not->toContain('currency');
});

it('keeps the job payload to a single generation id', function () {
    $parameters = (new ReflectionClass(ProcessCrmSegmentGeneration::class))->getConstructor()->getParameters();

    expect($parameters)->toHaveCount(1)
        ->and($parameters[0]->getName())->toBe('generationId')
        ->and((string) $parameters[0]->getType())->toBe('int');
});

it('bounds the number of batches a single job run may process', function () {
    expect(ProcessCrmSegmentGeneration::MAX_BATCHES_PER_JOB)->toBe(10)
        ->and(ProcessCrmSegmentGeneration::MAX_BATCHES_PER_JOB)->toBeLessThanOrEqual(10);
});

it('validates the rebuild configuration strictly', function (mixed $value) {
    config(['crm.segment_rebuild.batch_size' => $value]);

    expect(fn () => CrmConfig::segmentRebuildBatchSize())->toThrow(RuntimeException::class);
})->with([0, 101, '1.5', ' 50', true, null]);

it('fails closed on an invalid rebuild flag', function () {
    config(['crm.segment_rebuild.processing_enabled' => 'yes']);
    expect(fn () => CrmConfig::segmentRebuildProcessingEnabled())->toThrow(RuntimeException::class);

    config(['crm.segment_rebuild.enabled' => 'yes']);
    expect(fn () => CrmConfig::segmentRebuildEnabled())->toThrow(RuntimeException::class);
});

it('registers a fail-closed non-overlapping sweep schedule that is disabled by default', function () {
    $routes = file_get_contents(base_path('routes/console.php'));

    expect($routes)->toContain('CrmConfig::segmentRebuildProcessingEnabled()')
        ->and($routes)->toContain("Schedule::command('crm:sweep-segment-generations')")
        ->and($routes)->toContain('withoutOverlapping()')
        // Boot happens with the default (disabled) config, so nothing is scheduled.
        ->and(collect(Schedule::events())->contains(
            fn ($event): bool => str_contains((string) $event->command, 'crm:sweep-segment-generations'),
        ))->toBeFalse();
});

it('versions the segment rebuild configuration fail-closed and disabled by default', function () {
    $example = file_get_contents(base_path('.env.example'));

    expect($example)->toMatch('/(?m)^CRM_SEGMENT_REBUILD_ENABLED=false$/')
        ->toMatch('/(?m)^CRM_SEGMENT_REBUILD_PROCESSING_ENABLED=false$/')
        ->toMatch('/(?m)^CRM_SEGMENT_REBUILD_BATCH_SIZE=50$/');

    expect(config('crm.segment_rebuild.enabled'))->toBeTrue();   // overridden in beforeEach
});
