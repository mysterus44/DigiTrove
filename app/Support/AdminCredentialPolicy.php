<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * The credentials the admin seeder will refuse (H2.3, durcissement pré-production).
 *
 * ⚠️ THE DEFECT THIS CLOSES. `.env.example` has advertised `ADMIN_EMAIL` and
 * `ADMIN_PASSWORD` since P1, and **no file in the repository ever read them**. The "admin
 * seeder that refuses an empty password" existed in the tracker and nowhere else. This is
 * the first code that gives those two variables any meaning at all.
 *
 * Every refusal is loud. A seeder that quietly skipped, or quietly substituted a default,
 * would reproduce exactly the failure this whole hardening pass exists to remove: the
 * weaker outcome reached by omission, with nothing anywhere saying so.
 *
 * ⚠️ LENGTH, NOT COMPOSITION. There is deliberately no "one uppercase, one digit" rule:
 * composition rules mostly produce memorable-therefore-weak passwords (`Password1!`) while
 * feeling strict. Twelve characters plus a blocklist of the values people actually type is
 * a better filter than a character-class checklist.
 */
final class AdminCredentialPolicy
{
    /** Twelve is the floor. Length is the factor that actually costs an attacker. */
    public const MINIMUM_LENGTH = 12;

    /**
     * Values a human types when they are in a hurry, not an exhaustive dictionary. This is
     * a guard against the obvious, not a password strength meter.
     */
    private const BLOCKLIST = [
        'password', 'passw0rd', 'motdepasse', 'admin', 'administrator',
        'changeme', 'change-me', 'secret', 'digitrove', 'letmein', 'qwerty', 'azerty',
    ];

    /**
     * Below this, the "password must not contain the local part" rule is not applied.
     *
     * ⚠️ Measured reasoning, not caution for its own sake: for `a@example.com` the local
     * part is `a`, and a containment rule would reject every password containing the letter
     * a. The rule exists to catch `admin@digitrove.com` / `admin123`, which needs a local
     * part long enough to be meaningful in the first place.
     */
    private const MIN_LOCAL_PART_FOR_CONTAINMENT = 3;

    /**
     * @throws RuntimeException with a message that names the rule broken but NEVER echoes
     *                          the password itself — a seeder's output lands in CI logs.
     */
    public static function assertUsable(mixed $email, mixed $password): void
    {
        $email = is_string($email) ? trim($email) : '';
        $password = is_string($password) ? $password : '';

        if ($email === '') {
            throw new RuntimeException(
                'ADMIN_EMAIL is empty. Set it in .env before seeding; the seeder will not invent one.',
            );
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('ADMIN_EMAIL is not a valid email address.');
        }

        if ($password === '') {
            throw new RuntimeException(
                'ADMIN_PASSWORD is empty. Set it in .env before seeding; the seeder will never fall back to a default.',
            );
        }

        if (mb_strlen($password) < self::MINIMUM_LENGTH) {
            throw new RuntimeException(
                'ADMIN_PASSWORD is shorter than '.self::MINIMUM_LENGTH.' characters.',
            );
        }

        $lowered = mb_strtolower($password);

        foreach (self::BLOCKLIST as $banned) {
            if (str_contains($lowered, $banned)) {
                throw new RuntimeException('ADMIN_PASSWORD contains a well-known value; choose another.');
            }
        }

        // `aaaaaaaaaaaa` is twelve characters and defeats the length rule entirely.
        if (mb_strlen(count_chars($password, 3)) === 1) {
            throw new RuntimeException('ADMIN_PASSWORD is a single repeated character.');
        }

        self::assertUnrelatedToEmail($lowered, $email);
    }

    /**
     * The most likely mistake by far: an admin filling both fields quickly types
     * `admin@digitrove.com` and `admin123`. A blocklist alone does not catch it, because
     * the offending word comes from the address the operator just chose.
     */
    private static function assertUnrelatedToEmail(string $loweredPassword, string $email): void
    {
        $localPart = mb_strtolower(trim(mb_substr($email, 0, (int) mb_strpos($email, '@'))));

        if ($localPart === '') {
            return;
        }

        if ($loweredPassword === $localPart) {
            throw new RuntimeException('ADMIN_PASSWORD is the local part of ADMIN_EMAIL.');
        }

        if (mb_strlen($localPart) >= self::MIN_LOCAL_PART_FOR_CONTAINMENT
            && str_contains($loweredPassword, $localPart)) {
            throw new RuntimeException('ADMIN_PASSWORD contains the local part of ADMIN_EMAIL.');
        }
    }
}
