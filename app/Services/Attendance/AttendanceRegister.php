<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\OtRequest;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Attendance date-wise and over a range, punch by punch — the read model
 * behind Live Attendance's By Day, By Employee and Punch Audit tabs.
 *
 * WHO is the viewer's view_live_attendance scope, exactly as on the live
 * board (LiveAttendanceService::visibleEmployees). WHAT comes from the
 * canonical services, never recalculated here: each day from
 * AttendanceCalculator (first in, last out, worked, breaks, lateness,
 * missing check-out, auto check-out, regularised), its status from
 * AttendanceStatusResolver, and its punches with every missed punch from
 * MissedPunchTracker::trail() — the same trail as Punch Details on My
 * Attendance.
 *
 * Bounded: the punches, attendance rows, approved regularisations and
 * approved overtime of the whole range are read in one query each, then each
 * day is worked out from memory. A range is capped (MAX_*_DAYS).
 */
class AttendanceRegister
{
    public const MAX_EMPLOYEE_DAYS = 92;

    public const MAX_AUDIT_DAYS = 31;

    /** By Day status filters. */
    public const DAY_STATUSES = [
        'present' => 'Present',
        'late' => 'Late',
        'missed' => 'Missed punch',
        'missing' => 'Missing checkout',
        'absent' => 'Absent',
        'leave' => 'On leave',
        'off' => 'Day off',
    ];

    /** What the Punch Audit lists, in the order a reviewer works through them. */
    public const AUDIT_TYPES = [
        'missed_punch' => 'Missed punch',
        'missing_checkout' => 'Missing check-out',
        'late' => 'Late arrival',
        'auto_checkout' => 'Auto check-out',
        'long_break' => 'Long break',
        'regularised' => 'Regularised',
        'extra_taps' => 'Extra taps',
        'absent' => 'Absent',
    ];

    public function __construct(
        private readonly LiveAttendanceService $live,
        private readonly AttendanceStatusResolver $status,
        private readonly MissedPunchTracker $missed,
    ) {}

    /**
     * Everyone the viewer may see, on one date.
     *
     * @param  array{department?: int|string|null, search?: string|null, status?: string|null}  $filters
     * @return array{date: string, rows: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function day(User $viewer, CarbonInterface $date, array $filters = []): array
    {
        $day = Carbon::parse($date->toDateString())->min(Carbon::today());
        $employees = $this->live->narrow($this->live->visibleEmployees($viewer), $filters);
        $lines = $this->lines($employees, $day, $day)
            ->map(fn (array $byDate) => $byDate[$day->toDateString()] ?? null)->filter()->values();

        $counts = ['all' => $lines->count()] + collect(array_keys(self::DAY_STATUSES))
            ->mapWithKeys(fn (string $key) => [$key => $lines->filter(fn (array $l) => $this->matchesDay($l, $key))->count()])->all();
        $status = (string) ($filters['status'] ?? '');

        return [
            'date' => $day->toDateString(),
            'rows' => $lines->filter(fn (array $l) => $status === '' || $this->matchesDay($l, $status))
                ->sortBy(fn (array $l) => $l['present']
                    ? [0, $l['first_in_ms'] ?? 0, mb_strtolower($l['name'])]
                    : [1, $l['rank'], mb_strtolower($l['name'])])->values()->all(),
            'counts' => $counts,
        ];
    }

    /**
     * One person, day by day over a range (newest first), with totals — or
     * null when they are outside the viewer's scope.
     *
     * @return array{employee: Employee, from: string, to: string, days: list<array<string, mixed>>, totals: array<string, int|float>}|null
     */
    public function employee(User $viewer, int $employeeId, CarbonInterface $from, CarbonInterface $to): ?array
    {
        $employee = $this->live->visibleEmployees($viewer)->firstWhere('id', $employeeId);
        if ($employee === null) {
            return null;
        }

        [$start, $end] = self::range($from, $to, self::MAX_EMPLOYEE_DAYS);
        $days = collect($this->lines(collect([$employee]), $start, $end)->get($employee->id, []))
            ->sortKeysDesc()->values();
        $worked = $days->where('present', true);

        return [
            'employee' => $employee,
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'days' => $days->all(),
            'totals' => [
                'present' => $worked->count(),
                'late' => $days->where('late', true)->count(),
                'absent' => $days->where('key', 'absent')->count(),
                'leave' => $days->where('key', 'leave')->count(),
                'worked_minutes' => (int) $worked->sum('worked_minutes'),
                'average_minutes' => $worked->count() ? (int) round($worked->sum('worked_minutes') / $worked->count()) : 0,
                'missed' => (int) $days->sum('missed_count'),
                'missing_checkout' => $days->where('missing_checkout', true)->count(),
                'regularised' => $days->where('regularised', true)->count(),
            ],
        ];
    }

