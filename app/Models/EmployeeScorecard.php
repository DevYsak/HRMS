<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id', 'performance_cycle_id', 'template_id',
    'total_weighted_score', 'attendance_score', 'punctuality_score',
    'leave_impact', 'manager_rating', 'hr_rating', 'final_score',
    'grade', 'rank_in_department', 'rank_in_company',
    'generated_at', 'locked_at',
])]
class EmployeeScorecard extends Model
{
    protected function casts(): array
    {
        return [
            'total_weighted_score' => 'float',
            'attendance_score' => 'float',
            'punctuality_score' => 'float',
            'leave_impact' => 'float',
            'manager_rating' => 'float',
            'hr_rating' => 'float',
            'final_score' => 'float',
            'rank_in_department' => 'integer',
            'rank_in_company' => 'integer',
            'generated_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'performance_cycle_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PerformanceTemplate::class, 'template_id');
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function gradeLabel(): string
    {
        return match ($this->grade) {
            'a_plus' => 'A+ Outstanding',
            'a' => 'A Excellent',
            'b' => 'B Good',
            'c' => 'C Needs Improvement',
            'd' => 'D Critical',
            default => 'Not Graded',
        };
    }

    public function gradeColor(): string
    {
        return match ($this->grade) {
            'a_plus', 'a' => 'on_time',
            'b' => 'warning',
            'c', 'd' => 'terminated',
            default => 'onboarding',
        };
    }

    public static function computeGrade(float $score): string
    {
        return match (true) {
            $score >= 90 => 'a_plus',
            $score >= 80 => 'a',
            $score >= 70 => 'b',
            $score >= 60 => 'c',
            default => 'd',
        };
    }
}
