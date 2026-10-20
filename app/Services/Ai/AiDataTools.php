<?php

namespace App\Services\Ai;

use App\Models\Department;
use App\Models\HolidayWorkRequest;
use App\Models\User;
use App\Models\WfhRequest;
use App\Services\Approvals\ApprovalQueueService;
use App\Services\Attendance\AttendanceRateService;
use App\Services\Attendance\AttendanceWindowService;
use App\Services\Attendance\HolidayResolver;
use App\Services\Attendance\TodayStatusService;
use App\Services\Leave\EmployeeLeaveOverviewService;
use App\Services\ModuleFeatureService;
use App\Services\OvertimeService;
use App\Services\Performance\PerformanceStatusService;
use App\Services\Profile\ProfileCompletionService;
use App\Services\Security\PeopleScope;
use App\Services\WfhService;
use Illuminate\Support\Carbon;

/**
 * Deterministic, authorisation-first data for the AI assistant. Every number
 * the assistant states about requests comes from here — never from a model.
 *
 * Two questions that sound alike are kept apart on purpose:
 *  - "my pending OT requests"      → requests I SUBMITTED that still wait ({@see self::myRequests()})
 *  - "OT pending my approval"      → other people's requests waiting for ME ({@see self::approvalQueue()})
 * Conflating them is how an employee with one pending OT request was told "0".
 */
class AiDataTools
{
    public function __construct(private OvertimeService $overtime) {}

    /**
     * The user's own requests that are still waiting for a decision.
     * Null when the user has no employee record.
     *
     * @return array{leave: int, overtime: int, wfh: int, regularisation: int, holiday: int}|null
     */
    public function myPendingRequests(User $user): ?array
    {
        $employee = $user->employee;

        if (! $employee) {
            return null;
        }

        return [
            'leave' => $employee->leaveRequests()->whereIn('status', ['pending', 'pending_hr'])->count(),
            'overtime' => $this->overtime->summaryFor($employee)['pending'],
            'wfh' => $employee->wfhRequests()->pending()->count(),
            'regularisation' => $employee->regularisations()->pending()->count(),
            'holiday' => HolidayWorkRequest::where('employee_id', $employee->id)->where('status', 'pending')->count(),
        ];
    }

    /**
     * The user's own OT requests by status, plus approved hours this month.
     * The estimated amount is included only for someone who may see pay.
     *
     * @return array{pending: int, approved: int, rejected: int, cancelled: int, total: int, approved_hours_month: float, approved_amount_month?: float}|null
     */
    public function myOvertime(User $user): ?array
    {
        $employee = $user->employee;

        if (! $employee) {
            return null;
        }

        $summary = $this->overtime->summaryFor($employee);

        if (! $this->maySeeOwnPay($user)) {
            unset($summary['approved_amount_month']);
        }

        return $summary;
    }

    /**
     * The user's leave position from the one calculator every leave screen
     * uses (CSL + Comp Off, never floored; MDL dates are not a balance).
     *
     * @return array<string, mixed>|null
     */
    public function myLeave(User $user): ?array
    {
        $employee = $user->employee;

        if (! $employee) {
            return null;
        }

        $overview = app(EmployeeLeaveOverviewService::class)->for($employee);

        return array_filter([
            'available_leave_csl_plus_comp_off' => $overview['available_leave'] ?? null,
            'casual_sick_leave_available_to_request' => $overview['csl']['summary']['available_to_request'] ?? null,
            'casual_sick_leave_approved_balance' => $overview['csl']['summary']['approved_available'] ?? null,
            'casual_sick_leave_accrued_this_year' => isset($overview['csl']['summary']) ? round($overview['csl']['summary']['base'] + $overview['csl']['summary']['accrued'], 2) : null,
            'casual_sick_leave_rule' => $overview['policy']['grant'] ?? null,
            'comp_off' => $overview['comp_off']['summary']['approved_available'] ?? null,
            'mdl_shutdown_dates' => collect($overview['mdl']['dates'] ?? [])->map(fn ($d) => $d['date']->toDateString())->all(),
        ], fn ($value) => $value !== null);
    }

    /**
     * Requests from OTHER people waiting for this user's decision, one key per
     * approval permission the user holds, counted over that permission's data
     * scope and never including the user's own requests. An empty array means
     * the user approves nothing — not "nothing waiting".
     *
     * @return array<string, int>
     */
    public function approvalQueue(User $user): array
    {
        // The one queue definition (type permission, open statuses, reach,
        // never your own) shared with the Command Center, Team View and badge.
        return app(ApprovalQueueService::class)->counts($user);
    }

    /**
     * Today's attendance over the people this user may see — the same
     * TodayStatusService summary and view_attendance reach as Team View, the
     * Command Center and the HR dashboard. Without that permission, only
     * the user's own status.
     *
     * @return array{scope: string, summary: array<string, mixed>, own: bool}
     */
    public function attendanceToday(User $user, ?int $departmentId = null): array
    {
        $people = app(PeopleScope::class);

        if (! $user->hasPermission('view_attendance')) {
            $own = $user->employee ? [$user->employee->id] : [];

            return ['scope' => 'You', 'summary' => app(TodayStatusService::class)->summary($own), 'own' => true];
        }

        $ids = $people->workingEmployeeIds($user, 'view_attendance', $departmentId ? [$departmentId] : null);

        return [
            'scope' => $people->summary($user, 'view_attendance', $departmentId ? [$departmentId] : null)['label'],
            'summary' => app(TodayStatusService::class)->summary($ids),
            'own' => false,
        ];
    }

