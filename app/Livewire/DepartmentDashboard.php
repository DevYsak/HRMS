<?php

namespace App\Livewire;

use App\Models\Department;
use App\Services\Security\ScopeResolver;
use Illuminate\Support\Facades\Auth;

/**
 * Department Head dashboard: the Manager dashboard (attendance today, leave
 * and OT approvals, team OT, who is on leave, reviews, KPIs) over the
 * departments the user heads or is scoped to, instead of a reporting line.
 *
 * Reach is the data scope of view_attendance (ScopeResolver) — for the
 * Department Head role that is their own and headed departments; HR can
 * narrow it per user. Approvals stay inside the approve_leave reach, which
 * the services enforce.
 */
class DepartmentDashboard extends ManagerDashboard
{
    public function mount(): void
    {
        $user = Auth::user();

        abort_unless($user->isDepartmentHead() || $user->assignedRole?->slug === 'department_head', 403);
    }

    protected function reach(): ?array
    {
        $user = Auth::user();

        return $user->hasPermission('view_attendance')
            ? app(ScopeResolver::class)->employeeIds($user, 'view_attendance', includeSelf: false)
            : $user->accessibleEmployeeIds();
    }

    protected function scopeHeading(): ?string
    {
        $user = Auth::user();
        $departmentIds = $user->hasPermission('view_attendance')
            ? app(ScopeResolver::class)->departmentIds($user, 'view_attendance')
            : null;

        $names = Department::query()
            ->where(fn ($q) => $q->where('head_id', $user->id)
                ->when($departmentIds, fn ($q, $ids) => $q->orWhereIn('id', $ids)))
            ->orderBy('name')
            ->pluck('name');

        return $names->isEmpty() ? null : $names->implode(' · ');
    }

    protected function pageTitle(): string
    {
        return 'Department Dashboard';
    }
}
