<?php

declare(strict_types=1);

use App\Services\Delivery\ByteRangeParser;
use App\Services\Delivery\DownloadAuthorizationService;
use App\Services\Delivery\DownloadFileService;
use App\Support\AuthorizedDownloadAttempt;
use App\Support\DeliveryConfig;
use App\Support\InvalidByteRange;
use Tests\TestCase;

uses(TestCase::class);

it('bounds every public delivery runtime control fail closed', function (): void {
    config([
        'delivery.attempt.ttl_seconds' => 59,
        'delivery.rate_limit.authorize_per_minute' => 0,
        'delivery.rate_limit.file_per_minute' => 0,
        'delivery.file.stream_chunk_bytes' => 1,
    ]);

    expect(fn () => DeliveryConfig::attemptTtlSeconds())->toThrow(RuntimeException::class)
        ->and(fn () => DeliveryConfig::authorizeRateLimit())->toThrow(RuntimeException::class)
        ->and(fn () => DeliveryConfig::fileRateLimit())->toThrow(RuntimeException::class)
        ->and(fn () => DeliveryConfig::streamChunkBytes())->toThrow(RuntimeException::class);
});

it('parses only one strict bounded bytes range', function (): void {
    $parser = new ByteRangeParser;

    expect($parser->parse(null, 100))->toBeNull()
        ->and($parser->parse('bytes=0-9', 100)?->length())->toBe(10)
        ->and($parser->parse('bytes=90-', 100)?->length())->toBe(10)
        ->and($parser->parse('bytes=-10', 100)?->start)->toBe(90);

    foreach (['items=0-1', 'bytes=0-1,4-5', 'bytes=--1', 'bytes=+1-2'] as $invalid) {
        expect(fn () => $parser->parse($invalid, 100))->toThrow(InvalidByteRange::class);
    }
});

it('contains no permanent URL, raw-token logging or whole-file read in the delivery layer', function (): void {
    $sources = collect(File::allFiles(app_path()))
        ->filter(fn ($file): bool => str_contains(str_replace('\\', '/', $file->getRelativePathname()), 'Delivery')
            || str_contains($file->getFilename(), 'Download'))
        ->map(fn ($file): string => $file->getContents())
        ->implode("\n");

    expect($sources)->not->toContain('temporaryUrl(')
        ->not->toContain('Storage::url(')
        ->not->toContain('file_get_contents(')
        ->not->toContain('?token=')
        ->not->toContain('?attempt=')
        ->not->toContain('?grant=')
        ->and(preg_match('/\bLog::|\blogger\s*\(/', $sources))->toBe(0);
});

it('marks every raw delivery credential parameter as sensitive', function (): void {
    $parameters = [
        [DownloadAuthorizationService::class, 'authorize', 'rawGrantToken'],
        [DownloadAuthorizationService::class, 'assertCredentialShape', 'rawToken'],
        [DownloadFileService::class, 'prepare', 'rawAttemptToken'],
        [DownloadFileService::class, 'assertCredentialShape', 'rawToken'],
        [AuthorizedDownloadAttempt::class, '__construct', 'rawAttemptToken'],
    ];

    foreach ($parameters as [$class, $method, $name]) {
        $parameter = collect((new ReflectionMethod($class, $method))->getParameters())
            ->first(fn (ReflectionParameter $candidate): bool => $candidate->getName() === $name);

        expect($parameter)->not->toBeNull()
            ->and($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1);
    }
});
