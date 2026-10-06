<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\AttendanceSetting;
use App\Models\CoordinatorAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\AttendanceExceptionNotification;
use App\Services\Audit\AuditService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationRecipients;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Coordinator workflow: attendance exceptions among the people a
 * coordinator monitors, reminders to the employee, escalations to the
 * manager or HR, and the scheduled digest.
 *
 *   absent            a past scheduled working day with no attendance — or
 *                     today, once the shift start + the alert threshold has
 *                     passed (weekly offs, holidays, MDL, leave never count)
 *   late              late by more than the threshold beyond grace
 *   missing check-out clocked in, never out
 *   regularisation    a request still waiting for HR
 *
 * A coordinator monitors only what HR assigned them (employees and whole
 * departments); HR monitors everyone in their own reach. Every reminder and
 * escalation is sent once per person per issue per day, and the digest
 * repeats an unresolved issue at most once per reminder interval — so
 * nothing is sent twice and an escalation never escalates itself.
 */
class CoordinatorService
{
    public const TYPES = ['absent', 'late', 'missing_checkout', 'regularisation'];

    public function __construct(
        private WorkingDayResolver $days,
        private ShiftResolver $shifts,
        private NotificationDispatcher $dispatcher,
    ) {}

    /** @return array<int, int> */
    public function monitoredEmployeeIds(User $user): array
    {
        if (! $user->hasPermission('monitor_attendance_exceptions')) {
            return [];
        }

        // HR (employee management) watches everyone in its own reach.
        if ($user->canManageEmployees()) {
            $reach = $user->accessibleEmployeeIds();

            return $this->active(Employee::query()->when($reach !== null, fn ($q) => $q->whereIn('id', $reach)))->pluck('id')->all();
        }

        $assignments = CoordinatorAssignment::where('coordinator_user_id', $user->id)->get();
        $departments = $assignments->pluck('department_id')->filter()->all();
        $employees = $assignments->pluck('employee_id')->filter()->all();

        if ($departments === [] && $employees === []) {
            return [];
        }

        return $this->active(Employee::query()->where(fn ($q) => $q
            ->whereIn('id', $employees)
            ->orWhereIn('department_id', $departments)))
            ->where('user_id', '!=', $user->id)
            ->pluck('id')->all();
    }

    /**
     * @return array<string, Collection<int, array{employee_id: int, name: string, type: string, date: string, detail: string}>>
     */
    public function exceptions(User $user, CarbonInterface $date): array
    {
        $date = Carbon::parse($date)->startOfDay();
        $ids = $this->monitoredEmployeeIds($user);
        // One collection per type (array_fill_keys would share a single instance).
        $result = collect(self::TYPES)->mapWithKeys(fn (string $type) => [$type => collect()])->all();

        if ($ids === []) {
            return $result;
        }

        $threshold = $this->lateThreshold();
        $employees = Employee::with(['user', 'shift', 'exitRecord'])->whereIn('id', $ids)->get();
        $attendance = Attendance::whereIn('employee_id', $ids)->whereDate('date', $date)->get()->keyBy('employee_id');
        $item = fn (Employee $e, string $type, string $detail) => [
            'employee_id' => $e->id, 'name' => $e->user?->name ?? (string) $e->employee_id,
            'type' => $type, 'date' => $date->toDateString(), 'detail' => $detail,
        ];

        foreach ($employees as $employee) {
            $row = $attendance->get($employee->id);

            if ($row === null) {
                if ($this->days->classify($employee, $date) === WorkingDayResolver::WORKING_DAY && $this->absentBy($employee, $date, $threshold)) {
                    $result['absent']->push($item($employee, 'absent', 'No attendance'));
                }

                continue;
            }

            if (($row->is_late || $row->status === 'late') && (int) $row->late_minutes > $threshold) {
                $result['late']->push($item($employee, 'late', (int) $row->late_minutes.' min late'));
            }

            if ($row->check_in && ! $row->check_out && ($row->missing_checkout || $date->lt(Carbon::today()))) {
                $result['missing_checkout']->push($item($employee, 'missing_checkout', 'In at '.$row->check_in->format('H:i')));
            }
        }

        AttendanceRegularisation::with('employee.user')->whereIn('employee_id', $ids)->where('status', 'pending')
            ->whereDate('work_date', '<=', $date)->orderBy('work_date')->get()
            ->each(fn ($r) => $result['regularisation']->push([
                'employee_id' => $r->employee_id, 'name' => $r->employee?->user?->name ?? '',
                'type' => 'regularisation', 'date' => Carbon::parse($r->work_date)->toDateString(), 'detail' => 'Waiting for HR',
            ]));

        return $result;
    }

