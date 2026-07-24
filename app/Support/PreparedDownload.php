<?php

declare(strict_types=1);

namespace App\Support;

final readonly class PreparedDownload
{
    /**
     * @param  resource|null  $stream
     */
    public function __construct(
        public mixed $stream,
        public ?string $xAccelPath,
        public string $fileName,
        public string $mimeType,
        public string $checksum,
        public int $size,
        public int $start,
        public int $end,
        public bool $partial,
        public bool $head,
        public int $chunkBytes,
    ) {}

    public function length(): int
    {
        return $this->end - $this->start + 1;
    }
}
