<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use Illuminate\Console\Command;

class CheckExcessBreaks extends Command
{
    protected $signature = 'hrms:check-excess-breaks';

    protected $description = 'Flag today\'s attendance records where total break time exceeds 60 minutes. Runs at 20:00 IST.';

    public function handle(): int
    {
        $today = now()->toDateString();

        $records = Attendance::with('breakLogs')
            ->where('date', $today)
            ->whereNotNull('check_in')
            ->where('excess_break_flag', false)
            ->get();

        $flagged = 0;

        foreach ($records as $record) {
            // Sum completed break segments from break_logs; fall back to legacy break_minutes column
            $totalBreakMins = $record->breakLogs->whereNotNull('break_end')->sum('duration_minutes');

            if ($totalBreakMins === 0) {
                $totalBreakMins = (int) ($record->break_minutes ?? 0);
            }

            if ($totalBreakMins > 60) {
                $excess = $totalBreakMins - 60;
                $record->update([
                    'excess_break_flag' => true,
                    'notes' => trim(($record->notes ?? '')." [Auto] Excess break: {$totalBreakMins}min (+{$excess}min over limit)."),
                ]);
                $flagged++;
            }
        }

        $this->info("Flagged {$flagged} record(s) with excess break time for {$today}.");

        return self::SUCCESS;
    }
}