    /** Remind the employee once per issue per day. Returns whether it was sent now. */
    public function remind(User $actor, Employee $employee, string $type, string $date): bool
    {
        $this->authorise($actor, $employee, $type);

        $sent = $employee->user !== null && $this->dispatcher->sendOnce(
            $employee->user,
            (new AttendanceExceptionNotification('reminder', [['name' => $employee->user->name, 'type' => $type, 'date' => Carbon::parse($date)->format('d M Y')]], $actor->name))->forRole('employee'),
            "coord:remind:{$type}:{$employee->id}:{$date}",
        );

        if ($sent) {
            app(AuditService::class)->event('ATTENDANCE_EXCEPTION_REMINDED', AuditService::ATTENDANCE, $employee,
                new: ['type' => $type, 'date' => $date], subjectEmployeeId: $employee->id, actor: $actor);
        }

        return $sent;
    }

    /**
     * Escalate to the employee's manager or to HR, once per issue per day per
     * audience. Returns how many people were notified now (0 = already done).
     */
    public function escalate(User $actor, Employee $employee, string $type, string $date, string $to): int
    {
        $this->authorise($actor, $employee, $type);
        abort_unless(in_array($to, ['manager', 'hr'], true), 422);

        $recipients = ($to === 'manager'
            ? collect([$employee->manager])
            : app(NotificationRecipients::class)->hrCovering($employee))
            ->filter()
            ->reject(fn (User $u) => $u->id === $actor->id || $u->id === $employee->user_id);

        $sent = $this->dispatcher->sendToRecipients(
            AttendanceExceptionNotification::class,
            $recipients,
            fn () => (new AttendanceExceptionNotification('escalation', [['name' => $employee->user?->name ?? '', 'type' => $type, 'date' => Carbon::parse($date)->format('d M Y')]], $actor->name))
                ->forRole($to === 'hr' ? 'hr_admin' : 'manager'),
            "coord:escalate:{$to}:{$type}:{$employee->id}:{$date}",
            $employee,
        );

        if ($sent > 0) {
            app(AuditService::class)->event('ATTENDANCE_EXCEPTION_ESCALATED', AuditService::ATTENDANCE, $employee,
                new: ['type' => $type, 'date' => $date, 'to' => $to, 'recipients' => $sent], subjectEmployeeId: $employee->id, actor: $actor);
        }

        return $sent;
    }

    /**
     * The scheduled digest: each coordinator hears about exceptions they
     * have not been told about in the current reminder interval — new ones
     * at once, unresolved ones again only when the interval rolls over.
     * Returns how many coordinators were notified.
     */
    public function alertCoordinators(?CarbonInterface $at = null): int
    {
        $at = Carbon::parse($at ?? now());
        $interval = max(1, (int) (AttendanceSetting::query()->value('coordinator_reminder_hours') ?? 2));
        $bucket = intdiv($at->hour, $interval);
        $notified = 0;

        $coordinators = User::whereIn('id', CoordinatorAssignment::distinct()->pluck('coordinator_user_id'))->get()
            ->filter(fn (User $u) => $u->hasPermission('monitor_attendance_exceptions'));

        foreach ($coordinators as $coordinator) {
            $fresh = collect($this->exceptions($coordinator, $at->copy()))
                ->flatten(1)
                ->filter(fn (array $i) => DB::table('notification_dispatches')->insertOrIgnore([
                    'notification_key' => AttendanceExceptionNotification::class,
                    'recipient_user_id' => $coordinator->id,
                    'dedup_key' => substr("coord:digest:{$coordinator->id}:{$i['type']}:{$i['employee_id']}:{$i['date']}:{$at->toDateString()}:{$bucket}", 0, 191),
                    'sent_at' => now(),
                ]) === 1)
                ->values();

            if ($fresh->isNotEmpty()) {
                $coordinator->notify((new AttendanceExceptionNotification('digest', $fresh->map(fn ($i) => [
                    'name' => $i['name'], 'type' => $i['type'], 'date' => Carbon::parse($i['date'])->format('d M Y'),
                ])->all()))->forRole('coordinator'));
                $notified++;
            }
        }

        return $notified;
    }

    /** @throws AuthorizationException */
    private function authorise(User $actor, Employee $employee, string $type): void
    {
        if (! $actor->hasPermission('remind_employees') || ! in_array($type, self::TYPES, true)
            || ! in_array($employee->id, $this->monitoredEmployeeIds($actor), true)) {
            throw new AuthorizationException('You do not monitor this employee.');
        }
    }

    private function absentBy(Employee $employee, Carbon $date, int $threshold): bool
    {
        if ($date->lt(Carbon::today())) {
            return true;
        }

        if ($date->gt(Carbon::today())) {
            return false;
        }

        // The employee's shift; without one, the company shift start (09:00
        // when nothing is configured) — someone is absent only once the start,
        // grace and alert threshold have all passed.
        $shift = $this->shifts->resolve($employee, $date);
        $start = $shift?->start->copy()
            ?? Carbon::parse($date->toDateString().' '.(AttendanceSetting::query()->value('shift_start') ?: '09:00'));

        return now()->gt($start->addMinutes(($shift?->graceMinutes ?? 0) + $threshold));
    }

    private function lateThreshold(): int
    {
        return max(0, (int) (AttendanceSetting::query()->value('coordinator_late_minutes') ?? 15));
    }

    private function active($query)
    {
        return $query->whereNotIn('status', ['inactive', 'archived', 'resigned', 'terminated', 'absconded', 'draft']);
    }
}
