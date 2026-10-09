<?php

namespace App\Console\Commands;

use App\Services\Attendance\AutoCheckoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Nightly auto checkout (scheduled after 11:00 PM IST) — the one approved
 * exception to D10: automatic jobs may not make disciplinary / leave / payroll
 * decisions, except this missing-checkout fallback, which may close an open
 * attendance day at the scheduled shift end with a system / audit marker.
 *
 * Closes open days — a valid IN, no final OUT — at the employee's assigned
 * shift end, marked as a system auto checkout and audited. It never writes a
 * punch, never creates attendance, never produces overtime, and a later
 * genuine OUT replaces it. Sweeps today and yesterday only (yesterday catches
 * night shifts that ended this morning); it never backfills older days.
 * Idempotent: running it again changes nothing.
 */
class AutoCheckout extends Command
{
    protected $signature = 'hrms:auto-checkout {--dry-run : Report what would be closed without changing anything}';

    protected $description = 'After 11 PM, close open attendance days (IN, no final OUT) at the assigned shift end as a system auto checkout.';

    public function handle(AutoCheckoutService $autoCheckout): int
    {
        $now = Carbon::now();
        $dryRun = (bool) $this->option('dry-run');

        foreach ([$now->copy()->startOfDay(), $now->copy()->startOfDay()->subDay()] as $date) {
            $result = $autoCheckout->close($date, $now, $dryRun);

            $skipped = collect($result['skipped'])->map(fn (int $n, string $why) => "{$why}: {$n}")->implode(', ');
            $this->info(sprintf('%s: %d open day(s) checked, %d %s%s',
                $result['date'], $result['checked'], $result['closed'],
                $dryRun ? 'would be closed' : 'closed at shift end',
                $skipped !== '' ? " — left: {$skipped}" : ''));
        }

        return self::SUCCESS;
    }
}
