<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\GrantRevocationReason;
use App\Services\Delivery\DownloadOperationsService;
use Illuminate\Console\Command;

final class RevokeDownloadGrant extends Command
{
    protected $signature = 'downloads:revoke
                            {grant : Internal download grant id}
                            {--reason=manual_security_reissue : Sanitized allowlisted reason}
                            {--dry-run}';

    protected $description = 'Revoke one active download grant for a support security action';

    public function handle(DownloadOperationsService $operations): int
    {
        $grantId = filter_var($this->argument('grant'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reason = (string) $this->option('reason');
        if ($grantId === false || $reason !== GrantRevocationReason::ManualSecurityReissue->value) {
            $this->error('The revocation request is invalid.');

            return self::INVALID;
        }

        if ((bool) $this->option('dry-run')) {
            $this->line("would_revoke_grant_id={$grantId}");

            return self::SUCCESS;
        }

        if (! $operations->revokeGrant((int) $grantId, $reason)) {
            $this->error('The grant was not revoked.');

            return self::FAILURE;
        }

        $this->line("revoked_grant_id={$grantId}");

        return self::SUCCESS;
    }
}
