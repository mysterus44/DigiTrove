<?php

declare(strict_types=1);

use App\Enums\GrantRevocationReason;
use App\Models\DownloadGrant;
use App\Models\DownloadLog;
use App\Services\Delivery\DownloadAuthorizationService;
use App\Services\Delivery\DownloadOperationsService;
use App\Support\DownloadRequestContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithPaymentsDatabase;

uses(InteractsWithPaymentsDatabase::class);

function p4c6Configure(): void
{
    config([
        'delivery.operations.started_reconcile_minutes' => 30,
        'delivery.operations.revoked_grant_retention_days' => 365,
        'delivery.operations.abuse_window_hours' => 24,
        'delivery.operations.abuse_distinct_ip_threshold' => 3,
        'delivery.operations.batch_size' => 100,
    ]);
}

beforeEach(function (): void {
    p4c6Configure();
});

it('reconciles only stale started logs once and never returns their consumed quota', function (): void {
    $attempt = p4c5Attempt('stale');
    p4c6Configure();
    $authorization = app(DownloadAuthorizationService::class);
    $oldToken = rtrim(strtr(base64_encode(hash('sha256', 'stale-old', true)), '+/', '-_'), '=');

    // The first fresh attempt blocks replay, so expire it through its allowed
    // terminal transition before creating the historical probe.
    DB::table('download_logs')->where('download_grant_id', $attempt['grant']->id)->update([
        'status' => 'denied',
        'denial_reason_code' => 'delivery_interrupted',
        'terminal_at' => now(),
    ]);
    $grant = DownloadGrant::factory()->create([
        'token_hash' => hash('sha256', $oldToken),
        'max_downloads' => 3,
    ]);
    $file = $grant->productFile()->firstOrFail();
    Storage::disk('private')->put($file->storage_path, str_repeat('x', (int) $file->size_bytes));
    $authorization->authorize(
        (string) $grant->public_id,
        $oldToken,
        new DownloadRequestContext(hash('sha256', 'ip'), 1, null),
        now()->subHours(2)->toImmutable(),
    );
    $recent = p4c5Attempt('recent');
    p4c6Configure();

    $service = app(DownloadOperationsService::class);
    expect($service->reconcileStarted())->toBe(1)
        ->and($service->reconcileStarted())->toBe(0);

    $staleLog = DownloadLog::query()->where('download_grant_id', $grant->id)->sole();
    expect($staleLog->status)->toBe('denied')
        ->and($staleLog->denial_reason_code)->toBe('delivery_interrupted')
        ->and(DownloadGrant::query()->findOrFail($grant->id)->downloads_count)->toBe(1)
        ->and(DownloadLog::query()->where('download_grant_id', $recent['grant']->id)->sole()->status)->toBe('started')
        ->and(DownloadGrant::query()->findOrFail($recent['grant']->id)->downloads_count)->toBe(1);
});

it('detects only grants above the distinct pseudonymous IP threshold', function (): void {
    p4c5Attempt('config');
    p4c6Configure();
    $abusive = DownloadGrant::factory()->create(['max_downloads' => 5]);
    $quiet = DownloadGrant::factory()->create(['max_downloads' => 5]);

    foreach (range(1, 4) as $index) {
        DownloadLog::factory()->forGrant($abusive)->create([
            'ip_hash' => hash('sha256', 'abusive-'.$index),
            'ip_hash_key_version' => 1,
        ]);
    }
    foreach (range(1, 3) as $index) {
        DownloadLog::factory()->forGrant($quiet)->create([
            'ip_hash' => hash('sha256', 'quiet-'.$index),
            'ip_hash_key_version' => 1,
        ]);
    }

    $alerts = app(DownloadOperationsService::class)->detectAbuse();

    expect($alerts)->toBe([[
        'grant_id' => $abusive->id,
        'distinct_ip_count' => 4,
    ]])
        ->and(json_encode($alerts, JSON_THROW_ON_ERROR))->not->toContain('abusive-')
        ->not->toContain('quiet-');
});

it('revokes an active grant idempotently without overwriting its history', function (): void {
    $grant = DownloadGrant::factory()->create();
    $service = app(DownloadOperationsService::class);

    expect($service->revokeGrant($grant->id, GrantRevocationReason::ManualSecurityReissue->value))->toBeTrue()
        ->and($service->revokeGrant($grant->id, GrantRevocationReason::ManualSecurityReissue->value))->toBeTrue()
        ->and($service->revokeGrant($grant->id, GrantRevocationReason::DeliveryFailed->value))->toBeFalse();

    $fresh = DownloadGrant::query()->findOrFail($grant->id);
    expect($fresh->revoked_at)->not->toBeNull()
        ->and($fresh->revoked_reason_code)->toBe(GrantRevocationReason::ManualSecurityReissue->value);
});

it('purges only terminal logs past retention and keeps grants and commercial lineage', function (): void {
    $grant = DownloadGrant::factory()->create(['max_downloads' => 4]);
    $old = now()->subDays(3);
    $purgeable = DownloadLog::factory()->forGrant($grant)->create([
        'attempt_expires_at' => $old->copy()->addMinutes(10),
        'retention_until' => $old->copy()->addDay(),
        'created_at' => $old,
    ]);
    DB::table('download_logs')->where('id', $purgeable->id)->update([
        'status' => 'completed',
        'terminal_at' => $old->copy()->addMinute(),
    ]);
    $recent = DownloadLog::factory()->forGrant($grant)->create();
    DB::table('download_logs')->where('id', $recent->id)->update([
        'status' => 'completed',
        'terminal_at' => now(),
    ]);

    $service = app(DownloadOperationsService::class);
    expect($service->purgeLogs(dryRun: true))->toBe(1)
        ->and(DownloadLog::query()->whereKey($purgeable->id)->exists())->toBeTrue()
        ->and($service->purgeLogs())->toBe(1)
        ->and(DownloadLog::query()->whereKey($purgeable->id)->exists())->toBeFalse()
        ->and(DownloadLog::query()->whereKey($recent->id)->exists())->toBeTrue()
        ->and(DownloadGrant::query()->whereKey($grant->id)->exists())->toBeTrue()
        ->and(DB::table('orders')->count())->toBeGreaterThan(0)
        ->and(DB::table('order_items')->count())->toBeGreaterThan(0)
        ->and(DB::table('product_files')->count())->toBeGreaterThan(0);
});

it('registers bounded non-interactive commands and non-overlapping schedules', function (): void {
    $commands = Artisan::all();
    foreach (['downloads:reconcile', 'downloads:detect-abuse', 'downloads:purge', 'downloads:metrics', 'downloads:revoke'] as $name) {
        expect($commands)->toHaveKey($name);
    }

    expect(Artisan::call('downloads:metrics'))->toBe(Command::SUCCESS);
    $output = Artisan::output();
    expect($output)->not->toMatch('/[A-Za-z0-9_-]{43}/')
        ->not->toMatch('/[0-9a-f]{64}/')
        ->not->toContain('@');

    $events = collect(Schedule::events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'downloads:'))
        ->values();
    expect($events)->toHaveCount(4);
    foreach ($events as $event) {
        expect($event->withoutOverlapping)->toBeTrue();
    }
});
