<?php

namespace App\Console\Commands;

use App\Services\Attendance\MissingCheckoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Missing-checkout sweep (the old "auto punch-out", which no longer punches).
 * The separate nightly fallback hrms:auto-checkout may later close the still
 * open day at the shift end (the approved D10 exception) — never this sweep.
 *
 * It NEVER creates an OUT. A day with a Face IN and no valid ID Card OUT
 * becomes Missing Checkout once the employee's own shift end + 1 hour has
 * passed — check_out stays NULL, no shift is credited, no overtime is
 * produced (an approved OT request does not let the system invent a
 * checkout) and the employee must regularise. Regularised, HR-corrected and
 * payroll-settled days are never touched. Night shifts wait for their real
 * cutoff, so yesterday's date is swept too. Runs every few minutes and is
 * idempotent, including the notification.
 *
 * The command name is kept so the existing schedule and runbooks still work.
 */
class AutoPunchOut extends Command
{
    protected $signature = 'hrms:auto-punch-out {--date= : Sweep a specific work date (Y-m-d); default is today and yesterday (night shifts)}';

    protected $description = 'Mark open attendance days past shift end + 1 hour as Missing Checkout — never creates a check-out.';

    public function handle(MissingCheckoutService $missing): int
    {
        $now = Carbon::now();
        $dates = $this->option('date')
            ? [Carbon::parse($this->option('date'))]
            : [$now->copy()->startOfDay(), $now->copy()->startOfDay()->subDay()];

        $flagged = 0;
        foreach ($dates as $date) {
            $result = $missing->sweep($date, $now);
            $flagged += $result['flagged'];
            $this->info("{$date->toDateString()}: {$result['checked']} open day(s) checked, {$result['flagged']} newly flagged missing check-out, {$result['protected']} protected left untouched.");
        }

        $this->info("Missing-checkout sweep flagged {$flagged} day(s). No check-out was created.");

        return self::SUCCESS;
    }
}
