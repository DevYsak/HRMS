<?php

namespace App\Services\Ai;

use App\Models\AttendanceRegularisation;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\WfhRequest;
use App\Services\Leave\EmployeeLeaveOverviewService;
use App\Services\ModuleFeatureService;
use App\Services\OvertimeService;

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
     * @return array{leave: int, overtime: int, wfh: int, regularisation: int}|null
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
        $queue = [];
        $ownEmployeeId = $user->employee?->id;

        $inReach = function ($query, string $permission) use ($user, $ownEmployeeId) {
            $ids = $user->accessibleEmployeeIds($permission);

            return $query
                ->whereHas('employee.user')
                ->when($ids !== null, fn ($q) => $q->whereIn('employee_id', $ids))
                ->when($ownEmployeeId, fn ($q) => $q->where('employee_id', '!=', $ownEmployeeId));
        };

        if ($user->canApproveLeave()) {
            $queue['leave'] = $inReach(LeaveRequest::whereIn('status', ['pending', 'pending_hr']), 'approve_leave')->count();
        }

        $otQueue = $this->overtime->approvalQueueFor($user);
        if ($otQueue !== null) {
            $queue['overtime'] = $otQueue->count();
        }

        if ($user->canApproveWfh()) {
            $queue['wfh'] = $inReach(WfhRequest::pending(), 'approve_wfh')->count();
        }

        if ($user->canApproveRegularisations()) {
            $queue['regularisation'] = $inReach(AttendanceRegularisation::pending(), 'hr_approve_regularisation')->count();
        }

        return $queue;
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
