<?php

namespace App\Services\Dashboards;

use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Document;
use App\Models\Employee;
use App\Models\LeaveEncashment;
use App\Models\LeaveEscalation;
use App\Models\LeaveRequest;
use App\Models\OnboardingTask;
use App\Models\OtRequest;
use App\Models\Payroll;
use App\Models\PipRecord;
use App\Models\User;
use App\Models\WarningLetter;
use App\Models\WfhRequest;
use App\Services\Attendance\AttendanceStatusResolver;
use App\Services\Attendance\WorkingDayResolver;
use App\Services\Help\RouteAccess;
use App\Services\ModuleFeatureService;
use App\Services\Security\ScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The figures behind the HR and Super Admin dashboards, each counted once
 * and over the viewer's reach — company-wide for unscoped HR / Super Admin,
 * the configured departments for scoped HR (the ScopeResolver reach of
 * manage_employees, which includes the viewer: they are part of the
 * departments they look after). The views only lay them out,
 * so the same number can never be computed two ways on one page.
 */
class OrganisationOverview
{
    /** Employment states counted as the working population. */
    private const WORKING = ['onboarding', 'probation', 'confirmed', 'active', 'on-leave', 'notice_period'];

    /** @var array<int, int>|null */
    private ?array $ids = null;

    private bool $scoped = false;

    public function for(User $user): self
    {
        $clone = clone $this;
        $clone->ids = $user->hasPermission('manage_employees')
            ? app(ScopeResolver::class)->employeeIds($user, 'manage_employees')
            : $user->accessibleEmployeeIds();
        $clone->scoped = $clone->ids !== null;

        return $clone;
    }

    public function isScoped(): bool
    {
        return $this->scoped;
    }

    /**
     * Everything the HR / Super Admin overview shows, for one viewer. Super
     * Admins also get payroll, people-by-department and security activity.
     *
     * @return array<string, mixed>
     */
    public static function viewData(User $user): array
    {
        $overview = app(self::class)->for($user);
        $isSuperAdmin = $user->isSuperAdmin() || $user->assignedRole?->slug === 'super_admin';

        $data = [
            'heading' => $isSuperAdmin ? 'Company overview' : 'HR operations',
            'scopeLabel' => $overview->scopeLabel($user),
            'people' => $overview->people(),
            'attendance' => $overview->attendanceToday(),
            'approvals' => $overview->approvals(),
            'issues' => $overview->issues(),
        ];

        if ($isSuperAdmin) {
            $data['companyExtras'] = $overview->companyExtras($user);
        }

        return $data;
    }

    /** Whose figures these are, in words. */
    public function scopeLabel(User $user): string
    {
        $today = Carbon::today()->format('l, d F Y');

        if (! $this->scoped) {
            return "All departments · {$today}";
        }

        $names = Department::whereIn('id', Employee::whereIn('id', $this->ids ?? [])->distinct()->pluck('department_id'))
            ->orderBy('name')->pluck('name');

        return ($names->isEmpty() ? 'Your reach' : $names->implode(' · '))." · {$today}";
    }

    /**
     * Super Admin extras — company-wide by definition.
     *
     * @return array{payrollEnabled: bool, payrollRuns: Collection<int, Payroll>, departments: Collection<int, Department>, security: array<string, int>, auditUrl: ?string}
     */
    public function companyExtras(User $user): array
    {
        $now = Carbon::now();
        $since = $now->copy()->subDays(7);
        $events = fn (array $names) => AuditLog::whereIn('event', $names)->where('created_at', '>=', $since)->count();

        return [
            'payrollEnabled' => app(ModuleFeatureService::class)->payrollEnabled(),
            'payrollRuns' => Payroll::where('month', $now->format('F'))->where('year', $now->year)->orderBy('cycle')->get(['id', 'cycle', 'status', 'total_payout']),
            'departments' => Department::withCount(['employees as working_count' => fn ($q) => $q->whereIn('status', self::WORKING)])
                ->orderBy('name')->get(['id', 'name']),
            'security' => [
                'denied' => $events(['APPROVAL_DENIED']),
                'permission_changes' => $events(['ROLE_CREATED', 'ROLE_UPDATED', 'ROLE_DELETED', 'ROLE_CLONED', 'ROLE_ACTIVATED', 'ROLE_DEACTIVATED',
                    'ROLE_PERMISSIONS_CHANGED', 'ROLE_PERMISSION_SCOPES_CHANGED', 'USER_PERMISSION_OVERRIDE_SET', 'USER_PERMISSION_OVERRIDE_REMOVED', 'DEPARTMENT_HEAD_CHANGED']),
                'impersonations' => $events(['IMPERSONATION_STARTED']),
            ],
            'auditUrl' => app(RouteAccess::class)->allows($user, 'settings.audit-log') ? route('settings.audit-log') : null,
        ];
    }

