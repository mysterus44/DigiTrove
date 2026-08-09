<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * A structured criterion row could not be turned into a valid DSL V1 definition.
 *
 * The message is a stable, non-sensitive REASON CODE — never a database message, never
 * a SQLSTATE, never the offending value. The admin screen renders a human label chosen
 * from this code; nothing from PostgreSQL is ever echoed to a browser.
 */
final class CrmSegmentDefinitionException extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }

    /**
     * The stable reason code.
     *
     * Exposed under its own name rather than through `getMessage()` so callers read as
     * what they are — a domain code lookup — and so a security contract can ban
     * `getMessage()` outright on the CRM admin surface, where the only thing that must
     * never be echoed is a *database* message.
     */
    public function reason(): string
    {
        return $this->getMessage();
    }
}
