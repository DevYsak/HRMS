<?php

namespace App\Services\Ai;

use App\Models\Document;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OtRequest;
use App\Models\PipRecord;
use App\Models\User;
use App\Models\WarningLetter;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The organisation figures the AI assistant may answer from, for one user.
 *
 * Each figure is included only when the user holds the permission behind it,
 * and is counted over exactly the employees that permission's data scope
 * reaches (ApprovalGuard / ScopeResolver) — company-wide for HR, the
 * department for a Department Head, the reporting line for a Manager, and
 * nothing beyond themselves for an employee. Never decided by role name.
 */
class AiContextBuilder
{
    /**
     * @return array{organisation?: array<string, mixed>}
     */
    public function organisation(User $user): array
    {
        $figures = array_filter([
            'headcount' => $this->count($user, 'view_employee', fn () => Employee::where('status', 'active'), 'id'),
            'on_probation' => $this->count($user, 'manage_employees', fn () => Employee::where('status', 'probation'), 'id'),
            'pending_leave_requests' => $this->count($user, 'approve_leave', fn () => LeaveRequest::where('status', 'pending')),
            'pending_ot_requests' => $this->count($user, 'approve_overtime', fn () => OtRequest::where('status', 'pending')),
            'documents_expiring_30d' => $this->count($user, 'manage_documents', fn () => Document::query()->expiringSoon(30)),
            'employees_on_pip' => $this->count($user, 'manage_pip', fn () => PipRecord::whereIn('status', ['active', 'under_review', 'extended'])),
            'active_warnings' => $this->count($user, 'manage_warning_letters', fn () => WarningLetter::whereIn('status', ['issued', 'acknowledged', 'under_review'])),
        ], fn ($value) => $value !== null);

        if ($figures === []) {
            return [];
        }

        return ['organisation' => [
            // Tells the model whose numbers these are, so it never presents a
            // team figure as the company's.
            'scope' => $user->isCompanyWideApprover() ? 'company' : 'your team / departments',
            ...$figures,
        ]];
    }

    /**
     * Count rows over the user's reach for one permission; null when the user
     * does not hold it or reaches nobody but themselves.
     *
     * @param  Closure(): Builder<Model>  $query
     */
    private function count(User $user, string $permission, Closure $query, string $column = 'employee_id'): ?int
    {
        if (! $user->hasPermission($permission)) {
            return null;
        }

        $ids = $user->accessibleEmployeeIds($permission);

        if ($ids === []) {
            return null;
        }

        return $query()->when($ids !== null, fn ($q) => $q->whereIn($column, $ids))->count();
    }
}
