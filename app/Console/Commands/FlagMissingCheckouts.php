<?php

namespace App\Console\Commands;

use App\Services\Attendance\MissingCheckoutService;
use Illuminate\Console\Command;

/**
 * FIX 4 — Spec §3.2 + §3.6
 * Flags missing clock-outs and notifies the employee and their manager.
 *
 * "Missing" means a valid Face IN with no valid ID Card OUT by the employee's
 * OWN shift end + 1 hour — decided by the canonical status
 * ({@see MissingCheckoutService}), the same one every screen shows. Nothing
 * is invented: no OUT is created and no overtime is produced. A real session
 * on a weekly off or holiday follows the same rule; a day with no attendance
 * is left untouched. Idempotent: a day already flagged is never flagged or
 * notified twice.
 */
class FlagMissingCheckouts extends Command
{
    protected $signature = 'hrms:flag-missing-checkouts';

    protected $description = 'Flag today\'s open attendance as Missing Checkout once the employee\'s shift end + 1 hour has passed.';

    public function handle(MissingCheckoutService $missing): int
    {
        $today = now()->startOfDay();

        // Today, and yesterday for a night shift whose cutoff falls this morning.
        $flagged = 0;
        foreach ([$today, $today->copy()->subDay()] as $date) {
            $flagged += $missing->sweep($date)['flagged'];
        }

        $this->info("Flagged {$flagged} attendance record(s) as missing check-out for {$today->toDateString()}.");

        return self::SUCCESS;
    }
}
