<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Services\Attendance\AttendanceDayRebuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Re-process every employee's punches through the canonical PunchTimeline
 * (Face = IN, ID Card = OUT, latest of a 60-second burst, stray cards
 * ignored) and bring the processed attendance rows and daily summaries in
 * line with it.
 *
 * Preview by default — nothing is written without --apply. Raw punches are
 * never modified or deleted. A day is skipped, and listed with the reason,
 * when changing it would mean guessing or rewriting history: regularised
 * days, days inside an approved / locked payroll, days with no valid Face IN,
 * an impossible duration, or no attendance row to update.
 */
#[Signature('attendance:rebuild-punch-timelines
    {--employee= : Limit to one employee (id or employee code)}
    {--date= : One day (Y-m-d)}
    {--from= : First day (Y-m-d)}
    {--to= : Last day (Y-m-d)}
    {--apply : Write the rebuilt attendance (otherwise preview only)}')]
#[Description('Rebuild processed attendance from the raw punches with the canonical timeline — preview unless --apply')]
class RebuildPunchTimelines extends Command
{
    public function handle(AttendanceDayRebuilder $rebuilder): int
    {
        [$from, $to] = $this->range();
        if ($from === false) {
            return self::FAILURE;
        }

        $employeeIds = $this->employeeIds();
        if ($employeeIds === false) {
            $this->error('No employee matches --employee='.$this->option('employee').'.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $rows = [];
        $counts = ['checked' => 0, 'change' => 0, 'unchanged' => 0, 'skipped' => 0, 'applied' => 0];

        $days = AttendancePunch::query()
            ->selectRaw('employee_id, DATE(punch_date) as day')
            ->when($from, fn ($q) => $q->whereDate('punch_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('punch_date', '<=', $to))
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->groupBy('employee_id', 'day')
            ->orderBy('employee_id')->orderBy('day')
            ->get();

        // Employee-days to look at: every day with punches, plus attendance
        // rows with NO punches at all and no web / device trace — the shape of
        // the synthetic holiday-work rows the old approval wrote.
        $targets = [];
        foreach ($days as $d) {
            $targets[$d->employee_id.'|'.$d->day] = [(int) $d->employee_id, $d->day];
        }
        $bare = Attendance::query()
            ->whereNotNull('check_in')
            ->whereNull('check_in_method')->whereNull('check_in_ip')->whereNull('check_in_user_agent')
            ->where('is_regularized', false)->where('status', '!=', 'leave')
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('date', '<=', $to))
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('attendance_punches')
                ->whereColumn('attendance_punches.employee_id', 'attendances.employee_id')
                ->whereColumn('attendance_punches.punch_date', 'attendances.date'))
            ->get(['employee_id', 'date']);
        foreach ($bare as $a) {
            $targets[$a->employee_id.'|'.$a->date->toDateString()] ??= [(int) $a->employee_id, $a->date->toDateString()];
        }
        ksort($targets);

        foreach (collect($targets)->groupBy(fn ($t) => $t[0]) as $employeeId => $employeeDays) {
            $employee = Employee::with('user')->find($employeeId);
            if (! $employee) {
                continue;
            }

            foreach ($employeeDays as [, $day]) {
                $counts['checked']++;
                $row = $rebuilder->examine($employee, Carbon::parse($day), allowClear: true);

                if ($row['skip'] !== null) {
                    $counts['skipped']++;
                } elseif ($row['changes'] === []) {
                    $counts['unchanged']++;

                    continue;
                } else {
                    $counts['change']++;
                    if ($apply) {
                        $rebuilder->apply($employee, $row, 'update', audit: true, reason: 'attendance:rebuild-punch-timelines --apply', score: true);
                        $counts['applied']++;
                    }
                }

                $rows[] = $this->display($employee, $row, $apply);
            }
        }

        $this->table([
            'Employee', 'Date', 'Old first IN', 'New first IN', 'Old last OUT', 'New last OUT',
            'Old worked', 'New worked', 'Old break', 'New break', 'Dup', 'Ignored', 'Dir fixed', 'Row changes',
        ], $rows);

        $this->info(sprintf(
            '%s: %d employee-day(s) checked — %d to change, %d unchanged, %d skipped (left as they are).',
            $apply ? "Applied ({$counts['applied']} written)" : 'DRY RUN — nothing changed',
            $counts['checked'], $counts['change'], $counts['unchanged'], $counts['skipped'],
        ));

        if (! $apply && $counts['change'] > 0) {
            $this->line('Run again with --apply to write these changes. Raw punches are never modified.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    private function display(Employee $employee, array $row, bool $apply): array
    {
        $time = fn ($t) => $t ? Carbon::parse($t)->format('H:i:s') : '—';
        $hours = fn ($h) => $h === null ? '—' : number_format((float) $h, 2).'h';

        return [
            trim(($employee->employee_code ?? $employee->id).' '.($employee->user?->name ?? '')),
            $row['day']->toDateString(),
            $time($row['old_in']), $time($row['new_in']),
            $time($row['old_out']), $time($row['new_out']),
            $hours($row['old_worked']), $hours($row['new_worked']),
            $row['old_break'] === null ? '—' : $row['old_break'].'m', $row['new_break'].'m',
            (string) $row['duplicates'], (string) $row['ignored'], (string) $row['directions'],
            $this->verdict($row, $apply),
        ];
    }

    /**
     * The last column: what happens to the day, naming synthetic rows and rows
     * with no supporting genuine punches.
     *
     * @param  array<string, mixed>  $row
     */
    private function verdict(array $row, bool $apply): string
    {
        if ($row['clear']) {
            return ($apply ? 'REMOVED' : 'REMOVE').': synthetic '.$row['synthetic'].' row — no genuine punches';
        }

        if ($row['skip'] !== null) {
            return 'SKIP: '.$row['skip'].($row['unsupported'] ? ' [attendance row has no supporting genuine punches]' : '');
        }

        return ($apply ? 'updated: ' : 'yes: ').implode(', ', array_keys($row['changes']));
    }

    /** @return array{0: string|null|false, 1: string|null} */
    private function range(): array
    {
        $date = $this->option('date');
        $from = $date ?: $this->option('from');
        $to = $date ?: $this->option('to');

        foreach (['from' => $from, 'to' => $to] as $name => $value) {
            if ($value !== null && $value !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
                $this->error("--{$name} must be a date as Y-m-d.");

                return [false, null];
            }
        }

        return [$from ?: null, $to ?: null];
    }

    /** @return array<int, int>|null|false null = everyone, false = no match */
    private function employeeIds(): array|null|false
    {
        $option = $this->option('employee');
        if ($option === null || $option === '') {
            return null;
        }

        $ids = Employee::where('id', $option)->orWhere('employee_code', $option)->orWhere('employee_id', $option)->pluck('id')->all();

        return $ids === [] ? false : $ids;
    }
}
