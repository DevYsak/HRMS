<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

#[Fillable([
    'employee_id',
    'attendance_id',
    'work_date',
    'start_time',
    'end_time',
    'requested_hours',
    'reason',
    'status',
    'reviewer_id',
    'reviewer_comment',
    'reviewed_at',
    'source',
    'nexflow_ref',
])]
class OtRequest extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'reviewed_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function overtimeRecord(): HasOne
    {
        return $this->hasOne(OvertimeRecord::class, 'ot_request_id');
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

    public function resolvedRequestedHours(): float
    {
        if ((float) $this->requested_hours > 0) {
            return round((float) $this->requested_hours, 2);
        }

        $start = $this->timeAsCarbon($this->start_time);
        $end = $this->timeAsCarbon($this->end_time);

        if (! $start || ! $end) {
            return 0.0;
        }

        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return round($start->floatDiffInHours($end), 2);
    }

    public function timeWindowLabel(): string
    {
        $start = $this->timeAsCarbon($this->start_time)?->format('h:i A');
        $end = $this->timeAsCarbon($this->end_time)?->format('h:i A');

        return trim(($start ?? '--').' - '.($end ?? '--'));
    }

    public function totalRequestedMinutes(): int
    {
        return (int) round($this->resolvedRequestedHours() * 60);
    }

    private function timeAsCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $date = $this->work_date instanceof Carbon ? $this->work_date->toDateString() : now()->toDateString();

        return Carbon::parse($date.' '.$value);
    }

    /** The HR admin currently handling this request (claim-lock). */
    public function claimer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }
}
