<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'employee_id', 'phase', 'title', 'description',
    'category', 'owner_role', 'is_completed', 'completed_at', 'completed_by',
    'due_date', 'sort_order',
])]
class OnboardingTask extends Model
{
    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
            'completed_at' => 'datetime',
            'due_date'     => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** @return \Illuminate\Database\Eloquent\Builder<static> */
    public function scopeOnboarding(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('phase', 'onboarding');
    }

    /** @return \Illuminate\Database\Eloquent\Builder<static> */
    public function scopeOffboarding(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('phase', 'offboarding');
    }

    /** @return \Illuminate\Database\Eloquent\Builder<static> */
    public function scopePending(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_completed', false);
    }
}
