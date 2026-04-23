<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['employee_id', 'performance_review_id', 'title', 'description', 'self_rating', 'manager_rating', 'self_comment', 'manager_comment', 'due_date', 'is_completed', 'completed_at'])]
class ReviewGoal extends Model
{
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'is_completed' => 'boolean',
            'self_rating' => 'integer',
            'manager_rating' => 'integer',
        ];
    }

    public function employee(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function review(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PerformanceReview::class, 'performance_review_id');
    }
}
