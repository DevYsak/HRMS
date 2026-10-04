<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Spec §3.6 / §7 — notifications older than 90 days are pruned, read or not.
 * (Unread rows used to be kept forever, so the table and the bell's unread
 * count grew without bound with alerts nobody would act on any more.) Audit
 * logs are a separate table and are never pruned (spec §9).
 */
class PruneNotifications extends Command
{
    protected $signature = 'hrms:prune-notifications';

    protected $description = 'Delete database notifications older than 90 days.';

    public function handle(): int
    {
        $cutoff = now()->subDays(90)->toDateTimeString();

        $deleted = DB::table('notifications')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} old notification(s).");

        return self::SUCCESS;
    }
}
