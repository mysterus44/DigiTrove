<?php

namespace App\Enums;

enum WebhookProcessingStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';

    /**
     * H1 — closed out by reconciliation, never by a decision.
     *
     * Deliberately distinct from `Ignored`, which means a DELIBERATE rejection, and from
     * `Failed`, which means the provider counter-call died. This one means: nobody ever
     * decided, and the window is now shut. Collapsing the three would erase the difference
     * between "we chose not to act", "we could not reach the provider" and "we never
     * managed to handle this at all".
     */
    case UnresolvedExpired = 'unresolved_expired';
}
