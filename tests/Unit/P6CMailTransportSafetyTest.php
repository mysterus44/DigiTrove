<?php

declare(strict_types=1);

use App\Support\MailTransportGuard as Guard;
use Tests\TestCase;

// Pest only binds TestCase in `Feature`; this contract reads `config()`, so it needs
// the application booted. No database is touched.
uses(TestCase::class);

/**
 * D-030 debt, closed structurally for P6-C.
 *
 * `config/mail.php` declares `'default' => env('MAIL_MAILER', 'log')`, so a deployment
 * that forgets the variable falls back to a transport that writes the message — and a
 * cart-resume link is a live capability. The guard must refuse before any such link is
 * generated, and it must judge the transport ACTUALLY RESOLVED.
 */
beforeEach(function (): void {
    config([
        'mail.from.address' => 'no-reply@digitrove.test',
        'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => 'smtp.example.test', 'port' => 587],
    ]);
});

it('accepts a fully configured delivering transport', function () {
    config(['mail.default' => 'smtp']);

    expect(Guard::isSafe())->toBeTrue();
    Guard::assertSafe();
});

it('refuses every transport that records or discards the message', function (string $mailer, string $transport) {
    config(['mail.default' => $mailer, "mail.mailers.{$mailer}" => ['transport' => $transport]]);

    expect(Guard::isSafe())->toBeFalse()
        ->and(fn () => Guard::assertSafe())->toThrow(RuntimeException::class, 'record or discard');
})->with([
    'log' => ['log', 'log'],
    'array' => ['array', 'array'],
    'null' => ['null', 'null'],
]);

/**
 * THE regression that matters: the repository default. A deployment that never sets
 * MAIL_MAILER lands here.
 */
it('refuses the repository fallback when MAIL_MAILER is absent', function () {
    // Exactly what config/mail.php resolves to with no env var set.
    config(['mail.default' => 'log', 'mail.mailers.log' => ['transport' => 'log', 'channel' => null]]);

    expect(Guard::isSafe())->toBeFalse();
});

it('refuses an undefined, empty or unknown transport', function () {
    config(['mail.default' => 'ghost']);
    expect(Guard::isSafe())->toBeFalse();

    config(['mail.default' => '']);
    expect(Guard::isSafe())->toBeFalse();

    config(['mail.default' => null]);
    expect(Guard::isSafe())->toBeFalse();

    // A driver nobody reviewed is refused rather than trusted.
    config(['mail.default' => 'exotic', 'mail.mailers.exotic' => ['transport' => 'carrier-pigeon']]);
    expect(fn () => Guard::assertSafe())->toThrow(RuntimeException::class, 'not a reviewed delivery transport');

    config(['mail.default' => 'headless', 'mail.mailers.headless' => ['host' => 'x']]);
    expect(fn () => Guard::assertSafe())->toThrow(RuntimeException::class, 'declares no transport');
});

/**
 * A named `smtp` mailer is NOT a ready one — the shipped example has an empty
 * MAIL_HOST, which is precisely this case.
 */
it('refuses a named transport that is not actually configured', function () {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '']]);
    expect(fn () => Guard::assertSafe())->toThrow(RuntimeException::class, 'missing [host]');

    config(['mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '   ']]);
    expect(Guard::isSafe())->toBeFalse();

    config(['mail.mailers.smtp' => ['transport' => 'smtp']]);
    expect(Guard::isSafe())->toBeFalse();
});

it('refuses when no sender address is configured', function () {
    config(['mail.default' => 'smtp', 'mail.from.address' => '']);
    expect(fn () => Guard::assertSafe())->toThrow(RuntimeException::class, 'sender address');
});

/**
 * A composition is only as safe as its WEAKEST leg — and failover selects the fallback
 * precisely when delivery is already failing.
 */
it('walks failover and roundrobin and refuses any logging leg', function (string $composite) {
    config([
        'mail.default' => $composite,
        "mail.mailers.{$composite}" => ['transport' => $composite, 'mailers' => ['smtp', 'log']],
        'mail.mailers.log' => ['transport' => 'log'],
    ]);

    expect(Guard::isSafe())->toBeFalse()
        ->and(fn () => Guard::assertSafe())->toThrow(RuntimeException::class, 'record or discard');

    // All-safe legs are accepted.
    config([
        "mail.mailers.{$composite}" => ['transport' => $composite, 'mailers' => ['smtp', 'backup']],
        'mail.mailers.backup' => ['transport' => 'smtp', 'host' => 'backup.example.test'],
    ]);
    expect(Guard::isSafe())->toBeTrue();
})->with(['failover', 'roundrobin']);

it('refuses an empty or malformed composition', function () {
    config(['mail.default' => 'failover', 'mail.mailers.failover' => ['transport' => 'failover', 'mailers' => []]]);
    expect(fn () => Guard::assertSafe())->toThrow(RuntimeException::class, 'composes no mailer');

    config(['mail.mailers.failover' => ['transport' => 'failover', 'mailers' => [['nested']]]]);
    expect(fn () => Guard::assertSafe())->toThrow(RuntimeException::class, 'malformed leg');
});

it('refuses a cyclic composition instead of hanging', function () {
    config([
        'mail.default' => 'a',
        'mail.mailers.a' => ['transport' => 'failover', 'mailers' => ['b']],
        'mail.mailers.b' => ['transport' => 'failover', 'mailers' => ['a']],
    ]);

    expect(fn () => Guard::assertSafe())->toThrow(RuntimeException::class, 'too deep');
});

it('never puts a credential in a refusal message', function () {
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '', 'username' => 'postmaster@x', 'password' => 'sup3rs3cr3t'],
    ]);

    try {
        Guard::assertSafe();
        $message = '';
    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();
    }

    // The NAME of the missing setting, never a value.
    expect($message)->toContain('missing [host]')
        ->and($message)->not->toContain('sup3rs3cr3t')
        ->and($message)->not->toContain('postmaster@x');
});
