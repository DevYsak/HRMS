<?php

namespace App\Services\Performance;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeScorecard;
use App\Models\PerformanceAutoScoreConfig;
use App\Models\PerformanceComponent;
use App\Models\PerformanceCycle;
use App\Models\PerformanceReview;
use App\Models\PerformanceReviewScore;
use App\Models\PerformanceTemplate;
use Illuminate\Support\Facades\DB;

class KpiScoringEngine
{
    /**
     * Compute auto-score for a single component using attendance/leave data.
     */
    public function computeAutoScore(Employee $employee, PerformanceComponent $component, PerformanceCycle $cycle): float
    {
        $config = PerformanceAutoScoreConfig::where('component_id', $component->id)->first();

        if (! $config) {
            return 0.0;
        }

        $rawValue = match ($config->source_type) {
            'attendance_pct' => $this->attendancePercent($employee, $cycle),
            'late_marks' => $this->lateMarksCount($employee, $cycle),
            'early_exits' => $this->earlyExitsCount($employee, $cycle),
            'unauthorized_leave' => $this->unauthorizedLeaveDays($employee, $cycle),
            'lwp' => $this->lwpDays($employee, $cycle),
            'approved_leave' => $this->approvedLeaveDays($employee, $cycle),
            'shift_compliance' => $this->shiftCompliancePercent($employee, $cycle),
            default => 0.0,
        };

        return $config->compute($rawValue);
    }

    /**
     * Compute the full scorecard for an employee in a cycle.
     * Saves/updates EmployeeScorecard and each PerformanceReviewScore row.
     */
    public function buildScorecard(Employee $employee, PerformanceCycle $cycle, PerformanceTemplate $template): EmployeeScorecard
    {
        return DB::transaction(function () use ($employee, $cycle, $template) {
            $review = PerformanceReview::firstOrCreate(
                ['employee_id' => $employee->id, 'performance_cycle_id' => $cycle->id, 'type' => 'self'],
                ['review_cycle_id' => null, 'template_id' => $template->id, 'status' => 'draft'],
            );

            $components = $template->components()->with('autoScoreConfig')->get();
            $totalWeighted = 0.0;
            $attendanceScore = null;
            $punctualityScore = null;
            $leaveImpact = null;

            foreach ($components as $component) {
                $score = PerformanceReviewScore::firstOrCreate(
                    ['review_id' => $review->id, 'component_id' => $component->id],
                    ['auto_computed' => $component->isAutoScored()],
                );

                if ($component->isAutoScored()) {
                    $autoVal = $this->computeAutoScore($employee, $component, $cycle);
                    $score->update([
                        'final_score' => $autoVal,
                        'auto_computed' => true,
                        'weighted_score' => round($autoVal * $component->weight_percent / 100, 4),
                    ]);

                    // Track named auto-scores for the scorecard header
                    if ($component->scoring_type === 'auto_attendance') {
                        $attendanceScore = $autoVal;
                    }
                    if ($component->scoring_type === 'auto_punctuality') {
                        $punctualityScore = $autoVal;
                    }
                    if (in_array($component->scoring_type, ['auto_leave', 'unauthorized_leave', 'lwp'], true)) {
                        $leaveImpact = $autoVal;
                    }
                } else {
                    // Use hr_score > manager_score > self_score as effective
                    $effective = $score->hr_score ?? $score->manager_score ?? $score->self_score ?? 0.0;
                    $weighted = round($effective * $component->weight_percent / 100, 4);
                    $score->update(['final_score' => $effective, 'weighted_score' => $weighted]);
                }

                $totalWeighted += $score->fresh()->weighted_score ?? 0;
            }

            $finalScore = round($totalWeighted, 2);
            $grade = EmployeeScorecard::computeGrade($finalScore);

            $scorecard = EmployeeScorecard::updateOrCreate(
                ['employee_id' => $employee->id, 'performance_cycle_id' => $cycle->id],
                [
                    'template_id' => $template->id,
                    'total_weighted_score' => $finalScore,
                    'final_score' => $finalScore,
                    'grade' => $grade,
                    'attendance_score' => $attendanceScore,
                    'punctuality_score' => $punctualityScore,
                    'leave_impact' => $leaveImpact,
                    'generated_at' => now(),
                ],
            );

            $review->update(['final_score' => $finalScore, 'grade' => $grade]);

            return $scorecard;
        });
    }

    // ── Raw data helpers ──────────────────────────────────────────────────────

    private function attendancePercent(Employee $employee, PerformanceCycle $cycle): float
    {
        $total = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$cycle->start_date, $cycle->end_date])
            ->count();

        if ($total === 0) {
            return 0.0;
        }

        $present = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$cycle->start_date, $cycle->end_date])
            ->whereNotNull('check_in')
            ->count();

        return round($present / $total * 100, 2);
    }

    private function lateMarksCount(Employee $employee, PerformanceCycle $cycle): float
    {
        return (float) Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$cycle->start_date, $cycle->end_date])
            ->where('is_late', true)
            ->count();
    }

    private function earlyExitsCount(Employee $employee, PerformanceCycle $cycle): float
    {
        return (float) Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$cycle->start_date, $cycle->end_date])
            ->where('is_early_exit', true)
            ->count();
    }

    private function unauthorizedLeaveDays(Employee $employee, PerformanceCycle $cycle): float
    {
        return (float) $employee->leaveRequests()
            ->whereHas('leaveType', fn ($q) => $q->where('category', 'unauthorized'))
            ->where('status', 'approved')
            ->whereBetween('start_date', [$cycle->start_date, $cycle->end_date])
            ->sum('days');
    }

    private function lwpDays(Employee $employee, PerformanceCycle $cycle): float
    {
        return (float) $employee->leaveRequests()
            ->whereHas('leaveType', fn ($q) => $q->where('category', 'lwp'))
            ->where('status', 'approved')
            ->whereBetween('start_date', [$cycle->start_date, $cycle->end_date])
            ->sum('days');
    }

    private function approvedLeaveDays(Employee $employee, PerformanceCycle $cycle): float
    {
        return (float) $employee->leaveRequests()
            ->where('status', 'approved')
            ->whereBetween('start_date', [$cycle->start_date, $cycle->end_date])
            ->sum('days');
    }

    private function shiftCompliancePercent(Employee $employee, PerformanceCycle $cycle): float
    {
        // Placeholder — full shift compliance needs shift_schedules table (Phase 2+)
        return 100.0;
    }
}
