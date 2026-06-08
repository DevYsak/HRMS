<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneNotifications extends Command
{
    protected $signature = 'hrms:prune-notifications';

    protected $description = 'Delete read database notifications older than 90 days.';

    public function handle(): int
    {
        $cutoff = now()->subDays(90)->toDateTimeString();

        $deleted = DB::table('notifications')
            ->whereNotNull('read_at')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} old notification(s).");

        return self::SUCCESS;
    }
}
