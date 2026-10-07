<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Notifications\ExcessBreakNotification;
use App\Services\Attendance\AttendanceCalculator;
use Illuminate\Console\Command;

/**
 * FIX 4 — Spec §3.2 + §3.6
 * Flags excess break (>60 min) and notifies employee + their manager in-app.
 */
class CheckExcessBreaks extends Command
{
    protected $signature = 'hrms:check-excess-breaks';

    protected $description = 'Flag today\'s attendance records where total break time exceeds 60 minutes. Runs at 20:00 IST.';

    public function handle(): int
    {
        $today = now()->toDateString();

        $records = Attendance::with(['employee.user', 'employee.manager', 'employee.shift', 'breakLogs'])
            ->where('date', $today)
            ->whereNotNull('check_in')
            ->where('excess_break_flag', false)
            ->get();

        $flagged = 0;

        foreach ($records as $record) {
            if (! $record->employee) {
                continue;
            }

            // Pulse v3.1: total break for the day from the canonical calculation
            // (device punch gaps, else logged breaks); excess only above 60
            // minutes. Informational — worked hours are never reduced.
            $totalBreakMins = app(AttendanceCalculator::class)->forAttendance($record)->breakMinutes
                ?: (int) $record->breakLogs->whereNotNull('break_end')->sum('duration_minutes');
            $allowance = AttendanceCalculator::EXCESS_BREAK_MINUTES;

            if ($totalBreakMins > $allowance) {
                $excess = $totalBreakMins - $allowance;
                $record->update([
                    'excess_break_flag' => true,
                    'notes' => trim(($record->notes ?? '')." [Auto] Excess break: {$totalBreakMins}min (+{$excess}min over limit)."),
                ]);

                $employee = $record->employee;
                $notification = new ExcessBreakNotification($record, $totalBreakMins);

                $employee->user?->notify($notification->forRole('employee'));

                if ($employee->manager_id) {
                    $employee->manager?->notify($notification->forRole('manager'));
                }

                $flagged++;
            }
        }

        $this->info("Flagged {$flagged} record(s) with excess break time for {$today}.");

        return self::SUCCESS;
    }
}
