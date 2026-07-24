<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\DeliveryConfig;
use App\Support\IssuedGrant;
use App\Support\IssuedGrantBatch;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use RuntimeException;

/**
 * The paid-order download e-mail (P4-C3, D-035).
 *
 * It DELIBERATELY does NOT implement ShouldQueue: it is composed and sent
 * synchronously inside the worker, so the raw tokens it carries in memory never
 * reach Redis, `jobs`, `failed_jobs`, the cache or a log. The download links are
 * built from the enabled pipeline's HTTPS base URL; the real download route is
 * wired in P4-C4/C5.
 */
final class OrderDownloadsReady extends Mailable
{
    public function __construct(public readonly IssuedGrantBatch $batch) {}

    /**
     * Fail closed if any future call site attempts to put raw link tokens on a
     * queue. Synchronous Mail::send() never calls this method.
     */
    public function queue(QueueFactory $queue): never
    {
        throw new RuntimeException('Secure delivery mail cannot be queued.');
    }

    public function later($delay, QueueFactory $queue): never
    {
        throw new RuntimeException('Secure delivery mail cannot be queued.');
    }

    /** Prevent accidental generic serialisation outside Laravel's mail API. */
    public function __serialize(): never
    {
        throw new RuntimeException('Secure delivery mail cannot be serialized.');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your downloads are ready');
    }

    public function content(): Content
    {
        // Throws fail-closed if the pipeline is enabled without a valid HTTPS URL.
        $base = DeliveryConfig::downloadBaseUrl();

        $rows = '';
        foreach ($this->batch->grants as $grant) {
            /** @var IssuedGrant $grant */
            // TODO(P4-C4/C5): wire this URL onto the secure authorization + streaming route.
            // The token stays in the URI fragment, which browsers do not send
            // to the server or proxy logs. P4-C4/C5 will exchange it for the
            // dedicated attempt credential through the approved HTTP contract.
            $url = $base.'/'.rawurlencode($grant->grantPublicId).'#token='.rawurlencode($grant->rawToken);
            $rows .= '<li><a href="'.e($url).'">'.e($grant->fileName).'</a></li>';
        }

        return new Content(
            htmlString: '<p>Your downloads are ready:</p><ul>'.$rows.'</ul>',
        );
    }
}
