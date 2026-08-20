<?php

declare(strict_types=1);

use App\Support\SessionCookiePolicy;

/*
|--------------------------------------------------------------------------
| Durcissement pré-production — H2.2, politique du cookie de session
|--------------------------------------------------------------------------
|
| NO database and NO harness on purpose: this is a pure decision function, and
| paying for a migration to exercise it would be waste.
|
*/

/*
 * The policy is interrogated DIRECTLY rather than by evaluating config/session.php under a
 * forced APP_ENV. Measured, not assumed: Laravel's env repository is IMMUTABLE, so a test
 * that sets `APP_ENV` after phpunit.xml has defined it is a silent no-op — it would have
 * asserted the testing behaviour while claiming to prove the production one. That is
 * exactly why the rule lives in a named class instead of ternaries inside a config file.
 */

it('forces a secure, http-only session cookie in production even with the variables unset', function (): void {
    // The exact defect: `env('SESSION_SECURE_COOKIE')` with no default returned null, which
    // Laravel reads as "not secure". A missing line in `.env` silently sent the session
    // cookie over plain HTTP.
    expect(SessionCookiePolicy::secure('production', null))->toBeTrue()
        ->and(SessionCookiePolicy::httpOnly('production', null))->toBeTrue()
        ->and(SessionCookiePolicy::sameSite('production', null))->toBe('lax');
});

it('treats a missing environment exactly like production', function (): void {
    expect(SessionCookiePolicy::secure(null, null))->toBeTrue()
        ->and(SessionCookiePolicy::httpOnly(null, null))->toBeTrue();
});

it('refuses to let production turn the protections off through the environment', function (mixed $off): void {
    expect(SessionCookiePolicy::secure('production', $off))->toBeTrue()
        ->and(SessionCookiePolicy::httpOnly('production', $off))->toBeTrue();
})->with(['false', false, '0', 'no', '']);

it('falls back to lax rather than obeying a policy that disables the protection', function (?string $unsafe): void {
    expect(SessionCookiePolicy::sameSite('production', $unsafe))->toBe('lax');
})->with(['none', 'None', 'nonsense', '', null]);

it('still honours strict, which is stronger than lax', function (): void {
    expect(SessionCookiePolicy::sameSite('production', 'strict'))->toBe('strict')
        ->and(SessionCookiePolicy::sameSite('production', ' STRICT '))->toBe('strict');
});

it('leaves local and testing free to run over plain http', function (string $environment): void {
    expect(SessionCookiePolicy::secure($environment, 'false'))->toBeFalse()
        // A developer can still opt in, and the relaxed environments obey `none` because
        // that is sometimes exactly what a local cross-origin experiment needs.
        ->and(SessionCookiePolicy::secure($environment, 'true'))->toBeTrue()
        ->and(SessionCookiePolicy::sameSite($environment, 'none'))->toBe('none');
})->with(['local', 'testing']);

it('reads a string "false" from .env as false, not as a truthy string', function (): void {
    // The classic .env trap: every value arrives as a string, and "false" is truthy.
    expect(SessionCookiePolicy::httpOnly('local', 'false'))->toBeFalse()
        ->and(SessionCookiePolicy::httpOnly('local', null))->toBeTrue();
});
