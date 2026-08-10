<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Fail-closed mail transport boundary for P6-C (D-030 debt, D-056 §7).
 *
 * WHY A GUARD AND NOT A CONFIG CONVENTION. `.env.example` says `MAIL_MAILER=smtp`, but
 * `config/mail.php` still declares `'default' => env('MAIL_MAILER', 'log')`. A
 * deployment that simply forgets the variable therefore falls back to `log`, and every
 * cart-resume link — a live capability — would be written verbatim into
 * `storage/logs`. A named mailer is also not proof of readiness: the shipped `smtp`
 * entry has an EMPTY `MAIL_HOST`, so "smtp" alone would silently fail or, worse, be
 * reconfigured to something that absorbs mail.
 *
 * The guard therefore refuses on the TRANSPORT ACTUALLY RESOLVED, recursing through
 * `failover` and `roundrobin` so a composition cannot smuggle a logging leg past it.
 *
 * No refusal message ever contains a credential: only the mailer NAME and a reason.
 */
final class MailTransportGuard
{
    /** Transports that record or swallow a message instead of delivering it. */
    public const UNSAFE_TRANSPORTS = ['log', 'array', 'null'];

    /** Compositions that delegate to other mailers and must be walked. */
    public const COMPOSITE_TRANSPORTS = ['failover', 'roundrobin'];

    /** Transports that can actually deliver, each with its own readiness rule. */
    public const DELIVERING_TRANSPORTS = ['smtp', 'ses', 'postmark', 'resend', 'sendmail', 'mailgun'];

    private const MAX_DEPTH = 4;

    public static function isSafe(): bool
    {
        try {
            self::assertSafe();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @throws RuntimeException when no real delivery can be guaranteed
     */
    public static function assertSafe(): void
    {
        $default = config('mail.default');

        if (! is_string($default) || $default === '') {
            throw new RuntimeException('Mail transport is not configured.');
        }

        self::assertMailerSafe($default, 0);
    }

    private static function assertMailerSafe(string $mailer, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            // A cycle in failover/roundrobin must not hang or be assumed benign.
            throw new RuntimeException('Mail transport composition is too deep.');
        }

        $config = config("mail.mailers.{$mailer}");

        if (! is_array($config)) {
            throw new RuntimeException("Mail transport [{$mailer}] is not defined.");
        }

        $transport = $config['transport'] ?? null;

        if (! is_string($transport) || $transport === '') {
            throw new RuntimeException("Mail transport [{$mailer}] declares no transport.");
        }

        if (in_array($transport, self::UNSAFE_TRANSPORTS, true)) {
            throw new RuntimeException("Mail transport [{$mailer}] would record or discard the message.");
        }

        if (in_array($transport, self::COMPOSITE_TRANSPORTS, true)) {
            $legs = $config['mailers'] ?? [];

            if (! is_array($legs) || $legs === []) {
                throw new RuntimeException("Mail transport [{$mailer}] composes no mailer.");
            }

            // EVERY leg must be safe. One logging leg is enough to leak a live link,
            // and failover picks it precisely when things are already going wrong.
            foreach ($legs as $leg) {
                if (! is_string($leg)) {
                    throw new RuntimeException("Mail transport [{$mailer}] has a malformed leg.");
                }

                self::assertMailerSafe($leg, $depth + 1);
            }

            return;
        }

        if (! in_array($transport, self::DELIVERING_TRANSPORTS, true)) {
            // Unknown transports are refused rather than trusted: a new driver has to be
            // reviewed and added here deliberately.
            throw new RuntimeException("Mail transport [{$mailer}] is not a reviewed delivery transport.");
        }

        self::assertTransportConfigured($mailer, $transport, $config);
        self::assertSenderConfigured();
    }

    /**
     * A named transport is not a ready transport. Each one is checked against the values
     * it genuinely cannot deliver without.
     *
     * @param  array<string, mixed>  $config
     */
    private static function assertTransportConfigured(string $mailer, string $transport, array $config): void
    {
        $missing = match ($transport) {
            // The shipped example has an EMPTY MAIL_HOST — exactly the case that must fail.
            'smtp' => self::blank($config['host'] ?? null) ? 'host' : null,
            'ses' => self::blank(config('services.ses.key')) ? 'ses.key' : null,
            'postmark' => self::blank(config('services.postmark.token')) ? 'postmark.token' : null,
            'resend' => self::blank(config('services.resend.key')) ? 'resend.key' : null,
            'mailgun' => self::blank(config('services.mailgun.secret')) ? 'mailgun.secret' : null,
            'sendmail' => self::blank($config['path'] ?? null) ? 'path' : null,
            default => 'transport',
        };

        if ($missing !== null) {
            // The NAME of the missing setting, never its value.
            throw new RuntimeException("Mail transport [{$mailer}] is incomplete: missing [{$missing}].");
        }
    }

    /** Without a From address the provider rejects or silently drops the message. */
    private static function assertSenderConfigured(): void
    {
        if (self::blank(config('mail.from.address'))) {
            throw new RuntimeException('Mail sender address is not configured.');
        }
    }

    private static function blank(mixed $value): bool
    {
        return ! is_string($value) || trim($value) === '';
    }
}
