<?php

declare(strict_types=1);

use App\Models\DownloadGrant;
use App\Models\DownloadLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithPaymentsDatabase;

uses(InteractsWithPaymentsDatabase::class);

/**
 * @return array{grant: DownloadGrant, grant_token: string, attempt_token: string, content: string}
 */
function p4c5Attempt(string $seed = 'file'): array
{
    config([
        'app.env' => 'testing',
        'delivery.enabled' => true,
        'delivery.attempt.ttl_seconds' => 900,
        'delivery.log.retention_days' => 90,
        'delivery.audit.ip_hash_key' => str_repeat('k', 32),
        'delivery.audit.ip_hash_key_version' => 1,
        'delivery.rate_limit.authorize_per_minute' => 100,
        'delivery.rate_limit.file_per_minute' => 100,
        'delivery.private_disks' => ['private'],
        'delivery.file.driver' => 'stream',
        'delivery.file.stream_chunk_bytes' => 65_536,
        'delivery.acceleration.driver' => 'none',
        'delivery.acceleration.x_accel_prefix' => null,
        'delivery.acceleration.x_accel_min_bytes' => 0,
    ]);

    Storage::fake('private');
    $grantToken = rtrim(strtr(base64_encode(hash('sha256', $seed, true)), '+/', '-_'), '=');
    $grant = DownloadGrant::factory()->create([
        'token_hash' => hash('sha256', $grantToken),
        'max_downloads' => 3,
    ]);
    $file = $grant->productFile()->firstOrFail();
    $content = substr(str_repeat('0123456789', (int) ceil(((int) $file->size_bytes) / 10)), 0, (int) $file->size_bytes);
    Storage::disk('private')->put($file->storage_path, $content);

    $authorization = test()->withHeader('Authorization', 'Bearer '.$grantToken)
        ->postJson("/api/downloads/{$grant->public_id}/authorize")
        ->assertOk();

    return [
        'grant' => $grant,
        'grant_token' => $grantToken,
        'attempt_token' => $authorization->getCookie('dl_attempt')->getValue(),
        'content' => $content,
    ];
}

it('refuses missing, grant, foreign and query credentials without consuming again', function (): void {
    $attempt = p4c5Attempt('first');
    $foreign = p4c5Attempt('foreign');
    $path = "/downloads/{$attempt['grant']->public_id}/file";

    $responses = [
        $this->get($path),
        $this->withCookie('dl_attempt', $attempt['grant_token'])->get($path),
        $this->withCookie('dl_attempt', $foreign['attempt_token'])->get($path),
        $this->withCookie('dl_attempt', $attempt['attempt_token'])->get($path.'?token=forbidden'),
    ];

    foreach ($responses as $response) {
        $response->assertNotFound()
            ->assertSeeText('Download unavailable.');
        expect($response->headers->get('Cache-Control'))
            ->toContain('private')
            ->toContain('no-store');
    }

    expect(DownloadLog::query()->where('download_grant_id', $attempt['grant']->id)->count())->toBe(1)
        ->and(DownloadGrant::query()->findOrFail($attempt['grant']->id)->downloads_count)->toBe(1);
});

it('streams a complete private file with bounded security headers and one terminal log', function (): void {
    $attempt = p4c5Attempt();
    $path = "/downloads/{$attempt['grant']->public_id}/file";

    $response = $this->withCookie('dl_attempt', $attempt['attempt_token'])->get($path);

    $response->assertOk()
        ->assertHeader('Accept-Ranges', 'bytes')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Content-Length', (string) strlen($attempt['content']));
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store')
        ->and($response->streamedContent())->toBe($attempt['content'])
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')
        ->and($response->headers->all())->not->toContain(storage_path())
        ->and(DownloadLog::query()->where('download_grant_id', $attempt['grant']->id)->sole()->status)->toBe('completed')
        ->and(DownloadGrant::query()->findOrFail($attempt['grant']->id)->downloads_count)->toBe(1);
});

it('answers HEAD with the same metadata and no body, log, quota or status mutation', function (): void {
    $attempt = p4c5Attempt();
    $path = "/downloads/{$attempt['grant']->public_id}/file";
    $before = DownloadLog::query()->where('download_grant_id', $attempt['grant']->id)->sole();

    $response = $this->withCookie('dl_attempt', $attempt['attempt_token'])->head($path);

    $response->assertOk()
        ->assertHeader('Accept-Ranges', 'bytes')
        ->assertHeader('Content-Length', (string) strlen($attempt['content']));
    expect($response->getContent())->toBe('')
        ->and(DownloadLog::query()->where('download_grant_id', $attempt['grant']->id)->count())->toBe(1)
        ->and(DownloadLog::query()->findOrFail($before->id)->status)->toBe('started')
        ->and(DownloadGrant::query()->findOrFail($attempt['grant']->id)->downloads_count)->toBe(1);
});

it('reuses one completed attempt for retries without a second log or quota unit', function (): void {
    $attempt = p4c5Attempt();
    $path = "/downloads/{$attempt['grant']->public_id}/file";

    $this->withCookie('dl_attempt', $attempt['attempt_token'])->get($path)->assertOk();
    $this->withCookie('dl_attempt', $attempt['attempt_token'])->get($path)->assertOk();
    $this->withCookie('dl_attempt', $attempt['attempt_token'])->head($path)->assertOk();

    expect(DownloadLog::query()->where('download_grant_id', $attempt['grant']->id)->count())->toBe(1)
        ->and(DownloadGrant::query()->findOrFail($attempt['grant']->id)->downloads_count)->toBe(1);
});

it('sanitizes the filename and fails closed on missing storage or incomplete acceleration', function (): void {
    $attempt = p4c5Attempt();
    DB::table('product_files')->where('id', $attempt['grant']->product_file_id)
        ->update(['original_name' => "report\r\nX-Injected: yes.zip"]);
    $path = "/downloads/{$attempt['grant']->public_id}/file";

    $safe = $this->withCookie('dl_attempt', $attempt['attempt_token'])->head($path);
    expect($safe->headers->get('Content-Disposition'))
        ->not->toContain("\r")
        ->not->toContain("\n")
        ->not->toContain('X-Injected:');

    $missing = p4c5Attempt('missing');
    Storage::disk('private')->delete($missing['grant']->productFile()->value('storage_path'));
    $this->withCookie('dl_attempt', $missing['attempt_token'])
        ->get("/downloads/{$missing['grant']->public_id}/file")
        ->assertNotFound()
        ->assertSeeText('Download unavailable.');
    expect(DownloadLog::query()->where('download_grant_id', $missing['grant']->id)->sole()->denial_reason_code)
        ->toBe('storage_failure');

    $accelerated = p4c5Attempt('accelerated');
    config(['delivery.file.driver' => 'x_accel', 'delivery.acceleration.driver' => 'x_accel']);
    $this->withCookie('dl_attempt', $accelerated['attempt_token'])
        ->get("/downloads/{$accelerated['grant']->public_id}/file")
        ->assertNotFound();
});
