<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One engine-computed attendance score per employee per day (Rule 11).
 * `breakdown` persists every deduction/bonus line the engine applied — the
 * audit trail behind the number, rendered by the "Why?" decision popup.
 */
#[Fillable(['employee_id', 'date', 'score', 'status', 'breakdown'])]
class AttendanceDailyScore extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'score' => 'float',
            'breakdown' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
