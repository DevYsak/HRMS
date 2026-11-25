<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Security\ScopeResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Today's live attendance for the employees a viewer may see — the read model
 * behind the Live Attendance board.
 *
 * WHO comes from the view_live_attendance scope (ScopeResolver: own / team /
 * department / selected departments / company); filters only narrow inside
 * it, so a department outside the scope returns nobody. WHAT comes from the
 * canonical services, never recalculated here: each person's state, first
 * in, last out, worked time, lateness (assigned shift + grace), auto
 * checkout and regularisation from AttendanceStatusResolver /
 * AttendanceCalculator; the punches from the PunchTimeline engine's kept
 * punches (duplicates merged, Face = IN, ID Card = OUT whatever the device
 * tagged).
 *
 * The board puts everyone in one of three sections:
 *   - working — punched in now; the session runs from their latest IN;
 *   - out     — stepped out before the shift ends, on a break, or an IN
 *               left open past the missing-checkout point;
 *   - away    — not in yet, absent, on leave, a day off, or checked out.
 *
 * Today only, and bounded: one query each for the employees, today's
 * attendance rows and today's punches — nothing from earlier days.
 */
class LiveAttendanceService
{
    public const PERMISSION = 'view_live_attendance';

    /** Out this long (or a check-out missing) is "out too long". */
    public const OUT_TOO_LONG_MINUTES = 30;

    /** The board's status filters. */
    public const STATUSES = [
        'out_too_long' => 'Out too long',
        'working' => 'Working',
        'out' => 'Out now',
        'not_started' => 'Not in yet',
        'late' => 'Late',
        'missing' => 'Missing checkout',
        'checked_out' => 'Checked out',
        'on_leave' => 'On leave',
    ];

    /** Worked today against the shift's hours — the bar's colour. */
    public const PROGRESS = [
        'on_track' => 'On track',
        'nearing' => 'Nearing shift hours',
        'done' => 'Shift hours done',
        'beyond' => 'Beyond shift',
        'way_beyond' => 'Way beyond',
    ];

    public const SORTS = ['attention' => 'Attention', 'name' => 'Name', 'worked' => 'Worked today', 'arrival' => 'Arrival'];

