<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The in-memory result of a grant issuance run (P4-C, D-035).
 *
 * Holds the newly issued grants (with their raw tokens) and the buyer e-mail,
 * only long enough to compose and send the synchronous delivery mail. It is
 * never serialised into a queue payload, a log or an exception.
 */
final readonly class IssuedGrantBatch
{
    /** @param list<IssuedGrant> $grants */
    public function __construct(
        public int $orderId,
        public string $customerEmail,
        public array $grants,
    ) {}

    public function isEmpty(): bool
    {
        return $this->grants === [];
    }

    /** @return list<int> */
    public function grantIds(): array
    {
        return array_map(static fn (IssuedGrant $g): int => $g->grantId, $this->grants);
    }
}
