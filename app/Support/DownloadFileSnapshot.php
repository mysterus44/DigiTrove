<?php

declare(strict_types=1);

namespace App\Support;

final readonly class DownloadFileSnapshot
{
    public function __construct(
        public int $orderId,
        public int $grantId,
        public int $logId,
        public int $productFileId,
        public string $storageDisk,
        public string $storagePath,
        public int $sizeBytes,
        public string $fileName,
        public string $mimeType,
        public string $checksum,
    ) {}
}
