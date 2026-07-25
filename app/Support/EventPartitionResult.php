<?php

declare(strict_types=1);

namespace App\Support;

use JsonException;

final readonly class EventPartitionResult
{
    public function __construct(
        public string $partition,
        public bool $created,
        public string $bound,
    ) {}

    /**
     * @throws JsonException
     */
    public static function fromDatabaseJson(string $json): self
    {
        $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return new self(
            (string) $value['partition'],
            (bool) $value['created'],
            (string) $value['bound'],
        );
    }
}
