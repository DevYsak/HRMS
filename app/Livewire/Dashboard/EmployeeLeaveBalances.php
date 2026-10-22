<?php

namespace App\Livewire\Dashboard;

use App\Models\Department;
use App\Models\Employee;
use App\Services\Leave\LeaveDashboardService;
use App\Services\Leave\LeavePolicyAssignmentResolver;
use App\Services\Leave\LeavePolicyEngine;
use App\Services\Security\PeopleScope;
use App\Services\Security\ScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Employee Leave Balances on the HR dashboard: search by name, code or
 * department; each row is the employee's current policy and balances from
 * the Leave V2 engine (calculator figures), opening their full leave detail.
 * Only the visible page is computed. Read only.
 */
#[Lazy]
class EmployeeLeaveBalances extends Component
{
    use WithPagination;

    public string $search = '';

    public ?int $departmentId = null;

    /** '' | low | negative — set from the Leave cards above. */
    public string $filter = '';

    public function placeholder(): string
    {
        return '<section class="ui-surface h-64 animate-pulse" aria-busy="true"></section>';
    }

    #[On('leave-balances-filter')]
    public function applyFilter(string $filter = ''): void
    {
        $this->filter = in_array($filter, ['low', 'negative'], true) ? $filter : '';
        $this->resetPage('balancesPage');
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'departmentId', 'filter'], true)) {
            $this->resetPage('balancesPage');
        }
    }

    public function render()
    {
        $user = Auth::user();
        if (! $user->hasPermission('view_leave_management')) {
            return view('livewire.dashboard.employee-leave-balances', ['visible' => false]);
        }

        $ids = app(ScopeResolver::class)->employeeIds($user, 'view_leave_management');
        $service = app(LeaveDashboardService::class);
        $term = trim($this->search);

        // Low / negative: only employees whose Earned Leave is in that band.
        $band = null;
        if ($this->filter !== '') {
            $earned = $service->earnedAvailable($ids);
            $band = ($this->filter === 'low'
                ? $earned->filter(fn (float $a) => $a >= 0 && $a <= LeaveDashboardService::LOW_EARNED_DAYS)
                : $earned->filter(fn (float $a) => $a < 0))->keys()->all();
        }

        $employees = Employee::query()->with(['user', 'department'])
            ->whereIn('status', PeopleScope::WORKING_STATUSES)
            ->when($ids !== null, fn (Builder $q) => $q->whereIn('id', $ids))
            ->when($band !== null, fn (Builder $q) => $q->whereIn('id', $band))
            ->when($this->departmentId, fn (Builder $q, int $id) => $q->where('department_id', $id))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('employee_id', 'like', "%{$term}%")
                ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$term}%"))
                ->orWhereHas('department', fn (Builder $d) => $d->where('name', 'like', "%{$term}%"))))
            ->orderBy('employee_id')
            ->paginate(10, pageName: 'balancesPage');

        $year = $service->year();
        $resolver = app(LeavePolicyAssignmentResolver::class);
        $rows = $employees->getCollection()->map(function (Employee $employee) use ($year, $resolver) {
            $policy = $resolver->policyFor($employee, Carbon::today());
            $balances = null;
            if (LeavePolicyEngine::enabled()) {
                try {
                    $balances = app(LeavePolicyEngine::class)->balances($employee, $year);
                } catch (\Throwable $e) {
                    Log::warning('Dashboard leave balances: could not load an employee.', ['employee_id' => $employee->id, 'message' => $e->getMessage()]);
                }
            }
            $earned = $balances['earned'] ?? null;

            return [
                'employee' => $employee,
                'policy' => $policy?->name,
                'v2' => $earned !== null,
                'earned_available' => $earned['available'] ?? null,
                'earned_used' => $earned['used'] ?? null,
                'pending' => $earned['pending'] ?? null,
                'comp_off' => $balances['comp_off']['available'] ?? null,
                'special' => $balances ? collect($balances['special']['subtypes'] ?? [])->sum('available') : null,
                'historical' => count($balances['historical'] ?? []),
            ];
        });

        return view('livewire.dashboard.employee-leave-balances', [
            'visible' => true,
            'rows' => $rows,
            'paginator' => $employees,
            'departments' => Department::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
