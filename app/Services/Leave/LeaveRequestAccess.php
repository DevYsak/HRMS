<?php

namespace App\Services\Leave;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Approvals\ApprovalGuard;
use App\Services\Security\ScopeResolver;

/**
 * Who may open the HR leave request list and a request's detail page.
 *
 * Anyone who manages leave (view_leave_management, in its scope) or decides
 * it (approve_leave: HR, department heads, managers for their reporting
 * line). The reach is the union of both; deciding is still checked per
 * request by ApprovalQueueService / LeaveService, never here.
 */
class LeaveRequestAccess
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ApprovalGuard $guard,
    ) {}

    public function canBrowse(User $user): bool
    {
        return $user->hasPermission('view_leave_management') || $user->canApproveLeave();
    }

    /**
     * Employees whose requests the user may see, or null for company-wide.
     *
     * @return array<int, int>|null
     */
    public function employeeIds(User $user): ?array
    {
        $sets = [];
        if ($user->hasPermission('view_leave_management')) {
            $sets[] = $this->scopes->employeeIds($user, 'view_leave_management');
        }
        if ($user->canApproveLeave()) {
            $sets[] = $this->guard->accessibleEmployeeIds($user, 'approve_leave');
        }

        if ($sets === []) {
            return [];
        }

        if (in_array(null, $sets, true)) {
            return null;
        }

        return array_values(array_unique(array_map('intval', array_merge(...$sets))));
    }

    public function canView(User $user, LeaveRequest $request): bool
    {
        if (! $this->canBrowse($user)) {
            return false;
        }

        $ids = $this->employeeIds($user);

        return $ids === null || in_array((int) $request->employee_id, $ids, true);
    }
}