    /** @return array{headcount: int, new_this_month: int, on_probation: int, probation_due: int, leaving: int} */
    public function people(): array
    {
        $today = Carbon::today();

        return [
            'headcount' => $this->employees()->whereIn('status', self::WORKING)->count(),
            'new_this_month' => $this->employees()->whereBetween('joining_date', [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()])->count(),
            'on_probation' => $this->employees()->where('status', 'probation')->count(),
            'probation_due' => $this->employees()->where('status', 'probation')->whereNotNull('probation_end_date')
                ->whereDate('probation_end_date', '<=', $today->addDays(30)->toDateString())->count(),
            'leaving' => $this->employees()->whereHas('exitRecord', fn ($q) => $q->whereDate('last_working_day', '>=', $today->toDateString()))
                ->where('status', '!=', 'inactive')->count(),
        ];
    }

    /**
     * Today, from the shared status resolver (live inside the shift window,
     * missing checkout only after shift end + 1h). Weekly offs have no
     * absences or lates.
     *
     * @return array{present: int, late: int, missing_checkout: int, not_in: int, on_leave: int, weekly_off: bool}
     */
    public function attendanceToday(): array
    {
        $today = Carbon::today();
        $weeklyOff = app(WorkingDayResolver::class)->isWeeklyOff($today);
        $team = $this->employees()->with(['user', 'shift'])->whereIn('status', self::WORKING)
            ->where(fn ($q) => $q->whereNull('joining_date')->orWhereDate('joining_date', '<=', $today->toDateString()))
            ->get();
        $rows = Attendance::whereIn('employee_id', $team->pluck('id'))->where('date', $today->toDateString())->get()->keyBy('employee_id');
        $statuses = app(AttendanceStatusResolver::class)->currentForMany($team, $rows);
        $onLeave = $this->onLeaveTodayIds();

        $present = $late = $missing = $notIn = 0;

        foreach ($statuses as $employeeId => $status) {
            if ($status['state'] === AttendanceStatusResolver::NOT_IN) {
                if (! $weeklyOff && ! in_array($employeeId, $onLeave, true) && ($status['reason'] ?? null) === 'absent') {
                    $notIn++;
                }

                continue;
            }

            $present++;
            $late += ! $weeklyOff && $status['day']->isLate ? 1 : 0;
            $missing += $status['state'] === AttendanceStatusResolver::MISSING_CHECKOUT ? 1 : 0;
        }

        return [
            'present' => $present,
            'late' => $late,
            'missing_checkout' => $missing,
            'not_in' => $notIn,
            'on_leave' => count($onLeave),
            'weekly_off' => $weeklyOff,
        ];
    }

    /** @return array<string, int> pending requests by kind */
    public function approvals(): array
    {
        return [
            'leave_manager' => $this->forEmployees(LeaveRequest::where('status', 'pending'))->count(),
            'leave_hr' => $this->forEmployees(LeaveRequest::where('status', 'pending_hr'))->count(),
            'regularisations' => $this->forEmployees(AttendanceRegularisation::where('status', 'pending'))->count(),
            'overtime' => $this->forEmployees(OtRequest::where('status', 'pending'))->count(),
            'wfh' => $this->forEmployees(WfhRequest::where('status', 'pending'))->count(),
            'encashments' => $this->forEmployees(LeaveEncashment::whereIn('status', ['pending', 'pending_finance']))->count(),
            'escalations' => LeaveEscalation::where('resolved', false)
                ->when($this->ids !== null, fn ($q) => $q->whereHas('leaveRequest', fn ($r) => $r->whereIn('employee_id', $this->ids)))
                ->count(),
        ];
    }

    /** @return array{expiring_documents: int, pending_acknowledgements: int, onboarding_tasks: int, offboarding_tasks: int, active_warnings: int, active_pips: int} */
    public function issues(): array
    {
        return [
            'expiring_documents' => $this->forEmployees(Document::query()->expiringSoon(30))->count(),
            'pending_acknowledgements' => $this->forEmployees(
                Document::whereNull('parent_id')->where('requires_acknowledgement', true)->whereNotNull('employee_id')
                    ->whereDoesntHave('acknowledgements', fn ($q) => $q->whereColumn('document_acknowledgements.employee_id', 'documents.employee_id'))
            )->count(),
            'onboarding_tasks' => $this->forEmployees(OnboardingTask::where('phase', 'onboarding')->where('is_completed', false))->count(),
            'offboarding_tasks' => $this->forEmployees(OnboardingTask::where('phase', 'offboarding')->where('is_completed', false))->count(),
            'active_warnings' => $this->forEmployees(WarningLetter::whereIn('status', ['issued', 'acknowledged', 'under_review']))->count(),
            'active_pips' => $this->forEmployees(PipRecord::whereIn('status', ['active', 'under_review', 'extended']))->count(),
        ];
    }

    /** @return Builder<Employee> */
    private function employees(): Builder
    {
        return Employee::query()->when($this->ids !== null, fn ($q) => $q->whereIn('id', $this->ids));
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    private function forEmployees(Builder $query): Builder
    {
        return $query->when($this->ids !== null, fn ($q) => $q->whereIn($q->getModel()->getTable().'.employee_id', $this->ids));
    }

    /** @return array<int, int> */
    private function onLeaveTodayIds(): array
    {
        $today = Carbon::today()->toDateString();

        return $this->forEmployees(LeaveRequest::where('status', 'approved')->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today))
            ->distinct()->pluck('employee_id')->map(fn ($id) => (int) $id)->all();
    }
}
