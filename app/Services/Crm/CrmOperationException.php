<?php

declare(strict_types=1);

namespace App\Services\Crm;

use RuntimeException;

final class CrmOperationException extends RuntimeException
{
    public static function unavailable(): self
    {
        return new self('The CRM operation could not be completed.');
    }
}
