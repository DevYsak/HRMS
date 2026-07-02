<?php

namespace App\Console\Commands;

use App\Services\Biometric\EngineAttendanceSyncService;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Pulls pre-calculated daily attendance from the external Python attendance
 * engine into HRMS (attendances + attendance_daily_summaries) via
 * EngineAttendanceSyncService. HRMS never recalculates; matched by employee_code.
 *
 * Two modes:
 *   - Rolling (default): --days back from --date (used by the scheduler).
 *   - Backfill: --from / --to for a full historical range. A backfill keeps
 *     going past a bad day instead of aborting, so one gap can't stop the run.
 */
class SyncEngineAttendance extends Command
{
    protected $signature = 'attendance:sync-engine
                            {--date= : End date to sync (Y-m-d); defaults to today}
                            {--days=1 : Number of days back to sync, ending at --date}
                            {--from= : Backfill start date (Y-m-d); enables full-range mode}
                            {--to= : Backfill end date (Y-m-d); defaults to today}
                            {--resilient : Continue past a failed day instead of aborting (implied by --from)}';

    protected $description = 'Pull computed daily attendance from the Python biometric engine into HRMS.';

    public function handle(EngineAttendanceSyncService $service): int
    {
        $dates = $this->resolveDates();

        if ($dates === []) {
            $this->error('No dates to sync — check --from/--to (start must be on or before end).');

            return self::FAILURE;
        }

        $isBackfill = (bool) $this->option('from');
        $resilient = $isBackfill || $this->option('resilient');
        $totalSynced = 0;
        $totalSkipped = 0;
        $failedDates = [];

        $bar = $isBackfill ? $this->output->createProgressBar(count($dates)) : null;
        $bar?->start();

        foreach ($dates as $date) {
            $result = $service->syncDate($date);

            if ($result['error'] !== null) {
                $failedDates[$date] = $result['error'];

                // Non-resilient (rolling) runs preserve the original fail-fast
                // behaviour so the scheduler surfaces an unreachable engine.
                if (! $resilient) {
                    $this->error("  {$date}: {$result['error']}");

                    return self::FAILURE;
                }

                $bar?->advance();

                continue;
            }

            $totalSynced += $result['synced'];
            $totalSkipped += $result['skipped'];

            if ($bar) {
                $bar->advance();
            } else {
                $this->line("  {$date}: synced <info>{$result['synced']}</info>, skipped {$result['skipped']}");
            }
        }

        $bar?->finish();
        $this->newLine();

        $this->info("Done. {$totalSynced} synced, {$totalSkipped} skipped (unmatched PINs) across ".count($dates).' day(s).');

        if ($failedDates !== []) {
            $this->warn(count($failedDates).' day(s) could not be synced:');
            foreach ($failedDates as $date => $error) {
                $this->line("  {$date}: {$error}");
            }

            // A backfill only fails outright when nothing at all came through
            // (e.g. engine down / misconfigured) rather than on partial gaps.
            if ($totalSynced === 0) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Build the ascending list of dates to sync from either the backfill range
     * (--from/--to) or the rolling window (--date/--days).
     *
     * @return list<string>
     */
    private function resolveDates(): array
    {
        if ($this->option('from')) {
            $from = Carbon::parse($this->option('from'))->startOfDay();
            $to = ($this->option('to') ? Carbon::parse($this->option('to')) : now())->startOfDay();

            if ($from->gt($to)) {
                return [];
            }

            return collect(CarbonPeriod::create($from, $to))
                ->map(fn ($d) => $d->toDateString())
                ->all();
        }

        $endDate = $this->option('date') ? Carbon::parse($this->option('date')) : now();
        $days = max(1, (int) $this->option('days'));

        return collect(range(0, $days - 1))
            ->map(fn (int $i) => $endDate->copy()->subDays($i)->toDateString())
            ->all();
    }
}
