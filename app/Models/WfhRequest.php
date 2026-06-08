<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'employee_id',
    'start_date',
    'end_date',
    'is_half_day',
    'half_day_period',
    'reason',
    'status',
    'reviewer_id',
    'reviewer_comment',
    'reviewed_at',
])]
class WfhRequest extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_half_day' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** @return Builder<static> */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /** @return Builder<static> */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function daysCount(): float
    {
        if ($this->is_half_day) {
            return 0.5;
        }

        return (float) ($this->start_date->diffInDays($this->end_date) + 1);
    }

    public function dateRangeLabel(): string
    {
        if ($this->start_date->equalTo($this->end_date)) {
            return $this->start_date->format('d M Y');
        }

        return $this->start_date->format('d M Y').' - '.$this->end_date->format('d M Y');
    }
}
