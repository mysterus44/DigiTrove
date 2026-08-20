<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Payments\WebhookReconciliationService;
use App\Support\WebhookReconciliationConfig;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for webhook reconciliation (H1, dette #6).
 *
 * ⚠️ DRY-RUN BY DEFAULT, AND A DOUBLE BARRIER TO MUTATE. Writing requires BOTH `--execute`
 * AND `WEBHOOK_RECONCILIATION_ENABLED=true` — the same shape as
 * `crm:backfill-commerce-rollups` (P6-A1.3). One barrier is a flag someone flips while
 * debugging; two mean the mutation was intended twice, by two different people or at two
 * different moments.
 *
 * ⚠️ IT NEVER CONTACTS THE PROVIDER. Not a limitation to be lifted casually: retrying the
 * counter-call automatically would change a CinetPay behaviour an existing test pins down.
 * This command closes records and raises alerts. Nothing else.
 */
final class ReconcileWebhooksCommand extends Command
{
    protected $signature = 'payments:reconcile-webhooks {--execute : Actually close out expired events}';

    protected $description = 'Close out signed payment webhooks that never reached a financial decision (dry-run by default)';

    public function handle(WebhookReconciliationService $service): int
    {
        try {
            // Bounds are validated before anything is read, so an invalid setting fails the
            // run rather than half of it.
            WebhookReconciliationConfig::assertReady();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $requested = (bool) $this->option('execute');
        $enabled = WebhookReconciliationConfig::enabled();
        $execute = $requested && $enabled;

        if ($requested && ! $enabled) {
            // Refused LOUDLY. An operator who typed `--execute` and saw a silent dry-run
            // would reasonably conclude there was nothing to do.
            $this->error('Refusing to mutate: --execute was given but WEBHOOK_RECONCILIATION_ENABLED is false.');

            return self::FAILURE;
        }

        $result = $service->reconcile($execute);

        if ($execute) {
            $this->info(sprintf(
                'Closed out %d event(s); %d unresolved past the escalation threshold.',
                $result['expired'],
                $result['escalated'],
            ));
        } else {
            $this->line(sprintf(
                'DRY RUN — %d event(s) would be closed out; %d are past the escalation threshold.',
                $result['expired'],
                $result['escalated'],
            ));
            $this->comment('Nothing was written. Re-run with --execute and WEBHOOK_RECONCILIATION_ENABLED=true.');
        }

        // Said on every run, not only the busy ones: a count of zero with a capped batch is
        // indistinguishable from a full one unless the cap is stated.
        $this->line('Batch cap: '.WebhookReconciliationService::BATCH_SIZE.' per invocation and per window.');

        return self::SUCCESS;
    }
}
