<?php

namespace App\Console\Commands;

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

        foreach ($days->groupBy('employee_id') as $employeeId => $employeeDays) {
            $employee = Employee::with('user')->find($employeeId);
            if (! $employee) {
                continue;
            }

            foreach ($employeeDays as $d) {
                $counts['checked']++;
                $row = $rebuilder->examine($employee, Carbon::parse($d->day));

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
            $row['skip'] !== null ? 'SKIP: '.$row['skip'] : ($apply ? 'updated: ' : 'yes: ').implode(', ', array_keys($row['changes'])),
        ];
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
