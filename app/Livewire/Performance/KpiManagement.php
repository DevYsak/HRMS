<?php

namespace App\Livewire\Performance;

use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\PerformanceCycle;
use App\Services\Performance\PerformanceStatusService;
use App\Services\Security\PeopleScope;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Performance → KPI Management: the KPIs of a cycle with how many people
 * carry each and how far they are, over the viewer's review_performance
 * reach. Weights are validated per cycle template; a department in reach
 * with no KPIs for the cycle is called out. Configuring KPIs stays on KPI
 * Templates (manage_kpi_templates).
 */
class KpiManagement extends Component
{
    public const PERMISSION = 'review_performance';

    #[Url]
    public ?int $cycleId = null;

    #[Url]
    public string $departmentId = '';

    public function mount(): void
    {
        $user = Auth::user();
        abort_unless($user->hasPermission(self::PERMISSION) || $user->hasPermission('manage_kpi_templates'), 403);

        $this->cycleId ??= app(PerformanceStatusService::class)->currentCycle()?->id;
    }

    public function render()
    {
        $user = Auth::user();
        $people = app(PeopleScope::class);
        $permission = $user->hasPermission(self::PERMISSION) ? self::PERMISSION : 'manage_kpi_templates';

        $departments = $people->departmentOptions($user, $permission);
        $department = $people->sanitiseDepartment($user, $permission, $this->departmentId);
        $ids = $people->workingEmployeeIds($user, $permission, $department ? [$department] : null);

        $cycles = PerformanceCycle::orderByDesc('start_date')->get(['id', 'name', 'start_date', 'status']);
        $cycle = $this->cycleId ? $cycles->firstWhere('id', $this->cycleId) : null;
        $cycle?->load('template.components.category');

        $kpis = $cycle
            ? EmployeeKpi::where('performance_cycle_id', $cycle->id)->whereIn('employee_id', $ids)
                ->get(['employee_id', 'component_id', 'status'])->groupBy('component_id')
            : collect();

        $template = $cycle?->template;
        $appliesTo = $template ? $template->appliesTo() : null;

        $rows = collect($template?->components ?? [])->map(fn ($component) => [
            'kpi' => $component->name,
            'category' => $component->category?->name,
            'department' => $appliesTo,
            'weight' => (float) $component->weight_percent,
            'employees' => $kpis->get($component->id, collect())->pluck('employee_id')->unique()->count(),
            'completed' => $kpis->get($component->id, collect())->whereIn('status', ['achieved', 'missed'])->count(),
            'pending' => $kpis->get($component->id, collect())->whereIn('status', ['not_started', 'in_progress'])->count(),
        ])->values();

        $warnings = [];
        if (! $cycle) {
            $warnings[] = 'No review cycle is running yet.';
        } elseif (! $template || $rows->isEmpty()) {
            $warnings[] = 'No KPIs are configured for '.$cycle->name.'.';
        } elseif (! $template->isWeightageValid()) {
            $warnings[] = 'KPI weights for '.$cycle->name.' total '.rtrim(rtrim(number_format($template->totalWeight(), 2), '0'), '.').'% — they must total 100%.';
        }

        if ($cycle) {
            $withKpis = EmployeeKpi::where('performance_cycle_id', $cycle->id)->whereIn('employee_id', $ids)->distinct()->pluck('employee_id');
            $departmentsWithKpis = Employee::whereIn('id', $withKpis)->distinct()->pluck('department_id')->filter()->all();
            ($department ? $departments->where('id', $department) : $departments)
                ->reject(fn ($d) => in_array($d->id, $departmentsWithKpis, true))
                ->filter(fn ($d) => Employee::whereIn('id', $ids)->where('department_id', $d->id)->exists())
                ->each(function ($d) use (&$warnings, $cycle) {
                    $warnings[] = $cycle->name.' KPIs have not yet been configured for '.$d->name.'.';
                });
        }

        return view('livewire.performance.kpi-management', [
            'scope' => $people->summary($user, $permission, $department ? [$department] : null),
            'departments' => $departments,
            'lockedDepartment' => $departments->count() === 1 ? $departments->first() : null,
            'cycles' => $cycles,
            'cycle' => $cycle,
            'rows' => $rows,
            'warnings' => $warnings,
            'reviews' => app(PerformanceStatusService::class)->summary($ids, $cycle),
            'canConfigure' => $user->hasPermission('manage_kpi_templates'),
        ])->layout('layouts.app', ['title' => 'KPI Management']);
    }
}
