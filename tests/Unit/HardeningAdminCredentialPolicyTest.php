<?php

declare(strict_types=1);

use App\Support\AdminCredentialPolicy;

/*
|--------------------------------------------------------------------------
| Durcissement pré-production — H2.3, credentials de l'administrateur initial
|--------------------------------------------------------------------------
|
| NO database and NO harness: this is a pure decision function. Every case here
| is a refusal the seeder must make BEFORE writing anything.
|
*/

const HARDENING_GOOD_EMAIL = 'operations@digitrove.test';

const HARDENING_GOOD_PASSWORD = 'un-mot-de-passe-suffisamment-long';

it('accepts a credential pair that breaks no rule', function (): void {
    expect(fn () => AdminCredentialPolicy::assertUsable(HARDENING_GOOD_EMAIL, HARDENING_GOOD_PASSWORD))
        ->not->toThrow(RuntimeException::class);
});

/*
|--------------------------------------------------------------------------
| The variables were dead: absent must refuse, never fall back
|--------------------------------------------------------------------------
*/

it('refuses an absent or blank ADMIN_EMAIL rather than inventing one', function (mixed $email): void {
    expect(fn () => AdminCredentialPolicy::assertUsable($email, HARDENING_GOOD_PASSWORD))
        ->toThrow(RuntimeException::class);
})->with([[null], [''], ['   '], [0], [false], [[]], ['not-an-email'], ['a@b']]);

it('refuses an absent or blank ADMIN_PASSWORD rather than defaulting', function (mixed $password): void {
    // The whole point of this gate: the weaker outcome must never be the one reached by
    // omission. A seeder that quietly substituted a default would be the defect, not the fix.
    expect(fn () => AdminCredentialPolicy::assertUsable(HARDENING_GOOD_EMAIL, $password))
        ->toThrow(RuntimeException::class);
})->with([[null], [''], [0], [false], [[]]]);

/*
|--------------------------------------------------------------------------
| Trivial: length, then the values people actually type
|--------------------------------------------------------------------------
*/

it('refuses anything shorter than twelve characters', function (): void {
    expect(AdminCredentialPolicy::MINIMUM_LENGTH)->toBe(12)
        ->and(fn () => AdminCredentialPolicy::assertUsable(HARDENING_GOOD_EMAIL, 'onzecaracte'))
        ->toThrow(RuntimeException::class);
});

it('accepts exactly twelve characters — the floor is inclusive', function (): void {
    expect(fn () => AdminCredentialPolicy::assertUsable(HARDENING_GOOD_EMAIL, 'xkqvbtnrjmzw'))
        ->not->toThrow(RuntimeException::class);
});

it('refuses a well-known value even when it is long enough', function (string $password): void {
    expect(fn () => AdminCredentialPolicy::assertUsable(HARDENING_GOOD_EMAIL, $password))
        ->toThrow(RuntimeException::class);
})->with([
    'motdepasse-2026',
    'Password-Longue-1',
    'changeme-please-now',
    'digitrove-secure-2026',
    'le-secret-de-la-boutique',
    'AZERTY-azerty-1234',
]);

it('refuses a single repeated character, which defeats the length rule', function (): void {
    // Twelve characters, and worthless.
    expect(fn () => AdminCredentialPolicy::assertUsable(HARDENING_GOOD_EMAIL, 'aaaaaaaaaaaa'))
        ->toThrow(RuntimeException::class);
});

/*
|--------------------------------------------------------------------------
| The likeliest mistake of all
|--------------------------------------------------------------------------
*/

it('refuses a password built from the local part of the address', function (string $email, string $password): void {
    // `admin@digitrove.com` / `admin123` is what someone types when filling both fields in
    // one go. A blocklist alone cannot catch it: the offending word comes from the address
    // the operator just chose.
    expect(fn () => AdminCredentialPolicy::assertUsable($email, $password))
        ->toThrow(RuntimeException::class);
})->with([
    'contains it' => ['boutique@digitrove.test', 'boutique-2026-xyz'],
    'contains it, other case' => ['Boutique@digitrove.test', 'MA-BOUTIQUE-SECURE'],
    'equals it' => ['unmotdepasselong@digitrove.test', 'unmotdepasselong'],
]);

it('does not let a one or two letter local part reject everything', function (): void {
    // ⚠️ Measured reasoning: for `a@example.com` the local part is `a`, and a containment
    // rule would refuse every password containing the letter a. The rule exists to catch a
    // meaningful word, so containment applies only from three characters up.
    expect(fn () => AdminCredentialPolicy::assertUsable('a@digitrove.test', 'xkqvbtnrjamzw'))
        ->not->toThrow(RuntimeException::class);
});

it('still refuses a two-letter local part used as the whole password', function (): void {
    // Equality is checked regardless of length — but it also fails the length rule, so this
    // asserts the pair is refused, not which rule fired first.
    expect(fn () => AdminCredentialPolicy::assertUsable('ab@digitrove.test', 'ab'))
        ->toThrow(RuntimeException::class);
});

/*
|--------------------------------------------------------------------------
| A refusal must never leak the value it refused
|--------------------------------------------------------------------------
*/

it('never echoes the password in the message it throws', function (): void {
    // Seeder output lands in CI logs and terminal scrollback. A message that helpfully
    // quoted the rejected password would publish it.
    $secret = 'digitrove-tres-secret-refuse';

    try {
        AdminCredentialPolicy::assertUsable(HARDENING_GOOD_EMAIL, $secret);
        test()->fail('expected a refusal');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->not->toContain($secret)
            // It still names the rule, so the operator knows what to change.
            ->toContain('ADMIN_PASSWORD');
    }
});
