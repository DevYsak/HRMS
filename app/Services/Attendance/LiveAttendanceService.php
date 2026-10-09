<?php

namespace App\Services\Attendance;

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
 * behind the Live Attendance panel.
 *
 * WHO comes from the view_live_attendance scope (ScopeResolver: own / team /
 * department / selected departments / company); filters only narrow inside
 * it, so a department outside the scope returns nobody. WHAT comes from the
 * canonical services, never recalculated here: each person's state, first
 * in, last out, worked time, lateness (assigned shift + grace), auto
 * checkout and regularisation from AttendanceStatusResolver /
 * AttendanceCalculator; the activity feed from the PunchTimeline engine's
 * kept punches (duplicates merged, Face = IN, ID Card = OUT whatever the
 * device tagged).
 *
 * Today only, and bounded: one query each for the employees, today's
 * attendance rows (inside the resolver) and today's punches — nothing from
 * earlier days.
 */
class LiveAttendanceService
{
    public const PERMISSION = 'view_live_attendance';

    /** Status filter values. */
    public const STATUSES = [
        'present' => 'Present now',
        'late' => 'Late today',
        'not_in' => 'Not checked in',
        'checked_out' => 'Checked out',
        'missing' => 'Missing checkout',
        'on_break' => 'On break',
        'excess_break' => 'Excess break',
    ];

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly AttendanceStatusResolver $status,
        private readonly PunchTimeline $timeline,
    ) {}

    /**
     * @param  array{department?: int|string|null, shift?: int|string|null, status?: string|null, search?: string|null}  $filters
     * @return array{summary: array<string, int>, attention: array<string, int>, rows: array<int, array<string, mixed>>, activity: array<int, array<string, mixed>>, activity_total: int, latest_at: ?Carbon, departments: Collection<int, Department>, shifts: Collection<int, ShiftSetting>, scope_label: string}
     */
    public function snapshot(User $viewer, array $filters = [], int $activityLimit = 20, ?CarbonInterface $now = null): array
    {
        $now = $now ? Carbon::parse($now) : Carbon::now();
        $today = $now->copy()->startOfDay();

        $visible = $this->visibleEmployees($viewer);
        $departments = Department::whereIn('id', $visible->pluck('department_id')->filter()->unique())->orderBy('name')->get(['id', 'name']);
        $shifts = ShiftSetting::whereIn('id', $visible->pluck('shift_id')->filter()->unique())->orderBy('name')->get(['id', 'name']);

        $employees = $this->narrow($visible, $filters);
        $statuses = $employees->isEmpty() ? [] : $this->status->currentForMany($employees, now: $now);

        $rows = $employees->map(fn (Employee $e) => $this->row($e, $statuses[$e->id]))->values();
        $summary = $this->summary($rows);
        $attention = [
            'late' => $summary['late'],
            'missing' => $summary['missing'],
            'not_in' => $summary['not_in'],
            'excess_break' => $rows->where('excess_break', true)->count(),
        ];

        if (! empty($filters['status'])) {
            $rows = $rows->filter(fn (array $r) => $this->matchesStatus($r, $filters['status']))->values();
        }

        $activity = $this->activity($rows->pluck('employee')->keyBy('id'), $statuses, $today);

        return [
            'summary' => $summary,
            'attention' => $attention,
            'rows' => $rows->sortBy(fn (array $r) => [$r['order'], $r['name']])->values()->all(),
            'activity' => array_slice($activity, 0, $activityLimit),
            'activity_total' => count($activity),
            'latest_at' => $activity !== [] ? $activity[0]['at'] : null,
            'departments' => $departments,
            'shifts' => $shifts,
            'scope_label' => $this->scopes->scopeFor($viewer, self::PERMISSION)->label(),
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
    private function narrow(Collection $visible, array $filters): Collection
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

    /**
     * One employee's line for today, from the resolver's AttendanceDay.
     *
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private function row(Employee $employee, array $status): array
    {
        /** @var AttendanceDay $day */
        $day = $status['day'];
        $auto = $day->source === AttendanceCalculator::SOURCE_AUTO_CHECKOUT;
        $late = $day->isLate && $day->firstIn !== null;

        [$current, $tone, $order] = match (true) {
            $status['state'] === AttendanceStatusResolver::MISSING_CHECKOUT => ['Missing Checkout', 'red', 1],
            $auto => ['Auto Checkout', 'amber', 4],
            $status['state'] === AttendanceStatusResolver::ON_BREAK => ['On Break', 'blue', 2],
            $status['state'] === AttendanceStatusResolver::WORKING => [$late ? 'Late' : 'Working', $late ? 'amber' : 'green', 2],
            $status['state'] === AttendanceStatusResolver::COMPLETED => ['Checked Out', 'zinc', 4],
            default => ['Not In', 'zinc', in_array($status['reason'], ['absent', 'not_started'], true) ? 3 : 5],
        };

        $exceptions = array_values(array_filter([
            $late ? 'Late '.$day->lateMinutes.'m' : null,
            $day->excessBreak ? 'Excess break' : null,
            $day->regularised ? 'Regularised' : null,
            $auto ? 'Auto Checkout' : null,
            $status['state'] === AttendanceStatusResolver::NOT_IN ? match ($status['reason']) {
                'leave' => 'On leave', 'weekly_off' => 'Weekly off', 'holiday' => 'Holiday', 'mdl' => 'MDL shutdown',
                'absent' => 'Absent', 'not_employed' => 'Not employed', default => null,
            } : null,
        ]));

        return [
            'employee' => $employee,
            'id' => $employee->id,
            'name' => $employee->user?->name ?? '—',
            'department' => $employee->department?->name ?? '—',
            'department_id' => $employee->department_id,
            'shift' => $day->shift ? $day->shift->name.' · '.$day->shift->start->format('g:i A').' – '.$day->shift->end->format('g:i A') : 'Not assigned',
            'first_in' => $day->firstIn?->format('h:i A'),
            'last_out' => $day->lastOut?->format('h:i A'),
            'worked_minutes' => $day->workedMinutes,
            'state' => $status['state'],
            'reason' => $status['reason'],
            'current' => $current,
            'tone' => $tone,
            'order' => $order,
            'late' => $late,
            'excess_break' => $day->excessBreak,
            'auto_checkout' => $auto,
            'regularised' => $day->regularised,
            'exceptions' => $exceptions,
            'day' => $day,
        ];
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
            'present' => in_array($row['state'], [AttendanceStatusResolver::WORKING, AttendanceStatusResolver::ON_BREAK], true),
            'late' => $row['late'],
            'not_in' => $row['state'] === AttendanceStatusResolver::NOT_IN && in_array($row['reason'], ['absent', 'not_started'], true),
            'checked_out' => $row['state'] === AttendanceStatusResolver::COMPLETED,
            'missing' => $row['state'] === AttendanceStatusResolver::MISSING_CHECKOUT,
            'on_break' => $row['state'] === AttendanceStatusResolver::ON_BREAK,
            'excess_break' => $row['excess_break'],
            default => true,
        };
    }

    /**
     * Today's activity, newest first: every kept punch of the PunchTimeline
     * engine (one entry per real action — duplicates and stray scans are
     * merged by the engine) named by what it was, plus the system auto
     * checkout. One query for every visible employee's punches today.
     *
     * @param  Collection<int, Employee>  $employees  keyed by id
     * @param  array<int, array<string, mixed>>  $statuses
     * @return array<int, array<string, mixed>>
     */
    private function activity(Collection $employees, array $statuses, Carbon $today): array
    {
        if ($employees->isEmpty()) {
            return [];
        }

        $punches = AttendancePunch::whereIn('employee_id', $employees->keys())
            ->where('punch_date', $today->toDateString())
            ->orderBy('punched_at')
            ->get()
            ->groupBy('employee_id');

        $events = [];
        foreach ($punches as $employeeId => $dayPunches) {
            $employee = $employees->get($employeeId);
            $day = $statuses[$employeeId]['day'] ?? null;
            if ($employee === null || $day === null) {
                continue;
            }

            $nodes = array_values(array_filter(
                $this->timeline->process($dayPunches, $today, null, $day->shift)['nodes'],
                fn (array $n) => $n['type'] !== 'missing' && $n['ts_ms'] !== null,
            ));

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
