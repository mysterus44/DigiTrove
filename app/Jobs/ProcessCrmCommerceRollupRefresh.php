<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Crm\CrmCommerceRollupRefreshProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Durable refresh worker. Its payload is ID-only: a contact id and an ISO currency,
 * never an order, refund, email or amount. It computes no money; it only invokes the
 * PostgreSQL process authority. Uniqueness is per (contact, currency) with a finite
 * TTL that comfortably exceeds the retry horizon (3×[30,120,300] < 3600s).
 */
final class ProcessCrmCommerceRollupRefresh implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $contactId, public readonly string $currency)
    {
        $this->onQueue('crm');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->contactId.':'.$this->currency;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(CrmCommerceRollupRefreshProcessor $processor): void
    {
        $processor->process($this->contactId, $this->currency);
    }
}
