<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\AttendanceDailySummary;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Services\Attendance\AttendanceCalculator;
use App\Services\Attendance\AttendanceScoreEngine;
use App\Services\Attendance\PunchTimeline;
use App\Services\Audit\AuditService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
    /** Payroll statuses whose attendance must no longer change (submitted to finance, or final). */
    private const SETTLED_PAYROLL = ['pending_finance', 'finalized'];

    /** @var array<string, bool> employee|date → inside settled payroll */
    private array $settledCache = [];

    public function handle(PunchTimeline $timeline, AttendanceCalculator $calculator): int
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
                $row = $this->examine($employee, Carbon::parse($d->day), $timeline, $calculator);

                if ($row['skip'] !== null) {
                    $counts['skipped']++;
                } elseif ($row['changes'] === []) {
                    $counts['unchanged']++;

                    continue;
                } else {
                    $counts['change']++;
                    if ($apply) {
                        $this->write($employee, $row);
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
     * What the canonical timeline says about one employee-day, against what
     * the attendance row holds now.
     *
     * @return array<string, mixed>
     */
    private function examine(Employee $employee, Carbon $day, PunchTimeline $timeline, AttendanceCalculator $calculator): array
    {
        $punches = AttendancePunch::where('employee_id', $employee->id)
            ->whereDate('punch_date', $day->toDateString())
            ->orderBy('punched_at')
            ->get();
        $attendance = Attendance::where('employee_id', $employee->id)->whereDate('date', $day->toDateString())->first();
        $summary = AttendanceDailySummary::where('employee_id', $employee->id)->whereDate('date', $day->toDateString())->first();

        // Flags from the timeline; the figures from the calculator itself, so
        // the rebuilt row matches every screen exactly.
        $processed = $timeline->process($punches, $day);
        $flags = $processed['flags'];
        $calc = $calculator->forDay($employee, $day, $attendance, $punches);
        $in = $calc->firstIn ? Carbon::parse($calc->firstIn) : null;
        $out = $calc->lastOut ? Carbon::parse($calc->lastOut) : null;

        $worked = $out !== null ? $calculator->storedHours($in, $out) : null;
        $break = $calc->breakMinutes;

        $row = [
            'day' => $day,
            'punches' => $punches,
            'attendance' => $attendance,
            'summary' => $summary,
            'old_in' => $attendance?->check_in,
            'old_out' => $attendance?->check_out,
            'old_worked' => $attendance?->check_out ? (float) $attendance->total_hours : null,
            'old_break' => $attendance ? (int) ($attendance->break_minutes ?? 0) : null,
            'new_in' => $in,
            'new_out' => $out,
            'new_worked' => $worked,
            'new_break' => $break,
            'duplicates' => $flags['duplicate'] + $flags['retry'],
            'ignored' => $flags['stray'] + $flags['other_day'],
            'directions' => $flags['direction_corrected'],
            'changes' => [],
            'skip' => null,
        ];

        $row['skip'] = match (true) {
            $attendance === null => 'no attendance row',
            $attendance->is_regularized || $flags['regularised'] => 'regularised — corrected times kept',
            $processed['first_in_at'] === null || $in === null => 'no valid Face IN',
            $flags['impossible_duration'] => 'impossible duration — check manually',
            $this->inSettledPayroll($employee, $day) => 'inside approved payroll',
            default => null,
        };

        if ($row['skip'] !== null) {
            return $row;
        }

        $isLate = $calc->isLate;
        $target = [
            'check_in' => $in,
            'check_out' => $out,
            'total_hours' => $worked ?? 0.0,   // still open: nothing worked yet
            'break_minutes' => $break,
            'is_late' => $isLate,
            'late_minutes' => $calc->lateMinutes,
            'missing_checkout' => $out === null && ! $day->isToday(),
        ];
        if (in_array($attendance->status, ['on_time', 'late'], true)) {
            $target['status'] = $isLate ? 'late' : 'on_time';
        }

        $row['changes'] = $this->diff($attendance, $target);
        $row['target'] = $target + $this->methods($punches, $in, $out);

        return $row;
    }

    /**
     * Columns whose value would change.
     *
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function diff(Attendance $attendance, array $target): array
    {
        $changes = [];
        foreach ($target as $column => $value) {
            $current = $attendance->{$column};
            $same = match (true) {
                $value instanceof Carbon || $current instanceof \DateTimeInterface => ($value ? Carbon::parse($value)->format('Y-m-d H:i:s') : null) === ($current ? Carbon::parse($current)->format('Y-m-d H:i:s') : null),
                $column === 'total_hours' => ($value === null && $current === null) || ($value !== null && $current !== null && abs((float) $value - (float) $current) < 0.01),
                is_bool($value) => $value === (bool) $current,
                default => (string) $value === (string) $current,
            };
            if (! $same) {
                $changes[$column] = $value;
            }
        }

        return $changes;
    }

    /**
     * The verify method of the punches the day now starts and ends on.
     *
     * @param  Collection<int, AttendancePunch>  $punches
     * @return array<string, ?string>
     */
    private function methods(Collection $punches, ?Carbon $in, ?Carbon $out): array
    {
        $at = fn (?Carbon $t) => $t ? $punches->first(fn (AttendancePunch $p) => $p->punched_at->equalTo($t))?->method : null;

        return array_filter(['check_in_method' => $at($in), 'check_out_method' => $at($out)]);
    }

    /** @param  array<string, mixed>  $row */
    private function write(Employee $employee, array $row): void
    {
        /** @var Attendance $attendance */
        $attendance = $row['attendance'];
        $before = $attendance->only(['check_in', 'check_out', 'total_hours', 'break_minutes', 'status', 'is_late', 'missing_checkout']);

        $attendance->update($row['target']);

        $row['summary']?->update([
            'first_punch' => $row['new_in'],
            'last_punch' => $row['new_out'],
            'first_punch_method' => $row['target']['check_in_method'] ?? $row['summary']->first_punch_method,
            'last_punch_method' => $row['target']['check_out_method'] ?? $row['summary']->last_punch_method,
            'break_minutes' => $row['new_break'],
            'working_hours' => $row['new_worked'] ?? 0,
        ]);

        app(AuditService::class)->event('ATTENDANCE_TIMELINE_REBUILT', AuditService::ATTENDANCE, $attendance,
            old: $this->scalar($before),
            new: $this->scalar($attendance->fresh()->only(array_keys($before))),
            reason: 'attendance:rebuild-punch-timelines --apply',
            subjectEmployeeId: $employee->id,
            actor: null,
        );

        if (! $row['day']->isToday()) {
            app(AttendanceScoreEngine::class)->scoreDay($employee, $row['day']->toDateString());
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function scalar(array $values): array
    {
        return array_map(fn ($v) => $v instanceof \DateTimeInterface ? Carbon::parse($v)->format('Y-m-d H:i:s') : $v, $values);
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

    /**
     * Whether the day falls in a payroll that is with finance, finalised or
     * locked and holds a payslip for the employee.
     */
    private function inSettledPayroll(Employee $employee, Carbon $day): bool
    {
        $key = $employee->id.'|'.$day->toDateString();
        if (isset($this->settledCache[$key])) {
            return $this->settledCache[$key];
        }

        $payrollIds = Payslip::where('employee_id', $employee->id)->pluck('payroll_id');
        $settled = Payroll::whereIn('id', $payrollIds)
            ->where(fn ($q) => $q->whereIn('status', self::SETTLED_PAYROLL)->orWhereNotNull('locked_at'))
            ->get()
            ->contains(function (Payroll $payroll) use ($day) {
                try {
                    $month = Carbon::parse('1 '.$payroll->month.' '.$payroll->year);
                } catch (\Throwable) {
                    return true;   // unreadable period: protect it
                }
                [$start, $end] = $payroll->cycle === 'cycle_b'
                    ? [$month->copy()->subMonth()->setDay(21)->startOfDay(), $month->copy()->setDay(20)->endOfDay()]
                    : [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()];

                return $day->betweenIncluded($start, $end);
            });

        return $this->settledCache[$key] = $settled;
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
