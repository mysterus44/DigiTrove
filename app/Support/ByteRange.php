<?php

declare(strict_types=1);

namespace App\Support;

final readonly class ByteRange
{
    public function __construct(
        public int $start,
        public int $end,
        public int $size,
    ) {}

    public function length(): int
    {
        return $this->end - $this->start + 1;
    }
}