    /**
     * Every attendance exception over a range for the people in reach: missed
     * punches, late arrivals, missing and auto check-outs, long breaks,
     * absences, regularised days and extra taps (duplicates / ignored scans).
     *
     * @param  array{department?: int|string|null, search?: string|null, type?: string|null}  $filters
     * @return array{from: string, to: string, items: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function audit(User $viewer, CarbonInterface $from, CarbonInterface $to, array $filters = []): array
    {
        [$start, $end] = self::range($from, $to, self::MAX_AUDIT_DAYS);
        $employees = $this->live->narrow($this->live->visibleEmployees($viewer), $filters);

        $items = [];
        foreach ($this->lines($employees, $start, $end) as $byDate) {
            foreach ($byDate as $line) {
                array_push($items, ...$this->exceptions($line));
            }
        }

        $items = collect($items);
        $counts = ['all' => $items->count()] + collect(array_keys(self::AUDIT_TYPES))
            ->mapWithKeys(fn (string $type) => [$type => $items->where('type', $type)->count()])->all();
        $type = (string) ($filters['type'] ?? '');

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'items' => $items->when($type !== '', fn (Collection $c) => $c->where('type', $type))
                ->sortBy(fn (array $i) => [-strtotime($i['date']), array_search($i['type'], array_keys(self::AUDIT_TYPES), true), mb_strtolower($i['name']), $i['order']])->values()->all(),
            'counts' => $counts,
        ];
    }

    /**
     * A valid range: from ≤ to, never past today, at most $maxDays long
     * (counted back from the end).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function range(CarbonInterface $from, CarbonInterface $to, int $maxDays): array
    {
        $end = Carbon::parse($to->toDateString())->min(Carbon::today());
        $start = Carbon::parse($from->toDateString())->min($end);

        if ($start->diffInDays($end) >= $maxDays) {
            $start = $end->copy()->subDays($maxDays - 1);
        }

        return [$start, $end];
    }

    /**
     * Each employee's days from $start to $end, worked out from data read in
     * one query per kind.
     *
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, array<string, array<string, mixed>>> by employee id, then date
     */
    private function lines(Collection $employees, Carbon $start, Carbon $end): Collection
    {
        if ($employees->isEmpty()) {
            return collect();
        }

        $ids = $employees->pluck('id');
        $range = [$start->toDateString(), $end->toDateString()];
        $punches = AttendancePunch::whereIn('employee_id', $ids)->whereBetween('punch_date', $range)
            ->orderBy('punched_at')->get()
            ->groupBy(fn (AttendancePunch $p) => $p->employee_id.'|'.$p->punch_date->toDateString());
        $rows = Attendance::whereIn('employee_id', $ids)->whereBetween('date', $range)->with('activeBreak')->get()
            ->keyBy(fn (Attendance $a) => $a->employee_id.'|'.$a->date->toDateString());
        $regularised = AttendanceRegularisation::whereIn('employee_id', $ids)->where('status', 'approved')
            ->whereBetween('work_date', $range)->get(['employee_id', 'work_date'])
            ->mapWithKeys(fn ($r) => [$r->employee_id.'|'.Carbon::parse($r->work_date)->toDateString() => true]);
        $overtime = OtRequest::whereIn('employee_id', $ids)->where('status', 'approved')
            ->whereBetween('work_date', $range)->get(['employee_id', 'work_date'])
            ->mapWithKeys(fn ($r) => [$r->employee_id.'|'.Carbon::parse($r->work_date)->toDateString() => true]);

        return $employees->mapWithKeys(function (Employee $employee) use ($start, $end, $punches, $rows, $regularised, $overtime) {
            // Approved leave over the whole range in one query for this person.
            $calculator = app(AttendanceCalculator::class, [
                'workingDays' => app(WorkingDayResolver::class)->primeApprovedLeaveRange($employee, $start, $end),
            ]);

            $days = [];
            for ($date = $start->copy(); $date->lte($end); $date = $date->copy()->addDay()) {
                $key = $employee->id.'|'.$date->toDateString();
                $days[$date->toDateString()] = $this->line($employee, $date, $calculator,
                    $punches->get($key, collect()), $rows->get($key), isset($regularised[$key]), isset($overtime[$key]));
            }

            return [$employee->id => $days];
        });
    }

