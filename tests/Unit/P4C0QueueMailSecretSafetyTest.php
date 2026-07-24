<?php

declare(strict_types=1);

use App\Events\OrderPaid;
use App\Jobs\SecureDeliveryJob;
use App\Mail\OrderDownloadsReady;
use App\Support\DeliveryConfig;
use App\Support\IssuedGrant;
use App\Support\IssuedGrantBatch;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// Booted container (no DB): config, events and the bus fake.
uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| P4-C0 — Queue & Mail secret safety (D-035)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    config(['delivery' => [
        'enabled' => true,
        'grant' => ['ttl_minutes' => 10080, 'max_downloads' => 5],
        'job' => ['unique_seconds' => 3600],
        'download_base_url' => 'https://dl.example.com/d',
        'require_https' => true,
    ]]);
});

it('does not queue delivery when the pipeline is disabled', function (): void {
    config(['delivery.enabled' => false]);
    Bus::fake();

    event(new OrderPaid(42));

    Bus::assertNothingDispatched();
});

it('queues exactly one order-id-only job when the pipeline is enabled', function (): void {
    Bus::fake();

    event(new OrderPaid(42));

    Bus::assertDispatchedTimes(SecureDeliveryJob::class, 1);
    Bus::assertDispatched(SecureDeliveryJob::class, fn (SecureDeliveryJob $job): bool => $job->orderId === 42);
});

it('serialises a job payload that contains only the order id', function (): void {
    $job = new SecureDeliveryJob(4242);

    $serialized = serialize($job);

    expect($job->orderId)->toBe(4242)
        ->and($job->uniqueId())->toBe('4242')
        ->and($serialized)->toContain('4242')
        // no token / e-mail / grant list / public id can be present — there are no such fields
        ->and($serialized)->not->toContain('token')
        ->and($serialized)->not->toContain('@')
        ->and($serialized)->not->toContain('grant');
});

it('creates a real queue payload without a token, email, public id or grant data', function (): void {
    $connection = Queue::connection('sync');
    $createPayload = new ReflectionMethod($connection, 'createPayload');
    $payload = $createPayload->invoke($connection, new SecureDeliveryJob(4242), 'delivery');

    expect($payload)->toContain('4242')
        ->and(mb_strtolower($payload))->not->toContain('rawtoken')
        ->and(mb_strtolower($payload))->not->toContain('token_hash')
        ->and($payload)->not->toMatch('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i')
        ->and(mb_strtolower($payload))->not->toContain('customeremail')
        ->and(mb_strtolower($payload))->not->toContain('grantpublicid');
});

it('marks the job unique per order for the configured window', function (): void {
    $job = new SecureDeliveryJob(7);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->uniqueFor())->toBe(3600)
        ->and($job->afterCommit)->toBeTrue();
});

it('never queues the delivery Mailable', function (): void {
    expect(is_subclass_of(OrderDownloadsReady::class, ShouldQueue::class))->toBeFalse();
});

it('rejects queueing or serialising a delivery Mailable before raw tokens can leave memory', function (): void {
    $batch = new IssuedGrantBatch(42, 'buyer@example.test', [
        new IssuedGrant(1, '00000000-0000-4000-8000-000000000001', 2, 3, str_repeat('A', 43), 'file.zip'),
    ]);
    $mailable = new OrderDownloadsReady($batch);

    expect(fn () => Mail::to('buyer@example.test')->queue($mailable))
        ->toThrow(RuntimeException::class, 'Secure delivery mail cannot be queued.')
        ->and(fn () => serialize($mailable))
        ->toThrow(RuntimeException::class, 'Secure delivery mail cannot be serialized.');
});

it('OrderPaid carries only the integer order id', function (): void {
    $params = (new ReflectionClass(OrderPaid::class))->getConstructor()->getParameters();

    expect($params)->toHaveCount(1)
        ->and($params[0]->getName())->toBe('orderId')
        ->and((string) $params[0]->getType())->toBe('int');
});

it('fails closed on an out-of-range TTL', function (): void {
    config(['delivery.grant.ttl_minutes' => 0]);

    expect(fn () => DeliveryConfig::grantTtlMinutes())->toThrow(RuntimeException::class);
});

it('fails closed on a missing download base URL when enabled', function (): void {
    config(['delivery.download_base_url' => '']);

    expect(fn () => DeliveryConfig::downloadBaseUrl())->toThrow(RuntimeException::class);
});

it('refuses a non-HTTPS download base URL outside local/testing', function (): void {
    config(['delivery.download_base_url' => 'http://dl.example.com/d', 'delivery.require_https' => true]);

    expect(fn () => DeliveryConfig::downloadBaseUrl())->toThrow(RuntimeException::class);
});

it('refuses a logging mail transport before dispatching or issuing credentials', function (): void {
    config(['mail.default' => 'log']);

    expect(fn () => DeliveryConfig::assertPipelineReady())
        ->toThrow(RuntimeException::class, 'The delivery mail transport is unsafe.');
});

it('refuses credentials, query strings and fragments in the configured base URL', function (string $url): void {
    config(['delivery.download_base_url' => $url]);

    expect(fn () => DeliveryConfig::downloadBaseUrl())->toThrow(RuntimeException::class);
})->with([
    'credentials' => 'https://user:password@dl.example.com/d',
    'query' => 'https://dl.example.com/d?debug=1',
    'fragment' => 'https://dl.example.com/d#unsafe',
]);

it('versions no mail-provider secret and keeps real delivery disabled by default', function (): void {
    $example = file_get_contents(base_path('.env.example'));
    $setup = file_get_contents(base_path('docs/integrations/MAIL_PROVIDER_SETUP.md'));

    expect($example)->toContain('DELIVERY_PIPELINE_ENABLED=false')
        ->and($example)->toContain('QUEUE_CONNECTION=redis')
        ->and($example)->toMatch('/(?m)^MAIL_PASSWORD=$/')
        ->and($example)->toMatch('/(?m)^MAIL_SCHEME=smtp$/')
        ->and($example)->not->toMatch('/(?m)^MAIL_MAILER=log$/')
        ->and($setup)->toContain('never commit')
        ->and($setup)->toContain('All examples below are **commented and inactive**');
});
