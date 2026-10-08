<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\OtRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The one answer to "is this person working, done, or missing a checkout?".
 *
 * Every screen — All Attendance, Team Attendance, the dashboards — renders
 * from this instead of reading `attendance.check_out IS NULL`. It sits on
 * {@see AttendanceCalculator}, so it inherits the PunchTimeline rules: Face =
 * IN, ID Card = OUT, the latest of a 60-second burst, stray / duplicate /
 * wrong-day punches never deciding anything, regularised days following their
 * whole timeline (the correction's boundary plus later genuine punches), and
 * the employee's real shift boundaries (a night shift
 * may cross midnight).
 *
 * States
 *  - working            last valid punch is a Face IN and the shift window
 *                       (start … end + 1h) is still open
 *  - on_break           working, with a break running
 *  - completed          the last valid punch is an ID Card OUT
 *  - missing_checkout   an open IN after the cutoff (shift end + 1h)
 *  - not_in             no valid IN — the reason says why (weekly off,
 *                       holiday, leave, absent, not yet started)
 */
class AttendanceStatusResolver
{
    public const WORKING = 'working';

    public const ON_BREAK = 'on_break';

    public const COMPLETED = 'completed';

    public const MISSING_CHECKOUT = 'missing_checkout';

    public const NOT_IN = 'not_in';

    public const LABELS = [
        self::WORKING => 'Working',
        self::ON_BREAK => 'On break',
        self::COMPLETED => 'Completed',
        self::MISSING_CHECKOUT => 'Missing checkout',
        self::NOT_IN => 'Not in',
    ];

    public function __construct(private readonly AttendanceCalculator $calculator) {}

    /**
     * Status for one employee on one date.
     *
     * @param  Collection<int, AttendancePunch>|null  $punches  null = load them
     * @return array{state: string, label: string, live: bool, on_break: bool, reason: ?string, day: AttendanceDay, first_in: ?Carbon, last_out: ?Carbon, worked_minutes: int, work_date: string}
     */
    public function resolve(Employee $employee, CarbonInterface|string $date, ?Attendance $attendance = null, ?Collection $punches = null, ?CarbonInterface $now = null, ?bool $hasApprovedOt = null): array
    {
        $day = $this->calculator->forDay($employee, $date, $attendance, $punches, $now, $hasApprovedOt);

        return $this->describe($day, $attendance);
    }

    /** Status for an attendance row, on the row's own date. */
    public function forAttendance(Attendance $attendance, ?Collection $punches = null, ?CarbonInterface $now = null): array
    {
        $employee = $attendance->relationLoaded('employee') ? $attendance->employee : $attendance->employee()->first();

        return $this->resolve($employee, $attendance->date, $attendance, $punches, $now);
    }

    /**
     * What an employee is doing right now. Usually today; for a night shift
     * that started yesterday and is still inside its window, that shift day.
     *
     * @return array<string, mixed>
     */
    public function current(Employee $employee, ?Attendance $todayAttendance = null, ?CarbonInterface $now = null): array
    {
        $now = $now ? Carbon::parse($now) : Carbon::now();
        $today = $now->copy()->startOfDay();

        $carried = $this->overnightCarry($employee, $today, $now);

        return $carried ?? $this->resolve($employee, $today, $todayAttendance, null, $now);
    }

