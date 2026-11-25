<?php

namespace App\Services\Attendance;

use App\Enums\PunchMethod;
use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\OtRequest;
use App\Models\ShiftSetting;
use App\Services\AttendanceReportBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The attendance Excel workbook: Summary, Late Marks, Detailed Attendance,
 * Shift Changes and an About sheet, for the people and period the Attendance
 * Reports filters select.
 *
 * Every figure is the calculator's (AttendanceWindowService / AttendanceDay),
 * the same as every page shows: late = shift start + grace, worked = final
 * out − first in, breaks are information only, overtime counts only with an
 * approved request. Each day is judged against the shift that applied on it
 * (ShiftHistory), and a shift change in the period is spelled out.
 */
class AttendanceWorkbook
{
    private const STATUS_LABELS = [
        'present' => 'Present',
        'late' => 'Late',
        'absent' => 'Absent',
        'weekly_off' => 'Weekly off',
        'holiday' => 'Holiday',
        'leave' => 'On leave',
        'upcoming' => 'No punch yet',
    ];

    public function __construct(
        private AttendanceReportBuilder $reports,
        private AttendanceWindowService $window,
        private AttendanceCalculator $calculator,
        private ShiftResolver $shifts,
        private MissedPunchTracker $missed,
    ) {}

    /**
     * The sheets, ready for SpreadsheetService::downloadWorkbook().
     *
     * @param  array<string, mixed>  $filters  the Attendance Reports filters (+ employee_ids reach)
     * @return list<array{name: string, headings: list<string>, rows: list<list<mixed>>, widths: list<float>}>
     */
    public function sheets(array $filters): array
    {
        [$from, $to] = $this->period($filters);
        $mode = (string) ($filters['mode'] ?? '');
        $employees = $this->reports->employeeQuery($filters, activeOnly: false)
            ->with(['user', 'department'])->orderBy('id')->get();

        $history = app(ShiftHistory::class);
        $changes = $history->changesBetween($from, $to, $employees->pluck('id')->all());

        $summary = [];
        $late = [];
        $detail = [];

        foreach ($employees as $employee) {
            $days = $this->window->days($employee, $from, $to);
            if (collect($days)->every(fn (array $d) => $d['status'] === 'not_employed')) {
                continue;
            }

            $metrics = $this->window->dayMetrics($employee, $from, $to->copy()->min(Carbon::today()));
            $rows = Attendance::where('employee_id', $employee->id)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->get()->keyBy(fn (Attendance $a) => $a->date->toDateString());
            $punches = AttendancePunch::where('employee_id', $employee->id)
                ->whereBetween('punch_date', [$from->toDateString(), $to->toDateString()])
                ->orderBy('punched_at')->get()
                ->groupBy(fn (AttendancePunch $p) => $p->punch_date->toDateString());
            $missedByDay = $this->missed->between($employee, $from, $to);
            $otDays = OtRequest::where('employee_id', $employee->id)->where('status', 'approved')
                ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
                ->pluck('work_date')->map(fn ($d) => Carbon::parse($d)->toDateString())->flip();

            $note = $this->shiftNote($employee, $changes, $history);
            $identity = [$employee->employee_id ?? '—', $employee->user?->name ?? '—', $employee->department?->name ?? '—'];
            $shiftsUsed = [];
            $totals = ['present' => 0, 'late' => 0, 'late_min' => 0, 'absent' => 0, 'leave' => 0.0, 'off' => 0, 'off_worked' => 0,
                'worked_min' => 0, 'missing_out' => 0, 'regularised' => 0, 'ot_min' => 0];

            foreach ($days as $date => $day) {
                if ($day['status'] === 'not_employed' || ($mode !== '' && $day['attended'] && $day['mode'] !== $mode)) {
                    continue;
                }

                $shift = $this->shifts->resolve($employee, $date);
                $shiftName = $shift?->name ?? 'No shift';
                $shiftsUsed[$shiftName.' ('.($shift ? $shift->start->format('H:i').'–'.$shift->end->format('H:i') : '—').')'] = true;

                $m = $metrics[$date] ?? null;
                $row = $rows->get($date);
                $dayPunches = $punches->get($date, collect());
                $firstIn = $day['first_in_min'] !== null ? $this->clock($date, $day['first_in_min']) : ($row?->check_in?->format('h:i A'));
                $lastOut = $day['last_out_min'] !== null ? $this->clock($date, $day['last_out_min']) : ($row?->check_out?->format('h:i A'));
                $otMinutes = $day['attended'] && isset($otDays[$date])
                    ? $this->calculator->forDay($employee, $date, $row, $dayPunches, hasApprovedOt: true)->approvedOtMinutes
                    : 0;

                $totals['present'] += in_array($day['status'], ['present', 'late'], true) ? 1 : 0;
                $totals['late'] += $day['status'] === 'late' ? 1 : 0;
                $totals['late_min'] += $day['late_minutes'];
                $totals['absent'] += $day['status'] === 'absent' ? 1 : 0;
                $totals['leave'] += $day['status'] === 'leave' ? ($day['leave_days'] ?: 1.0) : 0;
                $totals['off'] += in_array($day['status'], ['weekly_off', 'holiday'], true) ? 1 : 0;
                $totals['off_worked'] += $day['attended'] && in_array($day['status'], ['weekly_off', 'holiday'], true) ? 1 : 0;
                $totals['worked_min'] += $day['worked'];
                $totals['missing_out'] += $day['missing_out'] ? 1 : 0;
                $totals['regularised'] += $row?->is_regularized ? 1 : 0;
                $totals['ot_min'] += $otMinutes;

                $dateCells = [$date, Carbon::parse($date)->format('D')];

                if ($day['status'] === 'late') {
                    $late[] = [...$identity, ...$dateCells,
                        $shiftName,
                        $shift?->start->format('h:i A') ?? '—',
                        $shift?->graceMinutes ?? 0,
                        $shift?->lateCutoff()->format('h:i A') ?? '—',
                        $firstIn ?? '—',
                        $day['late_minutes'],
                        $this->hm($day['late_minutes']),
                        $lastOut ?? '—',
                        $this->hm($day['worked']),
                        $row?->is_regularized ? 'Yes' : 'No',
                        $note,
                    ];
                }

                $status = self::STATUS_LABELS[$day['status']] ?? ucfirst(str_replace('_', ' ', $day['status']));
                if ($day['attended'] && in_array($day['status'], ['weekly_off', 'holiday'], true)) {
                    $status = 'Worked on '.strtolower($status);
                }

                $detail[] = [...$identity, ...$dateCells,
                    $status,
                    $shiftName,
                    $shift ? $shift->start->format('h:i A').' – '.$shift->end->format('h:i A').' · '.$shift->graceMinutes.' min grace' : '—',
                    $firstIn ?? '—',
                    $lastOut ?? '—',
                    $this->hm($day['worked']),
                    round($day['worked'] / 60, 2),
                    (int) ($m['break'] ?? $row?->break_minutes ?? 0),
                    $day['late_minutes'],
                    round($otMinutes / 60, 2),
                    $day['missing_out'] ? 'Yes' : 'No',
                    $dayPunches->count(),
                    $this->punchList($dayPunches),
                    $this->method($row?->check_in_method),
                    $this->method($row?->check_out_method),
                    $day['attended'] ? ucfirst((string) ($row?->work_mode ?? 'office')) : '—',
                    $row?->is_regularized ? 'Yes' : 'No',
                    $row?->is_auto_checkout ? 'Yes' : 'No',
                    $dayPunches->isNotEmpty() ? 'Biometric' : ($row ? 'Web / manual' : '—'),
                    count($missedByDay[$date] ?? []),
                    collect($missedByDay[$date] ?? [])->map(fn (array $m) => $m['label'].($m['resolved'] ? ' (regularised)' : ''))->implode('; '),
                ];
            }

            $summary[] = [...$identity,
                implode(' → ', array_keys($shiftsUsed)) ?: '—',
                $note !== '' ? $note : 'No change',
                $totals['present'] + $totals['absent'] + (int) ceil($totals['leave']),
                $totals['present'],
                $totals['late'],
                $totals['late_min'],
                $this->hm($totals['late_min']),
                $totals['late'] ? (int) round($totals['late_min'] / $totals['late']) : 0,
                $totals['absent'],
                $totals['leave'],
                $totals['off'],
                $totals['off_worked'],
                round($totals['worked_min'] / 60, 2),
                $totals['present'] ? round($totals['worked_min'] / 60 / $totals['present'], 2) : 0,
                $totals['missing_out'],
                $totals['regularised'],
                round($totals['ot_min'] / 60, 2),
                collect($missedByDay)->sum(fn (array $list) => count($list)),
                collect(array_keys($missedByDay))->map(fn (string $date) => MissedPunchTracker::monthStart(Carbon::parse($date))->toDateString())->unique()
                    ->filter(fn (string $month) => MissedPunchTracker::countInMonth($missedByDay, Carbon::parse($month)) > MissedPunchTracker::allowance())->count(),
            ];
        }

        return [
            [
                'name' => 'Summary',
                'headings' => ['Emp ID', 'Employee', 'Department', 'Shift(s) in period', 'Shift change', 'Working days', 'Present (incl. late)',
                    'Late marks', 'Total late (min)', 'Total late', 'Avg late (min)', 'Absent', 'Leave days', 'Weekly offs / holidays',
                    'Worked on off day', 'Total worked (h)', 'Avg worked per present day (h)', 'Missing check-outs', 'Regularised days', 'Approved OT (h)',
                    'Missed punches', 'Months over the allowance ('.MissedPunchTracker::allowance().')'],
                'rows' => $summary,
                'widths' => [10, 26, 18, 44, 60, 12, 12, 10, 12, 12, 12, 9, 10, 14, 12, 12, 14, 12, 12, 12, 12, 14],
            ],
            [
                'name' => 'Late Marks',
                'headings' => ['Emp ID', 'Employee', 'Department', 'Date', 'Day', 'Shift', 'Shift start', 'Grace (min)', 'Late after',
                    'First in', 'Late by (min)', 'Late by', 'Last out', 'Worked', 'Regularised', 'Shift note'],
                'rows' => $late,
                'widths' => [10, 26, 18, 12, 6, 18, 11, 10, 11, 10, 12, 10, 10, 10, 11, 60],
            ],
            [
                'name' => 'Detailed Attendance',
                'headings' => ['Emp ID', 'Employee', 'Department', 'Date', 'Day', 'Status', 'Shift', 'Shift timing', 'First in', 'Last out',
                    'Worked', 'Worked (h)', 'Break (min)', 'Late by (min)', 'Approved OT (h)', 'Missing check-out', 'Punches', 'Punch times',
                    'In method', 'Out method', 'Work mode', 'Regularised', 'Auto check-out', 'Source', 'Missed punches', 'Missed punch details'],
                'rows' => $detail,
                'widths' => [10, 26, 18, 12, 6, 13, 18, 34, 10, 10, 10, 10, 10, 12, 13, 14, 9, 60, 11, 11, 11, 11, 13, 13, 12, 60],
            ],
            [
                'name' => 'Shift Changes',
                'headings' => ['Date', 'Change', 'Emp ID', 'Employee', 'Department', 'Previous shift', 'Previous timing', 'New shift', 'New timing', 'Changed by'],
                'rows' => $this->changeRows($changes, $employees, $history),
                'widths' => [12, 18, 10, 26, 18, 18, 30, 18, 30, 20],
            ],
            [
                'name' => 'About',
                'headings' => ['Item', 'Details'],
                'rows' => $this->about($from, $to, $filters),
                'widths' => [26, 110],
            ],
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} the period, never past today */
    private function period(array $filters): array
    {
        $from = ! empty($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : Carbon::now()->startOfMonth();
        $to = ! empty($filters['to']) ? Carbon::parse($filters['to'])->startOfDay() : Carbon::today();
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to->min(Carbon::today())];
    }

    /**
     * "Shift changed on 05 Nov 2026: UK Sales Shift 13:00 – 22:00 → IT Shift 10:30 – 19:30", for the
     * employee's own moves and edits of the shifts they were on.
     *
     * @param  list<array<string, mixed>>  $changes
     */
    private function shiftNote(Employee $employee, array $changes, ShiftHistory $history): string
    {
        $mine = array_filter($changes, function (array $c) use ($employee, $history): bool {
            if ($c['type'] === 'reassigned') {
                return $c['employee_id'] === $employee->id;
            }

            // A timing edit matters when the employee was on that shift on the day it changed.
            return $history->shiftIdOn($employee, Carbon::parse($c['date'])) === $c['shift_id'];
        });

        return implode('; ', array_map(fn (array $c) => ($c['type'] === 'reassigned' ? 'Shift changed on ' : 'Shift timing changed on ')
            .Carbon::parse($c['date'])->format('d M Y').': '
            .$this->shiftText($c['before']).' → '.$this->shiftText($c['after']), $mine));
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     * @param  Collection<int, Employee>  $employees
     * @return list<list<mixed>>
     */
    private function changeRows(array $changes, Collection $employees, ShiftHistory $history): array
    {
        $byId = $employees->keyBy('id');
        $rows = [];

        foreach ($changes as $c) {
            $employee = $c['employee_id'] !== null ? $byId->get($c['employee_id']) : null;
            $onShift = $c['type'] === 'timing'
                ? $employees->filter(fn (Employee $e) => $history->shiftIdOn($e, Carbon::parse($c['date'])) === $c['shift_id'])->count()
                : 0;

            $rows[] = [
                $c['date'],
                $c['type'] === 'reassigned' ? 'Moved to another shift' : 'Shift timing edited',
                $employee?->employee_id ?? '—',
                $employee?->user?->name ?? ($c['type'] === 'timing' ? "Everyone on this shift ({$onShift} in this report)" : '—'),
                $employee?->department?->name ?? '—',
                $c['before']?->name ?? 'No shift',
                ShiftHistory::timingLabel($c['before']),
                $c['after']?->name ?? 'No shift',
                ShiftHistory::timingLabel($c['after']),
                $c['by'] ?? 'System',
            ];
        }

        return $rows;
    }

    /** @return list<list<string>> */
    private function about(Carbon $from, Carbon $to, array $filters): array
    {
        $rows = [
            ['Period', $from->format('d M Y').' – '.$to->format('d M Y')],
            ['Generated', now()->format('d M Y, h:i A').(auth()->user() ? ' by '.auth()->user()->name : '')],
            ['Late mark', 'First IN after the shift start plus its grace minutes, on a working day. Weekly offs, holidays and leave are never late.'],
            ['Worked', 'Final clock-out minus first clock-in. Breaks are shown for information and never deducted.'],
            ['Approved OT', 'Time beyond the shift\'s overtime threshold, counted only when an overtime request for that day is approved.'],
            ['Shift used', 'Each day is judged against the shift the employee was on that day, with the timings that applied then. A move to another shift applies from the day it was made.'],
            ['Shift history', 'Moves to another shift are in the history since the first release. Edits of a shift\'s own timings are recorded from '
                .Carbon::parse(ShiftHistory::TIMING_AUDIT_FROM)->format('d M Y').'; an earlier timing edit is not in the history, so those days use the timings recorded after it.'],
            ['Missing check-out', 'A day with a clock-in and no final clock-out after the shift end plus one hour. Nothing more is counted for it.'],
            ['Missed punch', 'Two INs in a row (a missed OUT), two OUTs in a row (a missed IN), or no final check-out once it is overdue. A regularised day still shows what was missed. '
                .MissedPunchTracker::allowance().' are allowed in a calendar month (counted Monday–Friday); one more flags the employee for HR review.'],
            ['Source', 'Biometric = device punches synced from the attendance engine; Web / manual = clocked in on the web or recorded by HR.'],
        ];

        if (! empty($filters['mode'])) {
            $rows[] = ['Work mode filter', ucfirst((string) $filters['mode']).' days only'];
        }

        return $rows;
    }

    private function shiftText(?ShiftSetting $shift): string
    {
        return $shift === null ? 'No shift' : $shift->name.' '.ShiftHistory::timingLabel($shift);
    }

    /** @param Collection<int, AttendancePunch> $punches */
    private function punchList(Collection $punches): string
    {
        return $punches->map(fn (AttendancePunch $p) => $p->punched_at->format('h:i A')
            .(($label = $this->method($p->method)) !== '—' ? ' '.$label : ''))->implode(' · ');
    }

    private function method(mixed $method): string
    {
        if ($method instanceof PunchMethod) {
            return $method->label();
        }

        return $method ? (PunchMethod::tryFrom((string) $method)?->label() ?? ucfirst(str_replace('_', ' ', (string) $method))) : '—';
    }

    private function clock(string $date, int $minutes): string
    {
        return Carbon::parse($date)->startOfDay()->addMinutes($minutes)->format('h:i A');
    }

    private function hm(int $minutes): string
    {
        $minutes = max(0, $minutes);

        return intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }
}
