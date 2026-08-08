<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Outcome of a single call to the PostgreSQL process authority. These are the ONLY
 * values process_crm_commerce_rollup_refresh() may return; anything else is treated
 * as an unavailable authority by the processor.
 */
enum CrmCommerceRollupRefreshStatus: string
{
    case Refreshed = 'refreshed';
    case Noop = 'noop';
    case Retry = 'retry';
    case Terminal = 'terminal';
    case NotFound = 'not_found';
}
