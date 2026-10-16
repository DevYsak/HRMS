<?php

namespace App\Livewire\Employees;

use App\Models\Department;
use App\Models\User;
use App\Services\DataQuality\DataQualityAuditor;
use App\Services\DataQuality\DataQualityIssue;
use App\Services\Help\RouteAccess;
use App\Services\Security\ScopeResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * People → Data Quality. A read-only view of DataQualityAuditor for HR and
 * Super Admin: what is missing, contradictory or unroutable in the org
 * structure. It links to the records to fix by hand — it has no bulk or
 * automatic correction. A department-scoped viewer sees only their reach;
 * role drift and per-user grants are company-wide and shown only to
 * company-wide viewers.
 */
class DataQuality extends Component
{
    /** @var array<string, string> */
    public const TABS = [
        'issues' => 'Issues',
        'structure' => 'Reporting structure',
        'departments' => 'Department health',
        'managers' => 'Manager health',
        'routing' => 'Approval routing',
        'roles' => 'Role drift',
        'wfh' => 'WFH consistency',
    ];

    #[Url]
    public string $tab = 'issues';

    #[Url]
    public string $department = '';

    #[Url]
    public string $check = '';

    #[Url]
    public string $severity = '';

    #[Url]
    public string $employee = '';

    #[Url]
    public string $status = '';

    public function mount(): void
    {
        abort_unless($this->user()->hasPermission('view_hr_data_quality'), 403);

        if (! array_key_exists($this->tab, self::TABS) || ($this->tab === 'roles' && ! $this->companyWide())) {
            $this->tab = 'issues';
        }
    }

    public function setTab(string $tab): void
    {
        if (array_key_exists($tab, self::TABS) && ($tab !== 'roles' || $this->companyWide())) {
            $this->tab = $tab;
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['department', 'check', 'severity', 'employee', 'status']);
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }

    private function companyWide(): bool
    {
        return app(ScopeResolver::class)->employeeIds($this->user(), 'view_hr_data_quality') === null;
    }

    /**
     * Issues inside the viewer's reach.
     *
     * @param  Collection<int, DataQualityIssue>  $issues
     * @return Collection<int, DataQualityIssue>
     */
    private function inReach(Collection $issues, ?array $employeeIds, ?array $departmentIds): Collection
    {
        if ($employeeIds === null) {
            return $issues;
        }

        return $issues->filter(fn (DataQualityIssue $i) => match (true) {
            $i->employeeId !== null => in_array($i->employeeId, $employeeIds, true),
            $i->departmentId !== null => in_array($i->departmentId, $departmentIds ?? [], true),
            default => false,   // users, roles, grants: company-wide only
        })->values();
    }

    /** @return array{employee: ?string, department: ?string, role: ?string} */
    public function links(DataQualityIssue $issue): array
    {
        $access = app(RouteAccess::class);
        $user = $this->user();

        return [
            'employee' => $issue->employeeId && $access->allows($user, 'employees.edit') && $user->canManageEmployees()
                ? route('employees.edit', $issue->employeeId)
                : ($issue->employeeId && $access->allows($user, 'employees.360') ? route('employees.360', $issue->employeeId) : null),
            'department' => $issue->departmentId && $access->allows($user, 'settings.departments') ? route('settings.departments') : null,
            'role' => in_array($issue->subjectType, ['role', 'user'], true) && $user->hasPermission('manage_roles') && $access->allows($user, 'settings.roles') ? route('settings.roles') : null,
        ];
    }

    public function render(DataQualityAuditor $auditor, ScopeResolver $scopes)
    {
        $user = $this->user();
        $employeeIds = $scopes->employeeIds($user, 'view_hr_data_quality');
        $departmentIds = $scopes->departmentIds($user, 'view_hr_data_quality');
        $companyWide = $employeeIds === null;

        $all = $this->inReach($auditor->issues(), $employeeIds, $departmentIds);
        $issues = $all
            ->when($this->department !== '', fn ($c) => $c->where('departmentId', (int) $this->department))
            ->when($this->check !== '', fn ($c) => $c->where('check', $this->check))
            ->when($this->severity !== '', fn ($c) => $c->where('severity', $this->severity))
            ->when($this->status !== '', fn ($c) => $c->where('employeeStatus', $this->status))
            ->when($this->employee !== '', fn ($c) => $c->filter(fn (DataQualityIssue $i) => $i->employeeId !== null && str_contains(mb_strtolower($i->subjectLabel), mb_strtolower(trim($this->employee)))))
            ->values();

        $keep = fn (array $rows, string $key = 'employee_id') => $companyWide ? $rows : array_values(array_filter($rows, fn ($r) => in_array($r[$key] ?? null, $key === 'department_id' ? ($departmentIds ?? []) : $employeeIds, true)));

        $data = [
            'companyWide' => $companyWide,
            'counts' => $auditor->checkedCounts(),
            'summary' => collect(DataQualityAuditor::SEVERITIES)->mapWithKeys(fn ($s) => [$s => $all->where('severity', $s)->count()])->all()
                + ['data' => $all->filter(fn ($i) => $i->nature() === 'data')->count(), 'policy' => $all->filter(fn ($i) => $i->nature() === 'policy')->count()],
            'issues' => $issues,
            'departments' => Department::query()->when(! $companyWide, fn ($q) => $q->whereIn('id', $departmentIds ?? []))->orderBy('name')->get(['id', 'name']),
            'checks' => $all->pluck('check')->unique()->mapWithKeys(fn ($c) => [$c => DataQualityAuditor::CHECKS[$c]['label']])->sort(),
        ];

        $data += match ($this->tab) {
            'structure' => ['rows' => $keep($auditor->structure())],
            'departments' => ['rows' => $keep($auditor->departmentHealth(), 'department_id')],
            'managers' => ['rows' => $keep($auditor->managerHealth(), 'department_id')],
            'routing' => ['rows' => $keep($auditor->routing())],
            'wfh' => ['rows' => $keep($auditor->wfhConsistency())],
            'roles' => ['rows' => $companyWide ? $auditor->roleDrift() : [], 'overrides' => $companyWide ? $auditor->overrides() : []],
            default => [],
        };

        return view('livewire.employees.data-quality', $data)->layout('layouts.app', ['title' => 'Data Quality']);
    }
}
