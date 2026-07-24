<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Deliberately carries no credential, identifier, storage path or inner error.
 */
final class DownloadAccessDenied extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Download unavailable.');
    }
}