    public const GROUPS = ['department' => 'By department', 'shift' => 'By shift', 'none' => 'No grouping'];

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly AttendanceStatusResolver $status,
        private readonly PunchTimeline $timeline,
    ) {}

    /**
     * @param  array{department?: int|string|null, shift?: int|string|null, status?: string|null, search?: string|null, sort?: string|null, group?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function snapshot(User $viewer, array $filters = [], int $activityLimit = 20, ?CarbonInterface $now = null): array
    {
        $now = $now ? Carbon::parse($now) : Carbon::now();
        $today = $now->copy()->startOfDay();

        $visible = $this->visibleEmployees($viewer);
        $departments = Department::whereIn('id', $visible->pluck('department_id')->filter()->unique())->orderBy('name')->get(['id', 'name']);
        $shifts = ShiftSetting::whereIn('id', $visible->pluck('shift_id')->filter()->unique())->orderBy('name')->get(['id', 'name']);

        // Shift and search narrow everything. The department chips count every
        // department, so the department filter applies after the counts.
        $searched = $this->narrow($visible, ['department' => null] + $filters);
        $ids = $searched->pluck('id');
        $attendance = $ids->isEmpty() ? collect() : Attendance::whereIn('employee_id', $ids)
            ->where('date', $today->toDateString())->with('activeBreak')->get()->keyBy('employee_id');
        $statuses = $ids->isEmpty() ? [] : $this->status->currentForMany($searched, $attendance, $now);
        $nodes = $this->keptNodes($ids, $statuses, $today);

        $all = $searched->map(fn (Employee $e) => $this->row($e, $statuses[$e->id], $nodes[$e->id] ?? [], $attendance->get($e->id), $now))->values();

        $department = (int) ($filters['department'] ?? 0);
        $rows = $department > 0 ? $all->where('department_id', $department)->values() : $all;

        $summary = $this->summary($rows);
        $attention = [
            'late' => $summary['late'],
            'missing' => $summary['missing'],
            'not_in' => $summary['not_in'],
            'excess_break' => $rows->where('excess_break', true)->count(),
        ];
        $pills = ['all' => $rows->count()] + collect(array_keys(self::STATUSES))
            ->mapWithKeys(fn (string $key) => [$key => $rows->filter(fn (array $r) => $this->matchesStatus($r, $key))->count()])->all();

        if (! empty($filters['status'])) {
            $rows = $rows->filter(fn (array $r) => $this->matchesStatus($r, $filters['status']))->values();
        }

        $activity = $this->activity($rows->pluck('employee')->keyBy('id'), $statuses, $nodes);

        return [
            'summary' => $summary,
            'attention' => $attention,
            'pills' => $pills,
            'rows' => $rows->sortBy(fn (array $r) => [$r['order'], $r['name']])->values()->all(),
            'sections' => $this->sections($rows, (string) ($filters['sort'] ?? 'attention'), (string) ($filters['group'] ?? 'department')),
            'everyone' => ['working' => $all->where('bucket', 'working')->count(), 'total' => $all->count()],
            'department_counts' => $departments->map(fn (Department $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'working' => $all->where('department_id', $d->id)->where('bucket', 'working')->count(),
                'total' => $all->where('department_id', $d->id)->count(),
            ])->filter(fn (array $d) => $d['total'] > 0)->values()->all(),
            'activity' => array_slice($activity, 0, $activityLimit),
            'activity_total' => count($activity),
            'latest_at' => $activity !== [] ? $activity[0]['at'] : null,
            'departments' => $departments,
            'shifts' => $shifts,
            'scope_label' => $this->scopes->scopeFor($viewer, self::PERMISSION)->label(),
            'now_ms' => (int) $now->getTimestampMs(),
        ];
    }

    /** Active employees inside the viewer's view_live_attendance scope. @return Collection<int, Employee> */
    public function visibleEmployees(User $viewer): Collection
    {
        $ids = $this->scopes->employeeIds($viewer, self::PERMISSION);

        if ($ids === []) {
            return collect();
        }

        return Employee::query()
            // office + exitRecord: the working-day and holiday resolvers read them per person.
            ->with(['user:id,name', 'department:id,name', 'shift', 'office', 'exitRecord'])
            ->whereNotIn('status', ['inactive', 'archived', 'draft'])
            ->whereHas('user')
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->get();
    }

    /**
     * Department / shift / search — always inside the visible set, so a
     * department the viewer cannot see simply matches nobody.
     *
     * @param  Collection<int, Employee>  $visible
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Employee>
     */
    public function narrow(Collection $visible, array $filters): Collection
    {
        $department = (int) ($filters['department'] ?? 0);
        $shift = (int) ($filters['shift'] ?? 0);
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));

        return $visible
            ->when($department > 0, fn (Collection $c) => $c->where('department_id', $department))
            ->when($shift > 0, fn (Collection $c) => $c->where('shift_id', $shift))
            ->when($search !== '', fn (Collection $c) => $c->filter(fn (Employee $e) => str_contains(mb_strtolower((string) $e->user?->name), $search)
                || str_contains(mb_strtolower((string) $e->employee_id), $search)))
            ->values();
    }

    /** Two-letter initials for an avatar. */
    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : mb_substr($parts[0] ?? '', 1, 1);

        return mb_strtoupper($first.$last) ?: '?';
    }

    /** The bar's colour for worked minutes against the shift's minutes. */
    public static function progressTone(int $worked, int $expected): string
    {
        $pct = $expected > 0 ? $worked * 100 / $expected : 0;

        return match (true) {
            $pct >= 130 => 'way_beyond',
            $pct >= 110 => 'beyond',
            $pct >= 100 => 'done',
            $pct >= 85 => 'nearing',
            default => 'on_track',
        };
    }

    /**
     * Today's kept punches per employee (the PunchTimeline engine — duplicates
     * merged, stray scans dropped), oldest first. One query for everyone.
     *
     * @param  Collection<int, int>  $ids
     * @param  array<int, array<string, mixed>>  $statuses
     * @return array<int, list<array<string, mixed>>>
     */
    private function keptNodes(Collection $ids, array $statuses, Carbon $today): array
    {
        if ($ids->isEmpty()) {
            return [];
        }

        $punches = AttendancePunch::whereIn('employee_id', $ids)
            ->where('punch_date', $today->toDateString())
            ->orderBy('punched_at')
            ->get()
            ->groupBy('employee_id');

        $nodes = [];
        foreach ($punches as $employeeId => $dayPunches) {
            $day = $statuses[$employeeId]['day'] ?? null;
            if ($day === null) {
                continue;
            }

            $nodes[$employeeId] = array_values(array_filter(
                $this->timeline->process($dayPunches, $today, null, $day->shift)['nodes'],
                fn (array $n) => $n['type'] !== 'missing' && $n['ts_ms'] !== null,
            ));
        }

        return $nodes;
    }

    /**
     * One employee's line for today, from the resolver's AttendanceDay.
     *
     * @param  array<string, mixed>  $status
     * @param  list<array<string, mixed>>  $nodes  today's kept punches
     * @return array<string, mixed>
     */
    private function row(Employee $employee, array $status, array $nodes, ?Attendance $attendance, Carbon $now): array
    {
        /** @var AttendanceDay $day */
        $day = $status['day'];
        $state = $status['state'];
        $auto = $day->source === AttendanceCalculator::SOURCE_AUTO_CHECKOUT;
        $late = $day->isLate && $day->firstIn !== null;
        $shiftEnd = $day->shift?->end;
        // A last OUT before the shift ends is a step out: they may come back.
        $steppedOut = $state === AttendanceStatusResolver::COMPLETED && ! $auto && $day->date->isSameDay($now)
            && $shiftEnd !== null && $now->lessThan($shiftEnd);

        [$current, $tone, $order] = match (true) {
            $state === AttendanceStatusResolver::MISSING_CHECKOUT => ['Missing Checkout', 'red', 1],
            $auto => ['Auto Checkout', 'amber', 4],
            $state === AttendanceStatusResolver::ON_BREAK => ['On Break', 'blue', 2],
            $state === AttendanceStatusResolver::WORKING => [$late ? 'Late' : 'Working', $late ? 'amber' : 'green', 2],
            $steppedOut => ['Out', 'red', 2],
            $state === AttendanceStatusResolver::COMPLETED => ['Checked Out', 'zinc', 4],
            default => ['Not In', 'zinc', in_array($status['reason'], ['absent', 'not_started'], true) ? 3 : 5],
        };

        $bucket = match (true) {
            $state === AttendanceStatusResolver::WORKING => 'working',
            $state === AttendanceStatusResolver::ON_BREAK, $state === AttendanceStatusResolver::MISSING_CHECKOUT, $steppedOut => 'out',
            default => 'away',
        };

        $lastIn = collect($nodes)->last(fn (array $n) => $n['dir'] === 'IN');
        $lastNode = $nodes !== [] ? $nodes[count($nodes) - 1] : null;
        $nowMs = (int) $now->getTimestampMs();
        $firstInMs = $day->firstIn ? (int) Carbon::parse($day->firstIn)->getTimestampMs() : null;

        $sessionSince = $bucket === 'working' ? ($lastIn['ts_ms'] ?? $firstInMs) : null;
        $outSince = match (true) {
            $state === AttendanceStatusResolver::ON_BREAK => $attendance?->activeBreak?->break_start
                ? (int) Carbon::parse($attendance->activeBreak->break_start)->getTimestampMs()
                : ($lastNode['ts_ms'] ?? null),
            $steppedOut => $day->lastOut ? (int) Carbon::parse($day->lastOut)->getTimestampMs() : null,
            default => null,
        };
        $outMinutes = $outSince !== null ? max(0, intdiv($nowMs - $outSince, 60000)) : 0;
        $outTooLong = $bucket === 'out' && ($state === AttendanceStatusResolver::MISSING_CHECKOUT || $outMinutes >= self::OUT_TOO_LONG_MINUTES);

        $exceptions = array_values(array_filter([
            $late ? 'Late '.$day->lateMinutes.'m' : null,
            $day->excessBreak ? 'Excess break' : null,
            $day->regularised ? 'Regularised' : null,
            $auto ? 'Auto Checkout' : null,
            $state === AttendanceStatusResolver::NOT_IN ? match ($status['reason']) {
                'leave' => 'On leave', 'weekly_off' => 'Weekly off', 'holiday' => 'Holiday', 'mdl' => 'MDL shutdown',
                'absent' => 'Absent', 'not_employed' => 'Not employed', default => null,
            } : null,
        ]));

        $name = $employee->user?->name ?? '—';
        $expected = $day->expectedMinutes;

        return [
            'employee' => $employee,
            'id' => $employee->id,
            'name' => $name,
            'initials' => self::initials($name),
            'code' => (string) $employee->employee_id,
            'department' => $employee->department?->name ?? '—',
            'department_id' => $employee->department_id,
            'shift' => $day->shift ? $day->shift->name.' · '.$day->shift->start->format('g:i A').' – '.$day->shift->end->format('g:i A') : 'Not assigned',
            'shift_name' => $day->shift?->name ?? 'No shift',
            'shift_end' => $shiftEnd?->format('h:i A'),
            'first_in' => $day->firstIn?->format('h:i A'),
            'first_in_ms' => $firstInMs,
            'last_out' => $day->lastOut?->format('h:i A'),
            'worked_minutes' => $day->workedMinutes,
            'expected_minutes' => $expected,
            'progress_pct' => $expected > 0 ? (int) floor($day->workedMinutes * 100 / $expected) : 0,
            'progress' => self::progressTone($day->workedMinutes, $expected),
            'state' => $state,
            'reason' => $status['reason'],
            'bucket' => $bucket,
            'current' => $current,
            'tone' => $tone,
            'order' => $order,
            'late' => $late,
            'late_minutes' => $late ? $day->lateMinutes : 0,
            'excess_break' => $day->excessBreak,
            'auto_checkout' => $auto,
            'regularised' => $day->regularised,
            'mode' => $attendance?->work_mode,
            'session_since_ms' => $sessionSince,
            'out_since_ms' => $outSince,
            'out_minutes' => $outMinutes,
            'out_too_long' => $outTooLong,
            'last_punch' => $lastNode ? ['dir' => $lastNode['dir'], 'method' => $lastNode['method_label'] ?? null,
                'time' => Carbon::createFromTimestampMs($lastNode['ts_ms'], config('app.timezone'))->format('h:i A')] : null,
            'note' => $this->note($bucket, $state, (string) $status['reason'], $day, $steppedOut, $auto, $outMinutes, $outSince, $lastIn, $lastNode),
            'exceptions' => $exceptions,
            'day' => $day,
        ];
    }

    /**
     * What the person is doing, in words, for the board's "On" column.
     *
     * @param  array<string, mixed>|null  $lastIn
     * @param  array<string, mixed>|null  $lastNode
     */
    private function note(string $bucket, string $state, string $reason, AttendanceDay $day, bool $steppedOut, bool $auto, int $outMinutes, ?int $outSince, ?array $lastIn, ?array $lastNode): string
    {
        $clock = fn (?int $ms): string => $ms ? Carbon::createFromTimestampMs($ms, config('app.timezone'))->format('h:i A') : '—';
        $for = fn (int $m): string => $m >= 60 ? intdiv($m, 60).'h '.($m % 60).'m' : $m.'m';
        $method = fn (?array $n): string => $n && ! empty($n['method_label']) ? ' · '.$n['method_label'] : '';

        return match (true) {
            $bucket === 'working' => 'In since '.$clock($lastIn['ts_ms'] ?? ($day->firstIn ? (int) Carbon::parse($day->firstIn)->getTimestampMs() : null)).$method($lastIn)
                .($lastIn !== null && $day->firstIn && ($lastIn['ts_ms'] ?? 0) > Carbon::parse($day->firstIn)->getTimestampMs() ? ' · first in '.$day->firstIn->format('h:i A') : ''),
            $state === AttendanceStatusResolver::MISSING_CHECKOUT => 'No check-out — in since '.($day->firstIn?->format('h:i A') ?? '—')
                .($day->shift ? '; shift ended '.$day->shift->end->format('h:i A') : ''),
            $state === AttendanceStatusResolver::ON_BREAK => 'On a break for '.$for($outMinutes).' · since '.$clock($outSince),
            $steppedOut => 'Out for '.$for($outMinutes).' · since '.$clock($outSince).($lastNode ? ' · last punch '.trim(($lastNode['method_label'] ?? '').' OUT') : ''),
            $auto => 'Auto check-out at '.($day->lastOut?->format('h:i A') ?? '—').' — no OUT punch',
            $state === AttendanceStatusResolver::COMPLETED => 'Checked out at '.($day->lastOut?->format('h:i A') ?? '—').' · in '.($day->firstIn?->format('h:i A') ?? '—'),
            default => match ($reason) {
                'leave' => 'On approved leave today',
                'weekly_off' => 'Weekly off',
                'holiday' => 'Holiday',
                'mdl' => 'MDL shutdown day',
                'absent' => 'Not clocked in — the shift is over',
                'not_employed' => 'Not employed today',
                default => 'Not clocked in yet'.($day->shift ? ' · shift starts '.$day->shift->start->format('h:i A') : ''),
            },
        };
    }

    /**
     * The board's three sections, sorted and (optionally) grouped. Each row
     * carries its group label so the view can print a heading where it changes.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{working: list<array<string, mixed>>, out: list<array<string, mixed>>, away: list<array<string, mixed>>}
     */
    private function sections(Collection $rows, string $sort, string $group): array
    {
        $groupOf = fn (array $r): string => match ($group) {
            'shift' => $r['shift_name'],
            'none' => '',
            default => $r['department'],
        };
        $awayRank = fn (array $r): int => match (true) {
            in_array($r['reason'], ['not_started', 'absent'], true) => 0,
            $r['reason'] === 'leave' => 1,
            $r['state'] === AttendanceStatusResolver::COMPLETED => 2,
            default => 3,
        };
        $key = fn (array $r): array => match ($sort) {
            'name' => [mb_strtolower($r['name'])],
            'worked' => [-$r['worked_minutes'], mb_strtolower($r['name'])],
            'arrival' => [$r['first_in_ms'] ?? PHP_INT_MAX, mb_strtolower($r['name'])],
            // Attention: late first, then the furthest through the day; the
            // longest out (a missing check-out first); not-in before leave.
            default => match ($r['bucket']) {
                'working' => [$r['late'] ? 0 : 1, -$r['progress_pct'], mb_strtolower($r['name'])],
                'out' => [$r['state'] === AttendanceStatusResolver::MISSING_CHECKOUT ? 0 : 1, -$r['out_minutes'], mb_strtolower($r['name'])],
                default => [$awayRank($r), mb_strtolower($r['name'])],
            },
        };

        $out = ['working' => [], 'out' => [], 'away' => []];
        foreach (array_keys($out) as $bucket) {
            $out[$bucket] = $rows->where('bucket', $bucket)
                ->map(fn (array $r) => $r + ['group' => $groupOf($r)])
                ->sortBy(fn (array $r) => [mb_strtolower($r['group']), ...$key($r)])
                ->values()->all();
        }

        return $out;
    }

    /** @param Collection<int, array<string, mixed>> $rows @return array<string, int> */
    private function summary(Collection $rows): array
    {
        return [
            'present' => $rows->whereIn('state', [AttendanceStatusResolver::WORKING, AttendanceStatusResolver::ON_BREAK])->count(),
            'late' => $rows->where('late', true)->count(),
            'not_in' => $rows->filter(fn (array $r) => $r['state'] === AttendanceStatusResolver::NOT_IN && in_array($r['reason'], ['absent', 'not_started'], true))->count(),
            'checked_out' => $rows->where('state', AttendanceStatusResolver::COMPLETED)->count(),
            'missing' => $rows->where('state', AttendanceStatusResolver::MISSING_CHECKOUT)->count(),
            'on_break' => $rows->where('state', AttendanceStatusResolver::ON_BREAK)->count(),
        ];
    }

    /** @param array<string, mixed> $row */
    private function matchesStatus(array $row, string $status): bool
    {
        return match ($status) {
            'out_too_long' => $row['out_too_long'],
            'working' => $row['state'] === AttendanceStatusResolver::WORKING,
            'out' => $row['bucket'] === 'out',
            'not_started', 'not_in' => $row['state'] === AttendanceStatusResolver::NOT_IN && in_array($row['reason'], ['absent', 'not_started'], true),
            'late' => $row['late'],
            'missing' => $row['state'] === AttendanceStatusResolver::MISSING_CHECKOUT,
            'checked_out' => $row['state'] === AttendanceStatusResolver::COMPLETED && $row['bucket'] === 'away',
            'on_leave' => $row['reason'] === 'leave',
            // Earlier filter names, still accepted from saved links.
            'present' => in_array($row['state'], [AttendanceStatusResolver::WORKING, AttendanceStatusResolver::ON_BREAK], true),
            'on_break' => $row['state'] === AttendanceStatusResolver::ON_BREAK,
            'excess_break' => $row['excess_break'],
            default => true,
        };
    }

    /**
     * Today's activity, newest first: every kept punch of the PunchTimeline
     * engine (one entry per real action — duplicates and stray scans are
     * merged by the engine) named by what it was, plus the system auto
     * checkout.
     *
     * @param  Collection<int, Employee>  $employees  keyed by id
     * @param  array<int, array<string, mixed>>  $statuses
     * @param  array<int, list<array<string, mixed>>>  $keptNodes
     * @return array<int, array<string, mixed>>
     */
    private function activity(Collection $employees, array $statuses, array $keptNodes): array
    {
        if ($employees->isEmpty()) {
            return [];
        }

        $events = [];
        foreach ($keptNodes as $employeeId => $nodes) {
            $employee = $employees->get($employeeId);
            $day = $statuses[$employeeId]['day'] ?? null;
            if ($employee === null || $day === null) {
                continue;
            }

            foreach ($nodes as $i => $node) {
                $laterIn = collect(array_slice($nodes, $i + 1))->contains(fn (array $n) => $n['dir'] === 'IN');
                $afterBreak = $i > 0 && $nodes[$i - 1]['dir'] === 'OUT';
                $isReg = ($node['source'] ?? '') === 'regularisation';

                [$event, $status] = match (true) {
                    $isReg => ['REGULARISED', 'Regularised '.$node['dir']],
                    $node['dir'] === 'IN' && $i === 0 => ['IN', $day->isLate ? 'Late' : 'On Time'],
                    $node['dir'] === 'IN' && $afterBreak => ['IN', 'Back from break'],
                    $node['dir'] === 'IN' => ['IN', 'Working'],
                    $laterIn => ['BREAK', 'On Break'],
                    default => ['OUT', 'Checked Out'],
                };

                $events[] = $this->event($employee, $node['ts_ms'], $event, $status, $node['method_label'] ?? match ($node['source'] ?? '') {
                    'web' => 'Web', 'regularisation' => 'Regularisation', default => '—',
                });
            }
        }

        // The system closed the day at the shift end — its own entry, never a punch.
        foreach ($statuses as $employeeId => $status) {
            $day = $status['day'];
            if ($day->source === AttendanceCalculator::SOURCE_AUTO_CHECKOUT && $day->lastOut && $employees->has($employeeId)) {
                $events[] = $this->event($employees->get($employeeId), (int) Carbon::parse($day->lastOut)->getTimestampMs(), 'AUTO CHECKOUT', 'Auto Checkout', 'System');
            }
        }

        usort($events, fn (array $a, array $b) => [$b['ts'], $b['employee_id']] <=> [$a['ts'], $a['employee_id']]);

        return $events;
    }

    /** @return array<string, mixed> */
    private function event(Employee $employee, int $tsMs, string $event, string $status, string $source): array
    {
        $at = Carbon::createFromTimestampMs($tsMs)->setTimezone(config('app.timezone'));

        return [
            'key' => $employee->id.'-'.$tsMs.'-'.$event,
            'ts' => $tsMs,
            'at' => $at,
            'time' => $at->format('h:i:s A'),
            'employee_id' => $employee->id,
            'employee' => $employee->user?->name ?? '—',
            'department' => $employee->department?->name ?? '—',
            'event' => $event,
            'status' => $status,
            'source' => $source,
        ];
    }
}
