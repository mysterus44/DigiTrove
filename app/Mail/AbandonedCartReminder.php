<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use SensitiveParameter;

/**
 * P6-C abandoned cart reminder.
 *
 * Deliberately NOT `ShouldQueue`. It is built and sent synchronously inside the worker
 * that already holds the capability in memory, so the raw secret never reaches Redis,
 * the `jobs` table or `failed_jobs` — the same discipline as P4-C's OrderDownloadsReady.
 *
 * The capability travels in the URI FRAGMENT, which browsers do not transmit in the
 * Referer header and which reverse proxies do not receive at all. That closes the
 * browser-side leak; it does NOT close a proxy that logs the full request line for a
 * query-string variant, which is precisely why the fragment form is used instead.
 */
final class AbandonedCartReminder extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $cartPublicId,
        #[SensitiveParameter] public readonly string $capability,
        public readonly int $expiresInMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Votre panier vous attend');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.abandoned-cart-reminder',
            with: [
                'resumeUrl' => url('/cart/resume/'.rawurlencode($this->cartPublicId)).'#c='.rawurlencode($this->capability),
                'expiresInMinutes' => $this->expiresInMinutes,
            ],
        );
    }

    /**
     * Fail closed if this Mailable is ever serialised: that would write the raw
     * capability into a queue payload or a failed-job record.
     *
     * @return array<string, never>
     */
    public function __sleep(): array
    {
        throw new \LogicException('AbandonedCartReminder must be sent synchronously and never serialised.');
    }
}