    /**
     * Review status counts for the current cycle over the people this user
     * reviews (review_performance reach) — the PerformanceStatusService
     * numbers every dashboard shows. Null when the user reviews nobody.
     *
     * @return array<string, mixed>|null
     */
    public function reviews(User $user, ?int $departmentId = null): ?array
    {
        $permission = $user->hasPermission('review_performance') ? 'review_performance' : null;

        if ($permission === null) {
            return null;
        }

        $people = app(PeopleScope::class);
        $ids = $people->workingEmployeeIds($user, $permission, $departmentId ? [$departmentId] : null);

        return app(PerformanceStatusService::class)->summary($ids)
            + ['scope' => $people->summary($user, $permission, $departmentId ? [$departmentId] : null)['label']];
    }

    /**
     * A department the user may look at, by name — null when it is not in
     * their view_attendance reach (never revealing whether it exists).
     */
    public function departmentNamed(User $user, string $text): ?Department
    {
        if (! $user->hasPermission('view_attendance')) {
            return null;
        }

        $text = mb_strtolower($text);

        return app(PeopleScope::class)->departmentOptions($user, 'view_attendance')
            ->sortByDesc(fn ($d) => mb_strlen($d->name))
            ->first(fn ($d) => str_contains($text, mb_strtolower($d->name)));
    }

    /**
     * The user's own attendance this month to date — the AttendanceWindowService
     * figures My Attendance and Employee 360 show — and today's status.
     *
     * @return array{from: string, to: string, scheduled: int, present: int, late: int, absent: int, leave_days: float, missing_checkouts: int, today: string}|null
     */
    public function myAttendance(User $user): ?array
    {
        $employee = $user->employee;

        if (! $employee) {
            return null;
        }

        $window = app(AttendanceWindowService::class);
        $from = Carbon::today()->startOfMonth();
        $to = Carbon::today();
        $summary = $window->summary($employee, $from, $to);
        $rate = app(AttendanceRateService::class)->forEmployee($employee, $from, $to);
        $today = app(TodayStatusService::class)->summary([$employee->id]);
        $status = $today['rows'] !== [] ? array_values($today['rows'])[0] : null;

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'scheduled' => (int) $summary['scheduled'],
            // The one attendance % (QA-F-079) — the figure every screen shows.
            'attendance_percent' => $rate['percent'],
            'present' => (int) $summary['present'],
            'late' => (int) $summary['late'],
            'absent' => (int) $summary['absent'],
            'leave_days' => (float) $summary['leave_days'],
            'missing_checkouts' => collect($window->days($employee, $from, $to))->where('missing_out', true)->count(),
            'today' => $status ? (TodayStatusService::LABELS[$status['bucket']] ?? str_replace('_', ' ', $status['bucket'])) : 'Not recorded',
        ];
    }

    /**
     * The next public holiday on the user's own calendar (HolidayResolver).
     *
     * @return array{name: string, date: string}|null
     */
    public function nextHoliday(User $user): ?array
    {
        $holiday = app(HolidayResolver::class)->upcomingHolidays($user->employee, 1)->first();

        return $holiday ? ['name' => (string) $holiday->name, 'date' => Carbon::parse($holiday->date)->toDateString()] : null;
    }

    /**
     * The user's WFH position: approved for today (the planned mode), pending
     * requests, and the next approved day. Pending authorises nothing.
     *
     * @return array{approved_today: bool, pending: int, next_approved: ?string}|null
     */
    public function myWfh(User $user): ?array
    {
        $employee = $user->employee;

        if (! $employee) {
            return null;
        }

        $next = WfhRequest::where('employee_id', $employee->id)->where('status', 'approved')
            ->whereDate('end_date', '>', Carbon::today()->toDateString())
            ->orderBy('start_date')->first();

        return [
            'approved_today' => app(WfhService::class)->isApprovedFor($employee, Carbon::today()),
            'pending' => $employee->wfhRequests()->pending()->count(),
            'next_approved' => $next ? Carbon::parse($next->start_date)->max(Carbon::tomorrow())->toDateString() : null,
        ];
    }

    /**
     * The user's own profile completeness — the ProfileCompletionService ring
     * on My Profile. Field labels only, never the values.
     *
     * @return array{percent: int, missing: list<string>, submitted: list<string>}|null
     */
    public function profileCompleteness(User $user): ?array
    {
        $employee = $user->employee;

        if (! $employee) {
            return null;
        }

        $completion = app(ProfileCompletionService::class)->for($employee);

        return [
            'percent' => (int) $completion['percent'],
            'missing' => array_values(array_map(fn ($m) => $m['label'], $completion['missing'])),
            'submitted' => array_values(array_map(fn ($m) => $m['label'], $completion['submitted'])),
        ];
    }

    /**
     * Own OT money is shown only where the person can already see their own
     * pay: the payslips module is on (My Payslips is open to every employee).
     */
    public function maySeeOwnPay(User $user): bool
    {
        return app(ModuleFeatureService::class)->payslipsEnabled() || $user->hasPermission('run_payroll');
    }
}
