<?php

declare(strict_types=1);

use App\Jobs\ProcessCrmCommerceRollupRefresh;
use App\Support\CrmConfig;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config([
        'crm.foundation_enabled' => true,
        'crm.commerce_rollup_refresh.processing_enabled' => true,
        'crm.commerce_rollup_refresh.batch_size' => 50,
        'cache.default' => 'array',
    ]);
});

it('is a unique, after-commit, id-only refresh job with a finite TTL beyond its retries', function () {
    $job = new ProcessCrmCommerceRollupRefresh(42, 'XOF');
    $payload = serialize($job);
    $maximumDeclaredRetryHorizon = $job->tries * ($job->timeout + max($job->backoff()));

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->contactId)->toBe(42)
        ->and($job->currency)->toBe('XOF')
        ->and($job->uniqueId())->toBe('42:XOF')
        ->and($job->uniqueFor)->toBe(3600)
        ->and($job->uniqueFor)->toBeGreaterThan($maximumDeclaredRetryHorizon)
        ->and($job->afterCommit)->toBeTrue()
        ->and($job->queue)->toBe('crm')
        // ID-only payload: no email, order, refund or amount ever travels through Redis.
        ->and($payload)->not->toContain('@')
        ->and(mb_strtolower($payload))->not->toContain('email')
        ->and(mb_strtolower($payload))->not->toContain('customer')
        ->and(mb_strtolower($payload))->not->toContain('amount')
        ->and(mb_strtolower($payload))->not->toContain('order')
        ->and(mb_strtolower($payload))->not->toContain('refund');
});

it('distinguishes uniqueness per contact and per currency', function () {
    expect((new ProcessCrmCommerceRollupRefresh(7, 'XOF'))->uniqueId())->toBe('7:XOF')
        ->and((new ProcessCrmCommerceRollupRefresh(7, 'USD'))->uniqueId())->toBe('7:USD')
        ->and((new ProcessCrmCommerceRollupRefresh(8, 'XOF'))->uniqueId())->toBe('8:XOF');
});

it('validates the batch size strictly', function (mixed $value) {
    config(['crm.commerce_rollup_refresh.batch_size' => $value]);

    expect(fn () => CrmConfig::commerceRollupRefreshBatchSize())->toThrow(RuntimeException::class);
})->with([0, 101, '1.5', ' 50', true, null]);

it('fails closed for an invalid processing flag', function () {
    config(['crm.commerce_rollup_refresh.processing_enabled' => 'yes']);

    expect(fn () => CrmConfig::commerceRollupRefreshProcessingEnabled())->toThrow(RuntimeException::class);
});

it('registers a fail-closed non-overlapping sweep schedule that is disabled by default', function () {
    $routes = file_get_contents(base_path('routes/console.php'));

    expect($routes)->toContain('CrmConfig::commerceRollupRefreshProcessingEnabled()')
        ->and($routes)->toContain("Schedule::command('crm:sweep-commerce-rollup-refresh')")
        ->and($routes)->toContain('withoutOverlapping()')
        // Boot happens with the default (disabled) config, so the sweep is not scheduled.
        ->and(collect(Schedule::events())->contains(
            fn ($event): bool => str_contains((string) $event->command, 'crm:sweep-commerce-rollup-refresh'),
        ))->toBeFalse();
});