    /**
     * Current status for many employees at once, keyed by employee id.
     * Punches and attendance rows are loaded in two queries, not per person.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  Collection<int|string, Attendance>|null  $attendanceByEmployee  today's rows keyed by employee id
     * @return array<int, array<string, mixed>>
     */
    public function currentForMany(Collection $employees, ?Collection $attendanceByEmployee = null, ?CarbonInterface $now = null): array
    {
        $now = $now ? Carbon::parse($now) : Carbon::now();
        $today = $now->copy()->startOfDay();
        $ids = $employees->pluck('id')->all();

        $attendanceByEmployee ??= Attendance::whereIn('employee_id', $ids)->where('date', $today->toDateString())->with('activeBreak')->get()->keyBy('employee_id');
        $punches = AttendancePunch::whereIn('employee_id', $ids)
            ->where('punch_date', $today->toDateString())
            ->orderBy('punched_at')->get()->groupBy('employee_id');

        // Approved leave and approved OT for the whole team in two queries;
        // the calculator below answers each person from these.
        $batch = new self(app(AttendanceCalculator::class, [
            'workingDays' => app(WorkingDayResolver::class)->primeApprovedLeave($ids, $today),
        ]));
        $approvedOt = OtRequest::whereIn('employee_id', $ids)->where('status', 'approved')
            ->where('work_date', $today->toDateString())
            ->distinct()->pluck('employee_id')->mapWithKeys(fn ($id) => [(int) $id => true]);

        $out = [];
        foreach ($employees as $employee) {
            $carried = $this->overnightCarry($employee, $today, $now);
            $out[$employee->id] = $carried ?? $batch->resolve(
                $employee, $today,
                $attendanceByEmployee->get($employee->id),
                $punches->get($employee->id, collect()),
                $now,
                $approvedOt->has((int) $employee->id),
            );
        }

        return $out;
    }

    /**
     * A night shift that began yesterday and has not closed: its session is
     * still the employee's current one (until shift end + 1h).
     *
     * @return array<string, mixed>|null
     */
    private function overnightCarry(Employee $employee, Carbon $today, Carbon $now): ?array
    {
        $yesterday = $today->copy()->subDay();
        $shift = app(ShiftResolver::class)->resolve($employee, $yesterday);

        if (! $shift?->crossesMidnight() || $now->greaterThan($shift->end->copy()->addMinutes(AttendanceCalculator::MISSING_CHECKOUT_AFTER_MINUTES))) {
            return null;
        }

        $attendance = Attendance::where('employee_id', $employee->id)->where('date', $yesterday->toDateString())->with('activeBreak')->first();
        $status = $this->resolve($employee, $yesterday, $attendance, null, $now);

        return $status['state'] === self::NOT_IN ? null : $status;
    }

    /**
     * The status of a day already calculated — for callers that need the
     * AttendanceDay too and must not compute it twice.
     *
     * @return array<string, mixed>
     */
    public function describe(AttendanceDay $day, ?Attendance $attendance = null): array
    {
        $onBreak = $attendance !== null && $attendance->relationLoaded('activeBreak')
            ? $attendance->activeBreak !== null
            : ($attendance?->activeBreak()->exists() ?? false);

        $state = match (true) {
            $day->firstIn === null => self::NOT_IN,
            $day->missingCheckout => self::MISSING_CHECKOUT,
            $day->lastOut !== null => self::COMPLETED,
            $day->live && $onBreak => self::ON_BREAK,
            $day->live => self::WORKING,
            // An open IN that is neither inside the window nor yet past it
            // cannot be shown as working.
            default => self::MISSING_CHECKOUT,
        };

        return [
            'state' => $state,
            'label' => self::LABELS[$state],
            'live' => in_array($state, [self::WORKING, self::ON_BREAK], true),
            'on_break' => $state === self::ON_BREAK,
            'reason' => $state === self::NOT_IN ? $this->reason($day) : null,
            'day' => $day,
            'first_in' => $day->firstIn ? Carbon::parse($day->firstIn) : null,
            'last_out' => $day->lastOut ? Carbon::parse($day->lastOut) : null,
            'worked_minutes' => $day->workedMinutes,
            'work_date' => $day->date->toDateString(),
        ];
    }

    /** Why nobody is in: a day off, leave, absence, or not started yet. */
    private function reason(AttendanceDay $day): string
    {
        return match ($day->dayType) {
            WorkingDayResolver::WEEKLY_OFF => 'weekly_off',
            WorkingDayResolver::PUBLIC_HOLIDAY => 'holiday',
            WorkingDayResolver::MDL_SHUTDOWN => 'mdl',
            WorkingDayResolver::APPROVED_LEAVE => 'leave',
            WorkingDayResolver::EMPLOYMENT_NOT_STARTED, WorkingDayResolver::EMPLOYMENT_ENDED => 'not_employed',
            default => $day->status === AttendanceDay::STATUS_ABSENT ? 'absent' : 'not_started',
        };
    }
}