    /**
     * One employee's day: the figures, the status and the punch trail.
     *
     * @param  Collection<int, AttendancePunch>  $punches
     * @return array<string, mixed>
     */
    private function line(Employee $employee, Carbon $date, AttendanceCalculator $calculator, Collection $punches, ?Attendance $row, bool $regularised, bool $overtime): array
    {
        $day = $calculator->forDay($employee, $date, $row, $punches, null, $overtime);
        $status = $this->status->describe($day, $row);
        $trail = $this->missed->trail($employee, $date, $punches, $row, $regularised);
        $auto = $day->source === AttendanceCalculator::SOURCE_AUTO_CHECKOUT;
        $late = $day->isLate && $day->firstIn !== null;
        $present = $day->firstIn !== null;
        $isToday = $date->isToday();

        [$key, $label, $tone, $rank] = match (true) {
            $status['state'] === AttendanceStatusResolver::MISSING_CHECKOUT => ['missing', 'Missing checkout', 'red', 0],
            $status['state'] === AttendanceStatusResolver::WORKING => ['working', $late ? 'Working · late' : 'Working', 'green', 2],
            $status['state'] === AttendanceStatusResolver::ON_BREAK => ['working', 'On break', 'blue', 2],
            $auto => ['present', 'Auto check-out', 'amber', 1],
            $present => ['present', $late ? 'Late' : 'Present', $late ? 'amber' : 'green', $late ? 1 : 3],
            default => match ($status['reason']) {
                'leave' => ['leave', 'On leave', 'violet', 5],
                'weekly_off' => ['off', 'Weekly off', 'zinc', 6],
                'holiday', 'mdl' => ['off', $status['reason'] === 'mdl' ? 'MDL shutdown' : 'Holiday', 'zinc', 6],
                'absent' => ['absent', 'Absent', 'red', 0],
                'not_employed' => ['not_employed', 'Not employed', 'zinc', 7],
                default => ['not_in', $isToday ? 'Not in yet' : 'No record', 'zinc', 4],
            },
        };

        $name = $employee->user?->name ?? '—';
        $missed = ($trail['counts']['missed'] ?? 0) + ($trail['counts']['missed_corrected'] ?? 0);

        return [
            'employee_id' => $employee->id,
            'name' => $name,
            'initials' => LiveAttendanceService::initials($name),
            'code' => (string) $employee->employee_id,
            'department' => $employee->department?->name ?? '—',
            'date' => $date->toDateString(),
            'weekday' => $date->format('D'),
            'key' => $key,
            'label' => $label,
            'tone' => $tone,
            'rank' => $rank,
            'present' => $present,
            'late' => $late,
            'late_minutes' => $late ? $day->lateMinutes : 0,
            'first_in' => $day->firstIn?->format('h:i A'),
            'first_in_ms' => $day->firstIn ? (int) Carbon::parse($day->firstIn)->getTimestampMs() : null,
            'last_out' => $day->lastOut?->format('h:i A'),
            'worked_minutes' => $day->workedMinutes,
            'expected_minutes' => $day->expectedMinutes,
            'progress' => LiveAttendanceService::progressTone($day->workedMinutes, $day->expectedMinutes),
            'break_minutes' => $day->breakMinutes,
            'excess_break' => $day->excessBreak,
            'missing_checkout' => $day->missingCheckout,
            'auto_checkout' => $auto,
            'regularised' => $day->regularised || $regularised,
            'shift' => $day->shift ? $day->shift->name.' · '.$day->shift->start->format('g:i A').' – '.$day->shift->end->format('g:i A') : 'No shift',
            'shift_start' => $day->shift?->start->format('h:i A'),
            'grace' => $day->shift?->graceMinutes ?? 0,
            'mode' => $row?->work_mode,
            'trail' => $trail['rows'],
            'trail_counts' => $trail['counts'],
            'missed_count' => $missed,
            'extra_taps' => ($trail['counts']['duplicate'] ?? 0) + ($trail['counts']['ignored'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $line */
    private function matchesDay(array $line, string $status): bool
    {
        return match ($status) {
            'present' => $line['present'],
            'late' => $line['late'],
            'missed' => $line['missed_count'] > 0,
            'missing' => $line['missing_checkout'],
            'absent' => $line['key'] === 'absent',
            'leave' => $line['key'] === 'leave',
            'off' => $line['key'] === 'off',
            default => true,
        };
    }

    /**
     * The exceptions of one employee-day, for the Punch Audit.
     *
     * @param  array<string, mixed>  $line
     * @return list<array<string, mixed>>
     */
    private function exceptions(array $line): array
    {
        $base = ['date' => $line['date'], 'employee_id' => $line['employee_id'], 'name' => $line['name'],
            'initials' => $line['initials'], 'department' => $line['department']];
        $hm = fn (int $m): string => intdiv($m, 60).'h '.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT).'m';
        $items = [];

        $checkoutListed = $line['missing_checkout'] || $line['auto_checkout'];
        foreach ($line['trail'] as $i => $node) {
            if ($checkoutListed && $node['title'] === 'Missed check-out') {
                continue;
            }
            if (in_array($node['state'], ['missed', 'missed_corrected'], true)) {
                $items[] = $base + ['type' => 'missed_punch', 'order' => 10 + $i, 'title' => $node['title'], 'detail' => $node['note'],
                    'resolved' => $node['state'] === 'missed_corrected'];
            }
        }
        if ($line['late']) {
            $items[] = $base + ['type' => 'late', 'order' => 1, 'title' => 'Late by '.$line['late_minutes'].'m',
                'detail' => 'In at '.$line['first_in'].' · shift starts '.($line['shift_start'] ?? '—').' with '.$line['grace'].'m grace', 'resolved' => false];
        }
        if ($line['missing_checkout']) {
            $items[] = $base + ['type' => 'missing_checkout', 'order' => 2, 'title' => 'No check-out',
                'detail' => 'In at '.($line['first_in'] ?? '—').' and never checked out', 'resolved' => false];
        }
        if ($line['auto_checkout']) {
            $items[] = $base + ['type' => 'auto_checkout', 'order' => 3, 'title' => 'Closed by the system at '.($line['last_out'] ?? '—'),
                'detail' => 'No OUT punch — the nightly auto check-out closed the day at the shift end', 'resolved' => false];
        }
        if ($line['excess_break']) {
            $items[] = $base + ['type' => 'long_break', 'order' => 4, 'title' => 'Away '.$hm($line['break_minutes']).' in breaks',
                'detail' => 'More than '.AttendanceCalculator::EXCESS_BREAK_MINUTES.' minutes out between punches', 'resolved' => false];
        }
        if ($line['key'] === 'absent') {
            $items[] = $base + ['type' => 'absent', 'order' => 0, 'title' => 'Absent', 'detail' => 'A working day with no punch and no leave', 'resolved' => false];
        }
        if ($line['regularised']) {
            $items[] = $base + ['type' => 'regularised', 'order' => 5, 'title' => 'Corrected by HR',
                'detail' => 'An approved regularisation changed the day', 'resolved' => true];
        }
        if ($line['extra_taps'] > 0) {
            $items[] = $base + ['type' => 'extra_taps', 'order' => 6, 'title' => $line['extra_taps'].' extra '.($line['extra_taps'] === 1 ? 'tap' : 'taps'),
                'detail' => trim(($line['trail_counts']['duplicate'] ?? 0).' read twice · '.($line['trail_counts']['ignored'] ?? 0).' ignored'), 'resolved' => true];
        }

        return $items;
    }
}
