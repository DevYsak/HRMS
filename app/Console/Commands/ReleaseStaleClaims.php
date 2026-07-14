<?php

namespace App\Console\Commands;

use App\Services\Approvals\ClaimLockService;
use Illuminate\Console\Command;

/**
 * Release approval claim-locks abandoned for over 2 hours, so a request opened
 * by an HR admin who never acted becomes available to the other in-scope HRs.
 * Scheduled hourly.
 */
class ReleaseStaleClaims extends Command
{
    protected $signature = 'hrms:release-stale-claims';

    protected $description = 'Release approval claim-locks older than '.ClaimLockService::STALE_AFTER_MINUTES.' minutes';

    public function handle(ClaimLockService $claims): int
    {
        $released = $claims->releaseStale();

        $this->info("Released {$released} stale claim(s).");

        return self::SUCCESS;
    }
}
