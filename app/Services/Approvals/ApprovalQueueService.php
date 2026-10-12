<?php

namespace App\Services\Approvals;

use App\Enums\UserRole;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\HolidayWorkRequest;
use App\Models\LeaveEncashment;
use App\Models\LeaveRequest;
use App\Models\OtRequest;
use App\Models\User;
use App\Models\WfhRequest;
use App\Services\Security\PeopleScope;
use App\Services\Teams\ApprovalRoutingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * "What is waiting for me to decide?" — one definition per request type, used
 * by the Command Center, Team View, Approval Center, the sidebar badge, the
 * HR dashboard and the AI assistant, so the numbers agree everywhere.
 *
 * Each type names the permission that decides it; the queue, its count and
 * the per-row Approve/Reject buttons all use that permission's reach
 * (PeopleScope → ApprovalGuard), so a visible action never predictably ends
 * in "You don't have permission". Your own requests are never in your queue.
 *
 * Approval paths describe the CURRENT policy only (no invented stages):
 * leave/OT/WFH/holiday work are one decision by an in-scope approver (D2),
 * regularisations are decided by HR, encashments by a Director or HR Admin
 * (D3) then settled in payroll.
 */
class ApprovalQueueService
{
    /**
     * @var array<string, array{label: string, model: class-string<Model>, permission: string, statuses: list<string>}>
     */
    public const TYPES = [
        'regularisation' => ['label' => 'Regularisation', 'model' => AttendanceRegularisation::class, 'permission' => 'hr_approve_regularisation', 'statuses' => ['pending']],
        'leave' => ['label' => 'Leave', 'model' => LeaveRequest::class, 'permission' => 'approve_leave', 'statuses' => ['pending', 'pending_hr']],
        'wfh' => ['label' => 'WFH', 'model' => WfhRequest::class, 'permission' => 'approve_wfh', 'statuses' => ['pending']],
        'overtime' => ['label' => 'Overtime', 'model' => OtRequest::class, 'permission' => 'approve_overtime', 'statuses' => ['pending']],
        // Holiday work is decided by leave approvers today (no permission of its own).
        'holiday' => ['label' => 'Holiday Work', 'model' => HolidayWorkRequest::class, 'permission' => 'approve_leave', 'statuses' => ['pending']],
    ];

    public function __construct(
        private readonly PeopleScope $people,
        private readonly ApprovalGuard $guard,
    ) {}

    /** Can this user decide requests of this type at all? */
    public function handles(User $user, string $type): bool
    {
        return isset(self::TYPES[$type]) && $user->hasPermission(self::TYPES[$type]['permission']);
    }

    /**
     * Requests of a type waiting for this user's decision, or NULL when the
     * user does not decide that type (so "0 waiting" is never claimed).
     *
     * @return Builder<Model>|null
     */
    public function pendingFor(User $user, string $type): ?Builder
    {
        if (! $this->handles($user, $type)) {
            return null;
        }

        return $this->inScope($user, $type)->whereIn('status', self::TYPES[$type]['statuses']);
    }

    /**
     * Every request of a type inside the user's reach (any status), for
     * decided history and "all in scope" views.
     *
     * @return Builder<Model>
     */
    public function inScope(User $user, string $type): Builder
    {
        $config = self::TYPES[$type];
        $query = $config['model']::query()->whereHas('employee.user');

        return $this->people->constrain($query, $user, $config['permission']);
    }

    /**
     * Pending counts for every type this user decides.
     *
     * @return array<string, int>
     */
    public function counts(User $user): array
    {
        $counts = [];

        foreach (array_keys(self::TYPES) as $type) {
            $query = $this->pendingFor($user, $type);
            if ($query !== null) {
                $counts[$type] = $query->count();
            }
        }

        return $counts;
    }

    public function total(User $user): int
    {
        return array_sum($this->counts($user));
    }

    /**
     * Encashments waiting for this user: first-stage decisions for a Director
     * or HR Admin (D3), the legacy Finance stage for finance approvers.
     *
     * @return Builder<LeaveEncashment>|null
     */
    public function pendingEncashments(User $user): ?Builder
    {
        $stages = array_values(array_filter([
            $this->decidesEncashmentFirstStage($user) ? 'pending' : null,
            $user->canApproveFinance() ? 'pending_finance' : null,
        ]));

        if ($stages === []) {
            return null;
        }

        return $this->people->constrain(LeaveEncashment::query()->whereHas('employee.user'), $user, 'approve_leave')
            ->whereIn('status', $stages);
    }

    /** D3: Director or HR Admin decides an encashment (Super Admin always). */
    public function decidesEncashmentFirstStage(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isHrAdmin() || $user->role === UserRole::Director;
    }

