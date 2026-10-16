<?php

namespace App\Livewire;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Services\ModuleFeatureService;
use App\Services\Navigation\DashboardAccess;
use App\Services\Security\ScopeResolver;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Department Head dashboard: the Manager dashboard (attendance today, leave
 * and OT approvals, team OT, who is on leave, reviews, KPIs) over the
 * departments the user heads or is scoped to, instead of a reporting line.
 *
 * Reach is the data scope of view_attendance (ScopeResolver) — for the
 * Department Head role that is their own and headed departments; HR can
 * narrow it per user. Approvals stay inside the approve_leave reach, which
 * the services enforce.
 *
 * A company-wide viewer (Super Admin, company-wide HR) has no department of
 * their own, so the view needs a department context (policy, 15 Oct): they
 * choose one, and company-wide figures are never shown as a "department".
 */
class DepartmentDashboard extends ManagerDashboard
{
    /** The department a company-wide viewer has chosen. */
    #[Url]
    public string $department = '';

    public function mount(): void
    {
        $user = Auth::user();

        abort_unless(app(DashboardAccess::class)->allows($user, DashboardAccess::DEPARTMENT), 403);
    }

    protected function reach(): ?array
    {
        if (! $this->isCompanyWide()) {
            return $this->scopedReach();
        }

        // Company-wide: only the chosen department, and nobody until one is chosen.
        $department = $this->chosenDepartment();

        return $department === null ? [] : Employee::where('department_id', $department->id)->pluck('id')->all();
    }

    /** @return array<int, int>|null */
    private function scopedReach(): ?array
    {
        $user = Auth::user();

        return $user->hasPermission('view_attendance')
            ? app(ScopeResolver::class)->employeeIds($user, 'view_attendance', includeSelf: false)
            : $user->accessibleEmployeeIds();
    }

    private function isCompanyWide(): bool
    {
        return $this->scopedReach() === null;
    }

    private function chosenDepartment(): ?Department
    {
        return ctype_digit($this->department) ? Department::find((int) $this->department) : null;
    }

    public function render()
    {
        $view = parent::render();

        if ($this->isCompanyWide()) {
            $view->with([
                'departmentOptions' => Department::query()->orderBy('name')->get(['id', 'name']),
                'needsDepartment' => $this->chosenDepartment() === null,
            ]);
        }

        return $view->with('departmentPayroll', $this->departmentPayroll());
    }

    /**
     * Aggregate payroll cost of the department(s) in view — only with an
     * explicit view_department_payroll_summary grant, within its scope, from
     * the last finalised run. Never an individual salary (Phase 4 policy).
     *
     * @return array{cost: float, label: string}|null
     */
    private function departmentPayroll(): ?array
    {
        $user = Auth::user();
        if (! $user->hasPermission('view_department_payroll_summary') || ! app(ModuleFeatureService::class)->payrollEnabled()) {
            return null;
        }

        $inView = $this->reach();
        $granted = app(ScopeResolver::class)->employeeIds($user, 'view_department_payroll_summary', includeSelf: false);
        $ids = match (true) {
            $inView === null => $granted,
            $granted === null => $inView,
            default => array_values(array_intersect($inView, $granted)),
        };

        $lastRun = Payroll::where('status', 'finalized')->orderByDesc('finance_approved_at')->orderByDesc('id')->first();

        return [
            'cost' => $lastRun ? (float) Payslip::where('payroll_id', $lastRun->id)
                ->when($ids !== null, fn ($q) => $q->whereIn('employee_id', $ids))->sum('net_salary') : 0.0,
            'label' => $lastRun ? 'net pay · '.$lastRun->month.' '.$lastRun->year : 'no finalised payroll yet',
        ];
    }

    protected function scopePermission(): string
    {
        return 'view_department_dashboard';
    }

    protected function scopeHeading(): ?string
    {
        if ($this->isCompanyWide()) {
            return $this->chosenDepartment()?->name;
        }

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
