<?php

namespace App\Livewire\Performance;

use App\Models\EmployeeKpi;
use App\Models\EmployeeScorecard;
use App\Models\PerformanceCycle;
use App\Models\PerformanceReview;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Dashboard extends Component
{
    public ?int $selectedCycleId = null;

    public function mount(): void
    {
        $employee = Auth::user()->employee;

        if (! $employee) {
            return;
        }

        $latest = PerformanceCycle::where(function ($q) use ($employee) {
            $q->whereHas('employeeKpis', fn ($qq) => $qq->where('employee_id', $employee->id))
                ->orWhereHas('scorecards', fn ($qq) => $qq->where('employee_id', $employee->id))
                ->orWhereHas('reviews', fn ($qq) => $qq->where('employee_id', $employee->id));
        })
            ->latest('start_date')
            ->first();

        $this->selectedCycleId = $latest?->id;
    }

    public function render()
    {
        $user = Auth::user();
        $employee = $user->employee;

        abort_unless($employee, 403);

        $cycles = PerformanceCycle::where(function ($q) use ($employee) {
            $q->whereHas('employeeKpis', fn ($qq) => $qq->where('employee_id', $employee->id))
                ->orWhereHas('scorecards', fn ($qq) => $qq->where('employee_id', $employee->id))
                ->orWhereHas('reviews', fn ($qq) => $qq->where('employee_id', $employee->id));
        })
            ->latest('start_date')
            ->get();

        $cycle = $cycles->firstWhere('id', $this->selectedCycleId);

        $scorecard = null;
        $previousScorecard = null;
        $review = null;
        $kpis = collect();
        $scorecardRows = collect();

        if ($this->selectedCycleId) {
            $scorecard = EmployeeScorecard::where('employee_id', $employee->id)
                ->where('performance_cycle_id', $this->selectedCycleId)
                ->first();

            // Previous cycle's scorecard, for the trend indicator
            $previousScorecard = EmployeeScorecard::where('employee_id', $employee->id)
                ->whereHas('cycle', function ($q) use ($cycle) {
                    if ($cycle?->start_date) {
                        $q->where('start_date', '<', $cycle->start_date);
                    }
                })
                ->whereNotNull('final_score')
                ->orderByDesc('id')
                ->first();

            $review = PerformanceReview::with(['componentScores.component.category'])
                ->where('employee_id', $employee->id)
                ->where('performance_cycle_id', $this->selectedCycleId)
                ->latest('id')
                ->first();

            $kpis = EmployeeKpi::with(['component.category'])
                ->where('employee_id', $employee->id)
                ->where('performance_cycle_id', $this->selectedCycleId)
                ->orderBy('due_date')
                ->get();

            // Build the unified KPI Scorecard table rows.
            if ($review && $review->componentScores->isNotEmpty()) {
                $scorecardRows = $review->componentScores->map(function ($score) {
                    $component = $score->component;
                    $max = $component?->max_score ?: 100;
                    $achievement = $max > 0 ? round(($score->effectiveScore() / $max) * 100, 1) : 0;

                    return (object) [
                        'name' => $component?->name ?? '—',
                        'category' => $component?->category?->name ?? 'Uncategorized',
                        'weight' => $component?->weight_percent,
                        'target' => $component?->target_value,
                        'actual' => $score->effectiveScore(),
                        'max' => $max,
                        'achievement' => $achievement,
                        'feedback' => $score->hr_comment ?? $score->manager_comment ?? $score->self_comment,
                    ];
                });
            } elseif ($kpis->isNotEmpty()) {
                $scorecardRows = $kpis->map(function ($kpi) {
                    $component = $kpi->component;
                    $target = $component?->target_value ?? 100;
                    $achievement = $kpi->progress_percent !== null ? round((float) $kpi->progress_percent, 1) : 0;

                    return (object) [
                        'name' => $component?->name ?? '—',
                        'category' => $component?->category?->name ?? 'Uncategorized',
                        'weight' => $component?->weight_percent,
                        'target' => $target,
                        'actual' => $kpi->achieved_value,
                        'max' => $target,
                        'achievement' => $achievement,
                        'feedback' => $kpi->notes,
                    ];
                });
            }
        }

        $kpiAchievement = $kpis->isNotEmpty() ? round((float) $kpis->avg('progress_percent'), 1) : 0;
        $taskCompletion = $kpis->isNotEmpty()
            ? round(($kpis->where('status', 'achieved')->count() / $kpis->count()) * 100, 1)
            : 0;

        // ── Auto-scored indicators (shift compliance, manager rating %) ──
        $shiftCompliance = null;
        $managerRatingPercent = null;

        if ($review) {
            $shiftScore = $review->componentScores->first(
                fn ($s) => $s->component?->scoring_type === 'auto_shift_compliance'
            );

            if ($shiftScore && $shiftScore->component->max_score > 0) {
                $shiftCompliance = round(($shiftScore->effectiveScore() / $shiftScore->component->max_score) * 100, 1);
            }
        }

        if ($scorecard?->manager_rating !== null) {
            $managerRatingPercent = $scorecard->manager_rating <= 5
                ? round(($scorecard->manager_rating / 5) * 100, 1)
                : round(min((float) $scorecard->manager_rating, 100), 1);
        }

        // ── Performance Timeline ─────────────────────────────────────────
        $timelines = $employee->performanceTimelines()
            ->where('is_visible_to_employee', true)
            ->limit(10)
            ->get();

        // ── Warning Letters ──────────────────────────────────────────────
        $warnings = $employee->warningLetters()
            ->with('acknowledgements')
            ->latest('issue_date')
            ->get();

        $warningStats = [
            'active' => $warnings->whereIn('status', ['issued', 'under_review'])->count(),
            'acknowledged' => $warnings->where('status', 'acknowledged')->count(),
            'pending_ack' => $warnings->where('status', 'issued')->filter(fn ($w) => ! $w->isAcknowledged())->count(),
            'escalated' => $warnings->whereNotNull('previous_warning_id')->count(),
        ];

        // ── PIP ───────────────────────────────────────────────────────────
        $activePip = $employee->activePip()->with(['goals', 'manager', 'hrReviewer', 'departmentHead'])->first();

        // ── Promotions ────────────────────────────────────────────────────
        $promotions = $employee->promotionRecommendations()->latest()->get();

        $promotionStats = [
            'eligible' => $promotions->where('status', 'draft')->count(),
            'under_review' => $promotions->whereIn('status', ['pending_hr', 'pending_dept_head', 'pending_super_admin'])->count(),
            'approved' => $promotions->where('status', 'approved')->count(),
            'rejected' => $promotions->where('status', 'rejected')->count(),
        ];

        // ── Top performance cards ───────────────────────────────────────
        $managerReviewsPending = PerformanceReview::where('employee_id', $employee->id)
            ->where('performance_cycle_id', $this->selectedCycleId)
            ->where('status', 'submitted')
            ->count();

        $stats = [
            'kpis_total' => $kpis->count(),
            'kpis_completed' => $kpis->where('status', 'achieved')->count(),
            'kpis_pending' => $kpis->whereIn('status', ['not_started', 'in_progress'])->count(),
            'open_warnings' => $employee->activeWarnings()->count(),
            'has_active_pip' => $activePip !== null,
            'manager_reviews_pending' => $managerReviewsPending,
        ];

        // ── KPI Highlights ───────────────────────────────────────────────
        $sortedRows = $scorecardRows->sortByDesc('achievement')->values();
        $topPerforming = $sortedRows->take(3);
        $needsImprovement = $sortedRows->reverse()->take(3)->values();

        return view('livewire.performance.dashboard', [
            'employee' => $employee,
            'cycles' => $cycles,
            'cycle' => $cycle,
            'scorecard' => $scorecard,
            'previousScorecard' => $previousScorecard,
            'review' => $review,
            'kpis' => $kpis,
            'scorecardRows' => $scorecardRows,
            'kpiAchievement' => $kpiAchievement,
            'taskCompletion' => $taskCompletion,
            'shiftCompliance' => $shiftCompliance,
            'managerRatingPercent' => $managerRatingPercent,
            'timelines' => $timelines,
            'warnings' => $warnings,
            'warningStats' => $warningStats,
            'activePip' => $activePip,
            'promotions' => $promotions,
            'promotionStats' => $promotionStats,
            'stats' => $stats,
            'topPerforming' => $topPerforming,
            'needsImprovement' => $needsImprovement,
        ])->layout('layouts.app', ['title' => 'My Performance']);
    }
}