    /**
     * Would the server accept this user's Approve/Reject on this request?
     * Mirrors the service checks: the type's permission, an open status, not
     * your own request, and the employee inside that permission's reach.
     */
    public function canDecide(User $user, Model $request): bool
    {
        $type = $this->typeOf($request);

        if ($type === null || ! $this->handles($user, $type)) {
            return false;
        }

        $open = $type === 'leave'
            ? ['pending', 'pending_hr', 'more_info_requested']
            : self::TYPES[$type]['statuses'];

        if (! in_array($request->status, $open, true)) {
            return false;
        }

        $employee = $request->employee;

        return $employee !== null
            && ! $this->guard->isSelf($user, $employee)
            && $this->people->covers($user, self::TYPES[$type]['permission'], $employee);
    }

    /** The queue type of a request model, or null for anything else. */
    public function typeOf(Model $request): ?string
    {
        foreach (self::TYPES as $type => $config) {
            if ($request instanceof $config['model']) {
                return $type;
            }
        }

        return null;
    }

    /** The permission a request type is decided under. */
    public function permissionFor(string $type): string
    {
        return self::TYPES[$type]['permission'];
    }

    /**
     * Who holds a request now and what happens next, from the current policy.
     *
     * @return array{steps: list<array{label: string, actor: string, state: string}>, current: string, next: string, escalated: ?string}
     */
    public function path(Model $request): array
    {
        $employee = $request->employee;
        $type = $request instanceof LeaveEncashment ? 'encashment' : $this->typeOf($request);
        $status = (string) $request->status;
        $decided = ! in_array($status, ['pending', 'pending_hr', 'pending_finance', 'more_info_requested'], true);

        $approver = $employee ? $this->approverFor($employee, $type === 'holiday' ? $request->manager : null) : null;
        $escalated = $this->escalation($request);

        [$current, $next] = match (true) {
            $decided => [ucfirst(str_replace('_', ' ', $status)).($request->reviewer?->name ? ' by '.$request->reviewer->name : ''), 'Closed'],
            $type === 'regularisation' => ['HR Admin — HR review', 'Final on approval; hours are recalculated'],
            $type === 'encashment' && $status === 'pending_finance' => ['Finance', 'Payroll settlement'],
            $type === 'encashment' => ['Director or HR Admin', 'Payroll settlement on approval'],
            $status === 'pending_hr' => ['HR Admin', 'Final on approval'],
            $status === 'more_info_requested' => [($employee?->user?->name ?? 'Employee').' — more information requested', 'Back to the approver'],
            $type === 'overtime' => [$approver ?? 'HR Admin', 'Approved request; payable OT is worked out from attendance'],
            default => [$approver ?? 'HR Admin', 'Final on approval'],
        };

        $steps = [
            ['label' => 'Submitted', 'actor' => $employee?->user?->name ?? 'Employee', 'state' => 'done'],
            ['label' => $decided ? 'Decided' : 'Awaiting decision', 'actor' => $current, 'state' => $decided ? 'done' : 'current'],
            ['label' => 'Next', 'actor' => $next, 'state' => $decided ? 'done' : 'next'],
        ];

        return ['steps' => $steps, 'current' => $current, 'next' => $next, 'escalated' => $escalated];
    }

    /**
     * The person a pending request is routed to: the reporting manager first
     * (they decide under ApprovalGuard), then the routing chain (team lead,
     * department head, HR). Named with the role they act in.
     */
    public function approverFor(Employee $employee, ?User $preferred = null): ?string
    {
        $user = $preferred ?? $employee->manager ?? app(ApprovalRoutingService::class)->resolveApprover($employee);

        if ($user === null) {
            return null;
        }

        $role = match (true) {
            $employee->department && (int) $employee->department->head_id === (int) $user->id => 'Department Head',
            (int) $employee->manager_id === (int) $user->id => 'Reporting Manager',
            $user->isHrAdmin() || $user->isSuperAdmin() => 'HR Admin',
            default => $user->assignedRole?->name ?? 'Approver',
        };

        return $user->name.' — '.$role;
    }

    /** "Escalated to HR after 24 hours", when the escalation job has fired. */
    private function escalation(Model $request): ?string
    {
        if ($request instanceof LeaveRequest && in_array($request->status, ['pending', 'pending_hr'], true)) {
            $escalation = $request->escalations()->where('resolved', false)->latest('escalated_at')->first();

            return $escalation ? 'Escalated to HR after 24 hours ('.$escalation->escalated_at?->userDateTime().')' : null;
        }

        if ($request instanceof OtRequest && $request->status === 'pending' && $request->escalated_at) {
            return 'Escalated to HR after 24 hours ('.$request->escalated_at->userDateTime().')';
        }

        return null;
    }
}
