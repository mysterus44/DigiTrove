<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class InvalidByteRange extends RuntimeException
{
    public function __construct(public readonly int $size)
    {
        parent::__construct('The requested byte range is not satisfiable.');
    }
}
