<?php

declare(strict_types=1);

use App\Models\DownloadGrant;
use App\Models\DownloadLog;
use Tests\Concerns\InteractsWithPaymentsDatabase;

uses(InteractsWithPaymentsDatabase::class);

it('serves start-end, open-ended and suffix ranges from one authenticated attempt', function (): void {
    $attempt = p4c5Attempt('ranges');
    $path = "/downloads/{$attempt['grant']->public_id}/file";
    $size = strlen($attempt['content']);

    $cases = [
        ['bytes=2-8', 2, 8],
        ['bytes=10-', 10, $size - 1],
        ['bytes=-7', $size - 7, $size - 1],
    ];

    foreach ($cases as [$header, $start, $end]) {
        $response = $this->withCookie('dl_attempt', $attempt['attempt_token'])
            ->withHeader('Range', $header)
            ->get($path);

        $response->assertStatus(206)
            ->assertHeader('Content-Range', "bytes {$start}-{$end}/{$size}")
            ->assertHeader('Content-Length', (string) ($end - $start + 1))
            ->assertHeader('Accept-Ranges', 'bytes');
        expect($response->streamedContent())->toBe(substr($attempt['content'], $start, $end - $start + 1));
    }

    expect(DownloadLog::query()->where('download_grant_id', $attempt['grant']->id)->count())->toBe(1)
        ->and(DownloadGrant::query()->findOrFail($attempt['grant']->id)->downloads_count)->toBe(1);
});

it('returns 416 for multi-range, wrong units, ambiguous, overflow and unsatisfiable ranges', function (): void {
    $attempt = p4c5Attempt('bad-ranges');
    $path = "/downloads/{$attempt['grant']->public_id}/file";
    $size = strlen($attempt['content']);

    $invalid = [
        'bytes=0-1,4-5',
        'items=0-1',
        'bytes=-',
        'bytes=--1',
        'bytes=5-4',
        'bytes='.$size.'-',
        'bytes=999999999999999999999999999999-',
        'bytes=+1-2',
        'bytes= 1-2',
    ];

    foreach ($invalid as $range) {
        $this->withCookie('dl_attempt', $attempt['attempt_token'])
            ->withHeader('Range', $range)
            ->get($path)
            ->assertStatus(416)
            ->assertHeader('Content-Range', "bytes */{$size}");
    }

    expect(DownloadLog::query()->where('download_grant_id', $attempt['grant']->id)->sole()->status)->toBe('started')
        ->and(DownloadGrant::query()->findOrFail($attempt['grant']->id)->downloads_count)->toBe(1);
});
