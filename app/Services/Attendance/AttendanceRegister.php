<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OtRequest;
use App\Models\User;
use App\Support\DateDisplay;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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

    /** Audit events the Day history shows for a date, with how they read. Others on the day's row read as their headline. */
    public const HISTORY_EVENTS = [
        'ATTENDANCE_AUTO_CHECKOUT' => 'Closed by the nightly auto check-out',
        'ATTENDANCE_AUTO_CHECKOUT_SUPERSEDED' => 'Auto check-out replaced by a real punch',
        'ATTENDANCE_TIMELINE_REBUILT' => 'Day rebuilt from the punches',
        'ATTENDANCE_SYNTHETIC_ROW_CLEARED' => 'Placeholder record cleared',
        'ATTENDANCE_REGULARISATION_REVERTED' => 'Correction undone — day rebuilt from the device punches',
    ];

    /** Regularisation audit events already told by the request's own approval trail. */
    private const TRAIL_EVENTS = ['ATTENDANCE_REGULARISATION_CORRECTED', 'ATTENDANCE_REGULARISATION_UPDATED', 'ATTENDANCE_REGULARISATION_DELETED'];

    /** The shortest span a timeline bar shows, in minutes. */
    public const MIN_WINDOW_MINUTES = 600;

    /** How far before the first punch a timeline stretches to show the shift's start (a late arrival). */
    public const SHIFT_REACH_MINUTES = 240;

    /** How far after the last punch a timeline stretches to show the shift's end. */
    public const SHIFT_END_REACH_MINUTES = 120;

    public function __construct(
        private readonly LiveAttendanceService $live,
        private readonly AttendanceStatusResolver $status,
        private readonly MissedPunchTracker $missed,
        private readonly PunchTimeline $timeline,
        private readonly ShiftResolver $shifts,
    ) {}

    /**
     * The lines behind a page of attendance rows (All Attendance's log),
     * keyed "employeeId|Y-m-d". Only those days are worked out; their
     * punches, approved regularisations and overtime are read in one query
     * each and the rows themselves are used as given.
     *
     * @param  iterable<Attendance>  $attendances  with employee (user, department, shift) loaded
     * @return array<string, array<string, mixed>>
     */
    public function forAttendances(iterable $attendances): array
    {
        $attendances = collect($attendances)->filter(fn (Attendance $a) => $a->employee !== null)->values();
        if ($attendances->isEmpty()) {
            return [];
        }

        $dates = $attendances->map(fn (Attendance $a) => $a->date->toDateString())->unique()->values()->all();
        [$punches, , $regularised, $overtime] = $this->load(
            $attendances->pluck('employee_id')->unique()->values(),
            fn ($query, string $column) => $query->whereIn($column, $dates),
            withRows: false,
        );
        $start = Carbon::parse(min($dates));
        $end = Carbon::parse(max($dates));

        $lines = [];
        foreach ($attendances->groupBy('employee_id') as $rowsOfOne) {
            $employee = $rowsOfOne->first()->employee;
            $calculator = app(AttendanceCalculator::class, [
                'workingDays' => app(WorkingDayResolver::class)->primeApprovedLeaveRange($employee, $start, $end),
            ]);
            foreach ($rowsOfOne as $row) {
                $key = $row->employee_id.'|'.$row->date->toDateString();
                $lines[$key] = $this->line($employee, Carbon::parse($row->date->toDateString()), $calculator,
                    $punches->get($key, collect()), $row, isset($regularised[$key]), isset($overtime[$key]));
            }
        }

        return $lines;
    }

    /**
     * Everything about one person's date, for All Attendance's Day history:
     * the day as the log shows it, its sessions and breaks, every punch as
     * the system read it, the raw device log, and what changed it —
     * regularisations with every approval step (deleted ones too, they are
     * soft-deleted) and the system's own events on the day (auto check-out,
     * rebuilds, an undone correction). The caller has already checked that
     * the employee is in the viewer's reach.
     *
     * @return array{line: array<string, mixed>, sessions: list<array<string, mixed>>, raw: list<array<string, mixed>>, requests: list<array<string, mixed>>, events: list<array<string, mixed>>, corrections: array<string, array{from: string, to: string}>, window: array{from: int, to: int}}
     */
    public function history(Employee $employee, CarbonInterface $date): array
    {
        $day = Carbon::parse($date->toDateString());
        $iso = $day->toDateString();
        $line = $this->lines(collect([$employee]), $day, $day)->get($employee->id)[$iso];

        $row = Attendance::where('employee_id', $employee->id)->where('date', $iso)->first();
        $punches = AttendancePunch::where('employee_id', $employee->id)->where('punch_date', $iso)->orderBy('punched_at')->get();
        $processed = $punches->isEmpty()
            ? $this->timeline->emptyResult()
            : $this->timeline->process($punches, $day, null, $this->shifts->resolve($employee, $iso));

        $regularisations = AttendanceRegularisation::withTrashed()
            ->where('employee_id', $employee->id)->where('work_date', $iso)
            ->orderBy('created_at')->orderBy('id')->get();

        $events = [];
        foreach ($regularisations as $request) {
            $byHr = str_starts_with((string) $request->reason, '[HR');
            $events[] = [
                'at' => $request->created_at,
                'kind' => 'requested',
                'title' => $byHr ? 'Attendance marked by HR' : 'Regularisation requested',
                'detail' => $request->requestedWindowLabel().' · “'.Str::limit((string) $request->reason, 140).'”',
                'by' => $byHr ? null : $employee->user?->name,
            ];
            foreach ($request->approval_trail ?? [] as $step) {
                $action = (string) ($step['action'] ?? '');
                $events[] = [
                    'at' => isset($step['at']) ? Carbon::parse($step['at']) : $request->updated_at,
                    'kind' => $action,
                    'title' => match ($action) {
                        'approved' => 'Approved',
                        'fast_tracked' => 'Applied directly by HR',
                        'corrected' => 'Correction edited by HR',
                        'edited' => 'Request edited',
                        'rejected' => 'Rejected',
                        'deleted' => 'Request deleted',
                        default => Str::headline($action ?: 'updated'),
                    }.match ((string) ($step['stage'] ?? '')) {
                        'manager_review' => ' · manager stage',
                        'hr_review' => ' · HR stage',
                        'admin_approval' => ' · admin stage',
                        default => '',
                    },
                    'detail' => ($step['comment'] ?? null) ?: null,
                    'by' => $step['name'] ?? null,
                ];
            }
        }

        $audit = AuditLog::with('user:id,name')->whereNotNull('event')
            ->where(function ($query) use ($row, $regularisations) {
                $query->where(fn ($q) => $q->where('auditable_type', Attendance::class)->where('auditable_id', $row?->id ?? 0));
                if ($regularisations->isNotEmpty()) {
                    $query->orWhere(fn ($q) => $q->where('auditable_type', AttendanceRegularisation::class)
                        ->whereIn('auditable_id', $regularisations->pluck('id'))->whereNotIn('event', self::TRAIL_EVENTS));
                }
            })
            ->orderBy('created_at')->orderBy('id')->get();
        foreach ($audit as $entry) {
            $events[] = [
                'at' => $entry->created_at,
                'kind' => 'system',
                'title' => self::HISTORY_EVENTS[$entry->event] ?? Str::headline(Str::lower(Str::after((string) $entry->event, 'ATTENDANCE_'))),
                'detail' => $entry->reason ?: null,
                'by' => $entry->user?->name ?? 'System',
            ];
        }
        usort($events, fn (array $a, array $b) => ($a['at']?->getTimestamp() ?? 0) <=> ($b['at']?->getTimestamp() ?? 0));

        $corrections = [];
        foreach (['check_in' => 'Check-in', 'check_out' => 'Check-out'] as $field => $label) {
            $original = $row?->{'original_'.$field};
            $current = $row?->{$field};
            if ($original && $current && ! $original->equalTo($current)) {
                $corrections[$label] = ['from' => $original->toImmutable()->userTime(), 'to' => $current->toImmutable()->userTime()];
            }
        }

        return [
            'line' => $line,
            'sessions' => $processed['sessions'],
            'raw' => $processed['raw_events'],
            'requests' => $regularisations->map(fn (AttendanceRegularisation $r) => [
                'id' => $r->id,
                'status' => $r->trashed() ? 'deleted' : $r->status,
                'window' => $r->requestedWindowLabel(),
                'reason' => (string) $r->reason,
                'stage' => $r->trashed() ? 'Deleted' : $r->stageLabel(),
                'pending' => ! $r->trashed() && $r->status === 'pending',
            ])->all(),
            'events' => $events,
            'corrections' => $corrections,
            'window' => self::window([$line]),
        ];
    }

    /**
     * The hours a set of day lines spans, for their timeline bars (minutes
     * from midnight): from the earliest punch or missed punch to the latest,
     * stretched to show the shift start (up to SHIFT_REACH_MINUTES before
     * the first punch, so a late arrival is visible) and the shift end (up
     * to SHIFT_END_REACH_MINUTES after the last), then widened to whole hours and
     * to at least MIN_WINDOW_MINUTES. Capping the shift's reach keeps a
     * mistyped shift (an "8 PM" end saved as 08:00, read as overnight) from
     * stretching every bar across a day and a half. Days without punches
     * show their shift.
     *
     * @param  iterable<array<string, mixed>>  $lines
     * @return array{from: int, to: int}
     */
    public static function window(iterable $lines): array
    {
        $activity = [];
        $shifts = [];
        foreach ($lines as $line) {
            foreach ($line['segments'] ?? [] as $segment) {
                array_push($activity, $segment['from'], $segment['to']);
            }
            foreach ($line['marks'] ?? [] as $mark) {
                $activity[] = $mark['at'];
            }
            if (($line['shift_from'] ?? null) !== null) {
                $shifts[] = [$line['shift_from'], $line['shift_to']];
            }
        }

        if ($activity !== []) {
            $first = min($activity);
            $last = max($activity);
            foreach ($shifts as [$shiftFrom, $shiftTo]) {
                $first = min($first, max($shiftFrom, min($activity) - self::SHIFT_REACH_MINUTES));
                $last = max($last, min($shiftTo, max($activity) + self::SHIFT_END_REACH_MINUTES));
            }
        } elseif ($shifts !== []) {
            $first = min(array_column($shifts, 0));
            $last = min(max(array_column($shifts, 1)), $first + 16 * 60);
        }

        $from = isset($first) ? intdiv(max(0, $first), 60) * 60 : 9 * 60;
        $to = isset($last) ? (int) ceil($last / 60) * 60 : 18 * 60;
        while ($to - $from < self::MIN_WINDOW_MINUTES) {
            if ($from > 0) {
                $from -= 60;
            }
            if ($to - $from < self::MIN_WINDOW_MINUTES) {
                $to += 60;
            }
        }

        return ['from' => $from, 'to' => $to];
    }

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

        $range = [$start->toDateString(), $end->toDateString()];
        [$punches, $rows, $regularised, $overtime] = $this->load($employees->pluck('id'), fn ($query, string $column) => $query->whereBetween($column, $range));

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
     * The punches, attendance rows, approved regularisations and approved
     * overtime of these people on the dates $onDates picks — one query each
     * (the rows only when the caller does not already have them).
     *
     * @param  Collection<int, int>  $ids
     * @param  \Closure(mixed, string): mixed  $onDates  narrows a query on its date column
     * @return array{0: Collection<string, Collection<int, AttendancePunch>>, 1: Collection<string, Attendance>, 2: Collection<string, bool>, 3: Collection<string, bool>}
     */
    private function load(Collection $ids, \Closure $onDates, bool $withRows = true): array
    {
        $byDay = fn ($r) => [$r->employee_id.'|'.Carbon::parse($r->work_date)->toDateString() => true];

        return [
            $onDates(AttendancePunch::whereIn('employee_id', $ids), 'punch_date')->orderBy('punched_at')->get()
                ->groupBy(fn (AttendancePunch $p) => $p->employee_id.'|'.$p->punch_date->toDateString()),
            $withRows
                ? $onDates(Attendance::whereIn('employee_id', $ids), 'date')->with('activeBreak')->get()
                    ->keyBy(fn (Attendance $a) => $a->employee_id.'|'.$a->date->toDateString())
                : collect(),
            $onDates(AttendanceRegularisation::whereIn('employee_id', $ids)->where('status', 'approved'), 'work_date')
                ->get(['employee_id', 'work_date'])->mapWithKeys($byDay),
            $onDates(OtRequest::whereIn('employee_id', $ids)->where('status', 'approved'), 'work_date')
                ->get(['employee_id', 'work_date'])->mapWithKeys($byDay),
        ];
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
        $live = in_array($status['state'], [AttendanceStatusResolver::WORKING, AttendanceStatusResolver::ON_BREAK], true);
        $midnightMs = (int) Carbon::parse($date->toDateString())->getTimestampMs();
        $minute = fn (int $ms): int => (int) floor(($ms - $midnightMs) / 60000);
        $shiftFrom = $day->shift ? $minute((int) $day->shift->start->getTimestampMs()) : null;
        $shiftTo = $day->shift ? $minute((int) $day->shift->end->getTimestampMs()) : null;
        $segments = $this->segments($trail['rows'], $day, $minute, $live, $shiftTo);

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
            'first_in' => $day->firstIn ? app(DateDisplay::class)->time($day->firstIn) : null,
            'first_in_ms' => $day->firstIn ? (int) Carbon::parse($day->firstIn)->getTimestampMs() : null,
            'last_out' => $day->lastOut ? app(DateDisplay::class)->time($day->lastOut) : null,
            'worked_minutes' => $day->workedMinutes,
            'expected_minutes' => $day->expectedMinutes,
            'progress' => LiveAttendanceService::progressTone($day->workedMinutes, $day->expectedMinutes),
            'break_minutes' => $day->breakMinutes,
            'excess_break' => $day->excessBreak,
            'missing_checkout' => $day->missingCheckout,
            'auto_checkout' => $auto,
            'regularised' => $day->regularised || $regularised,
            'shift' => $day->shift ? $day->shift->name.' · '.$day->shift->start->format('g:i A').' – '.$day->shift->end->format('g:i A') : 'No shift',
            'shift_name' => $day->shift?->name,
            'shift_window' => $day->shift ? app(DateDisplay::class)->time($day->shift->start).' – '.app(DateDisplay::class)->time($day->shift->end) : null,
            'shift_start' => $day->shift ? app(DateDisplay::class)->time($day->shift->start) : null,
            'grace' => $day->shift?->graceMinutes ?? 0,
            'mode' => $row?->work_mode,
            'trail' => $trail['rows'],
            'trail_counts' => $trail['counts'],
            'missed_count' => $missed,
            'extra_taps' => ($trail['counts']['duplicate'] ?? 0) + ($trail['counts']['ignored'] ?? 0),
            'live' => $live,
            'ot_minutes' => $day->approvedOtMinutes,
            // The timeline bar, in minutes from the date's midnight.
            'shift_from' => $shiftFrom,
            'shift_to' => $shiftTo,
            'segments' => $segments,
            // Each missed punch midway between the punches it was missed
            // between (a missed check-out: at the last IN).
            'marks' => collect($trail['rows'])
                ->filter(fn (array $n) => in_array($n['state'], ['missed', 'missed_corrected'], true) && $n['ts'] > $midnightMs)
                ->map(function (array $n) use ($trail, $minute) {
                    $next = collect($trail['rows'])->first(fn (array $o) => in_array($o['state'], ['counted', 'corrected', 'ignored'], true) && $o['ts'] > $n['ts']);
                    $at = $next !== null && $n['title'] !== 'Missed check-out' ? intdiv($minute($n['ts']) + $minute($next['ts']), 2) : $minute($n['ts']);

                    return ['at' => $at, 'title' => $n['title'], 'resolved' => $n['state'] === 'missed_corrected'];
                })
                ->values()->all(),
            'ticks' => collect($trail['rows'])
                ->filter(fn (array $n) => in_array($n['state'], ['counted', 'corrected'], true) && $n['ts'] > $midnightMs)
                ->map(fn (array $n) => ['at' => $minute($n['ts']), 'dir' => str_ends_with($n['title'], ' OUT') ? 'OUT' : 'IN', 'time' => $n['time'], 'title' => $n['title']])
                ->values()->all(),
            'punch_count' => ($trail['counts']['counted'] ?? 0) + ($trail['counts']['corrected'] ?? 0),
            'break_count' => max(0, count(array_filter($segments, fn (array $s) => $s['kind'] !== 'open')) - 1),
        ];
    }

    /**
     * The worked stretches of a day for its timeline bar: each counted IN to
     * the next counted OUT (an automatic punch-out marks its stretch "auto").
     * A day still open runs to now while live, or as "open" once the
     * check-out is missing — to the shift end, but never longer than one
     * shift's hours (a mistyped overnight shift would otherwise run it
     * through the night). A day clocked in on the web, with no
     * device punches, is its first in to its last out.
     *
     * @param  list<array<string, mixed>>  $trail
     * @param  \Closure(int): int  $minute  epoch ms to minutes from the date's midnight
     * @return list<array{from: int, to: int, kind: string}>
     */
    private function segments(array $trail, AttendanceDay $day, \Closure $minute, bool $live, ?int $shiftTo): array
    {
        $segments = [];
        $open = null;
        foreach ($trail as $node) {
            if (! in_array($node['state'], ['counted', 'corrected'], true)) {
                continue;
            }
            if (str_ends_with($node['title'], ' IN') && $open === null) {
                $open = $minute($node['ts']);
            } elseif (str_ends_with($node['title'], ' OUT') && $open !== null) {
                $segments[] = ['from' => $open, 'to' => max($open, $minute($node['ts'])), 'kind' => $node['source'] === 'system_auto' ? 'auto' : 'work'];
                $open = null;
            }
        }

        if ($segments === [] && $open === null && $day->firstIn !== null) {
            $open = $minute((int) Carbon::parse($day->firstIn)->getTimestampMs());
            if ($day->lastOut !== null) {
                $segments[] = ['from' => $open, 'to' => max($open, $minute((int) Carbon::parse($day->lastOut)->getTimestampMs())),
                    'kind' => $day->source === AttendanceCalculator::SOURCE_AUTO_CHECKOUT ? 'auto' : 'work'];
                $open = null;
            }
        }

        if ($open !== null) {
            $segments[] = $live
                ? ['from' => $open, 'to' => max($open, $minute((int) Carbon::now()->getTimestampMs())), 'kind' => 'live']
                : ['from' => $open, 'to' => max($open + 30, min($shiftTo ?? 0, $open + $day->expectedMinutes)), 'kind' => 'open'];
        }

        return $segments;
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
