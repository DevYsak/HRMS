<?php

namespace App\Livewire\Performance;

use App\Models\Department;
use App\Models\EmployeeScorecard;
use App\Models\PerformanceCycle;
use App\Models\PerformanceReviewScore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class KpiDashboard extends Component
{
    public ?int $selectedCycleId = null;

    public ?int $selectedDepartmentId = null;

    public string $search = '';

    public string $gradeFilter = '';

    public function mount(): void
    {
        $latest = PerformanceCycle::whereIn('status', ['active', 'completed', 'locked'])
            ->latest('start_date')
            ->first();

        $this->selectedCycleId = $latest?->id;
    }

    public function updatedSelectedCycleId(): void
    {
        $this->reset(['gradeFilter', 'search']);
    }

    private function getScorecards(): Collection
    {
        if (! $this->selectedCycleId) {
            return collect();
        }

        return EmployeeScorecard::with(['employee.user', 'employee.department'])
            ->where('performance_cycle_id', $this->selectedCycleId)
            ->when($this->selectedDepartmentId, fn ($q) => $q->whereHas('employee', fn ($q2) => $q2->where('department_id', $this->selectedDepartmentId)))
            ->when($this->gradeFilter, fn ($q) => $q->where('grade', $this->gradeFilter))
            ->when($this->search, function ($q) {
                $q->whereHas('employee.user', fn ($q2) => $q2->where('name', 'like', "%{$this->search}%"));
            })
            ->orderByDesc('final_score')
            ->get();
    }

    public function render()
    {
        abort_unless(Auth::user()->canManageSettings() || Auth::user()->isManager(), 403);

        $cycles = PerformanceCycle::whereIn('status', ['active', 'completed', 'locked'])
            ->latest('start_date')
            ->get();

        $scorecards = $this->getScorecards();

        // Stats
        $totalEmployees = $scorecards->count();
        $avgScore = $totalEmployees > 0 ? round($scorecards->avg('final_score'), 1) : null;
        $topScore = $scorecards->first()?->final_score;

        $gradeDistribution = $scorecards->countBy('grade')->toArray();

        // Strength & weakness: avg per component across employees
        $strengthWeakness = [];
        if ($this->selectedCycleId && $totalEmployees > 0) {
            $strengthWeakness = PerformanceReviewScore::query()
                ->join('performance_reviews', 'performance_review_scores.review_id', '=', 'performance_reviews.id')
                ->join('performance_components', 'performance_review_scores.component_id', '=', 'performance_components.id')
                ->where('performance_reviews.performance_cycle_id', $this->selectedCycleId)
                ->selectRaw('performance_components.name as component_name, performance_components.max_score, AVG(performance_review_scores.final_score) as avg_score')
                ->groupBy('performance_components.id', 'performance_components.name', 'performance_components.max_score')
                ->orderByDesc('avg_score')
                ->limit(10)
                ->get()
                ->toArray();
        }

        // Performer segmentation (derived from the already-loaded, score-sorted scorecards)
        $topPerformers = $scorecards->take(5)->values();
        $atRisk = $scorecards->sortBy('final_score')->take(5)->values();
        $promotionReady = $scorecards->filter(fn ($s) => (float) $s->final_score >= 85)->values();

        $departments = Department::orderBy('name')->get();

        // Cycle trend: last 4 cycles avg score
        $trendCycles = PerformanceCycle::whereIn('status', ['completed', 'locked'])
            ->latest('end_date')
            ->limit(6)
            ->with('scorecards')
            ->get()
            ->reverse()
            ->map(fn ($cycle) => [
                'name' => $cycle->name,
                'avg' => round($cycle->scorecards->avg('final_score') ?? 0, 1),
            ])
            ->values();

        return view('livewire.performance.kpi-dashboard', [
            'cycles' => $cycles,
            'scorecards' => $scorecards,
            'totalEmployees' => $totalEmployees,
            'avgScore' => $avgScore,
            'topScore' => $topScore,
            'gradeDistribution' => $gradeDistribution,
            'strengthWeakness' => $strengthWeakness,
            'departments' => $departments,
            'trendCycles' => $trendCycles,
            'topPerformers' => $topPerformers,
            'atRisk' => $atRisk,
            'promotionReady' => $promotionReady,
        ])->layout('layouts.app', ['title' => 'KPI Dashboard']);
    }
}
