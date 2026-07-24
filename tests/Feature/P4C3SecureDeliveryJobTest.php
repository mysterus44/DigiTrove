<?php

declare(strict_types=1);

use App\Enums\GrantRevocationReason;
use App\Jobs\SecureDeliveryJob;
use App\Mail\OrderDownloadsReady;
use App\Models\DownloadGrant;
use App\Models\DownloadLog;
use App\Services\Delivery\GrantIssuanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\InteractsWithPaymentsDatabase;

uses(InteractsWithPaymentsDatabase::class);

/*
|--------------------------------------------------------------------------
| P4-C3 — Secure delivery job (D-035)
|--------------------------------------------------------------------------
*/

function p4cJobOrder(): int
{
    p4cConfig();
    $product = p4cProduct();
    p4cFile($product, name: 'a.zip');
    p4cFile($product, name: 'b.zip');
    ['order' => $order] = p4cDeliverableOrder($product);

    return $order->id;
}

it('issues grants and sends the downloads mail synchronously', function (): void {
    Mail::fake();
    $orderId = p4cJobOrder();

    (new SecureDeliveryJob($orderId))->handle(new GrantIssuanceService);

    expect(DownloadGrant::query()->whereNull('revoked_at')->count())->toBe(2);
    Mail::assertSent(OrderDownloadsReady::class, fn (OrderDownloadsReady $m): bool => $m->batch->orderId === $orderId
        && count($m->batch->grants) === 2);
});

it('builds fragment-only links in memory and persists neither raw token nor private path', function (): void {
    Mail::fake();
    $orderId = p4cJobOrder();

    (new SecureDeliveryJob($orderId))->handle(new GrantIssuanceService);

    Mail::assertSent(OrderDownloadsReady::class, function (OrderDownloadsReady $mail): bool {
        $html = $mail->render();

        foreach ($mail->batch->grants as $issued) {
            expect($html)->toContain('/'.$issued->grantPublicId.'#token='.$issued->rawToken)
                ->and($html)->not->toContain('?token=')
                ->and(json_encode(DownloadGrant::query()->findOrFail($issued->grantId)->getAttributes()))
                ->not->toContain($issued->rawToken);
        }

        return ! str_contains($html, 'storage_path')
            && ! str_contains($html, 'storage/app/private');
    });
});

it('does not queue the mail (sent, not queued)', function (): void {
    Mail::fake();
    $orderId = p4cJobOrder();

    (new SecureDeliveryJob($orderId))->handle(new GrantIssuanceService);

    Mail::assertNotQueued(OrderDownloadsReady::class);
    Mail::assertSent(OrderDownloadsReady::class);
});

it('revokes the just-issued grants as delivery_failed when the mail throws', function (): void {
    $orderId = p4cJobOrder();
    // Force the synchronous send to fail.
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

    $failure = null;
    try {
        (new SecureDeliveryJob($orderId))->handle(new GrantIssuanceService);
    } catch (RuntimeException $exception) {
        $failure = $exception;
    }

    expect($failure)->not->toBeNull()
        ->and($failure->getMessage())->toBe('Secure delivery could not be completed.')
        ->and($failure->getMessage())->not->toContain('smtp down')
        ->and($failure->getMessage())->not->toContain('token')
        ->and($failure->getMessage())->not->toContain('@')
        ->and(DownloadGrant::query()->whereNull('revoked_at')->count())->toBe(0)
        ->and(DownloadGrant::query()->where('revoked_reason_code', GrantRevocationReason::DeliveryFailed->value)->count())->toBe(2);
});

it('writes no download log and consumes no quota', function (): void {
    Mail::fake();
    $orderId = p4cJobOrder();

    (new SecureDeliveryJob($orderId))->handle(new GrantIssuanceService);

    expect(DownloadLog::query()->count())->toBe(0)
        ->and(DownloadGrant::query()->sum('downloads_count'))->toBe(0);
});

it('refuses to run inside an ambient transaction', function (): void {
    Mail::fake();
    $orderId = p4cJobOrder();

    DB::beginTransaction();
    try {
        expect(fn () => (new SecureDeliveryJob($orderId))->handle(new GrantIssuanceService))
            ->toThrow(RuntimeException::class);
    } finally {
        DB::rollBack();
    }

    Mail::assertNothingSent();
});

it('reissues on a retry with fresh tokens and revokes the stale grants as uncertain', function (): void {
    Mail::fake();
    $orderId = p4cJobOrder();

    (new SecureDeliveryJob($orderId))->handle(new GrantIssuanceService);
    $firstActive = DownloadGrant::query()->whereNull('revoked_at')->pluck('id')->all();

    // A retry (at-least-once): the old grants become uncertain, fresh ones issue.
    (new SecureDeliveryJob($orderId))->handle(new GrantIssuanceService);
    $secondActive = DownloadGrant::query()->whereNull('revoked_at')->pluck('id')->all();

    expect(count($secondActive))->toBe(2)
        ->and(array_intersect($firstActive, $secondActive))->toBe([])
        ->and(DownloadGrant::query()->where('revoked_reason_code', GrantRevocationReason::DeliveryUncertainReissue->value)->count())->toBe(2);
});

it('retries the historical pairs without delivering a file added after the first mail', function (): void {
    Mail::fake();
    p4cConfig();
    $product = p4cProduct();
    p4cFile($product, name: 'purchased.zip');
    ['order' => $order] = p4cDeliverableOrder($product);

    (new SecureDeliveryJob($order->id))->handle(new GrantIssuanceService);
    p4cFile($product, name: 'added-later.zip');
    (new SecureDeliveryJob($order->id))->handle(new GrantIssuanceService);

    $sent = Mail::sent(OrderDownloadsReady::class);
    $last = $sent->last();
    $names = array_map(fn ($grant): string => $grant->fileName, $last->batch->grants);

    expect($sent)->toHaveCount(2)
        ->and($names)->toBe(['purchased.zip'])
        ->and(DownloadGrant::query()->whereNull('revoked_at')->count())->toBe(1);
});

it('allows only the three explicitly reviewed P4-C4/C5 download routes', function (): void {
    $applicationDownloadRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(function ($route): bool {
            $action = $route->getActionName();

            return str_contains(mb_strtolower($route->uri()), 'download')
                && ($action === 'Closure' || str_starts_with($action, 'App\\'));
        })
        ->map(fn ($route): string => implode('|', $route->methods()).' '.$route->uri().' '.$route->getActionName())
        ->sort()
        ->values()
        ->all();

    expect($applicationDownloadRoutes)->toBe([
        'GET|HEAD downloads/{grantPublicId} App\Http\Controllers\DownloadLandingController',
        'GET|HEAD downloads/{grantPublicId}/file App\Http\Controllers\DownloadFileController',
        'POST api/downloads/{grantPublicId}/authorize App\Http\Controllers\Api\DownloadAuthorizationController',
    ]);
});
