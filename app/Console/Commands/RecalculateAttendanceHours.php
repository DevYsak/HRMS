<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Services\Attendance\AttendanceCalculator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Rewrites attendances.total_hours under the Pulse v3.1 rule — final clock-out
 * − first clock-in, breaks NOT deducted — for rows saved by the old
 * break-deducting formulas. Preview by default; nothing changes without
 * --apply. Only closed days (with a check-out) are touched, and only the
 * total_hours column; regularised days keep their corrected times.
 */
#[Signature('attendance:recalculate-hours {--from= : First date (Y-m-d)} {--to= : Last date (Y-m-d)} {--employee= : Limit to one employee id} {--apply : Write the corrected hours (otherwise preview only)}')]
#[Description('Recompute stored attendance hours as first-in to final-out (no break deduction) — preview unless --apply')]
class RecalculateAttendanceHours extends Command
{
    public function handle(AttendanceCalculator $calculator): int
    {
        $query = Attendance::query()
            ->whereNotNull('check_in')
            ->whereNotNull('check_out')
            ->when($this->option('from'), fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($this->option('to'), fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($this->option('employee'), fn ($q, $id) => $q->where('employee_id', $id))
            ->orderBy('date');

        $checked = 0;
        $changed = [];

        $query->chunkById(500, function ($rows) use ($calculator, &$checked, &$changed) {
            foreach ($rows as $row) {
                $checked++;
                $correct = $calculator->storedHours($row->check_in, $row->check_out);

                if (abs((float) $row->total_hours - $correct) < 0.01) {
                    continue;
                }

                $changed[] = [$row->id, $row->employee_id, $row->date->toDateString(), $row->check_in->format('H:i'), $row->check_out->format('H:i'), (float) $row->total_hours, $correct];

                if ($this->option('apply')) {
                    // Column only, no model events: nothing else on the row changes.
                    Attendance::whereKey($row->id)->update(['total_hours' => $correct]);
                }
            }
        });

        $this->table(['Row', 'Employee', 'Date', 'In', 'Out', 'Stored hours', 'Correct hours'], array_slice($changed, 0, 200));

        if (count($changed) > 200) {
            $this->line('… and '.(count($changed) - 200).' more.');
        }

        $this->info(sprintf('%s: %d closed day(s) checked, %d with hours to correct.',
            $this->option('apply') ? 'Applied' : 'Preview — nothing changed', $checked, count($changed)));

        return self::SUCCESS;
    }
}
