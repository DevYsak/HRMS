<?php

namespace App\Models;

use App\Services\Profile\ProfileFieldRegistry;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's request to change an approval-tier profile field.
 *
 * The stored value on the employee record is never touched while a request is
 * pending — the old value stays live, and only approval applies the new one.
 */
#[Fillable([
    'employee_id', 'requested_by', 'field', 'old_value', 'new_value', 'reason',
    'attachment_path', 'status', 'reviewer_id', 'reviewer_comment', 'reviewed_at',
    'claimed_by', 'claimed_at',
])]
class ProfileChangeRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function claimer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Human label for the field, from the registry rather than the raw key. */
    public function fieldLabel(): string
    {
        return ProfileFieldRegistry::label($this->field);
    }

    /**
     * Timeline steps for <x-timeline>, so the requester can see exactly where
     * their request stands without a bespoke view.
     *
     * @return array<int, array<string, mixed>>
     */
    public function timelineSteps(): array
    {
        $steps = [[
            'label' => 'Requested',
            'user' => $this->requestedBy?->name,
            'timestamp' => $this->created_at,
            'icon' => 'pencil-square',
            'tone' => 'neutral',
        ]];

        $steps[] = match ($this->status) {
            self::STATUS_APPROVED => [
                'label' => 'Approved by HR',
                'user' => $this->reviewer?->name,
                'timestamp' => $this->reviewed_at,
                'icon' => 'check-circle',
                'tone' => 'success',
            ],
            self::STATUS_REJECTED => [
                'label' => 'Rejected'.($this->reviewer_comment ? ' — '.$this->reviewer_comment : ''),
                'user' => $this->reviewer?->name,
                'timestamp' => $this->reviewed_at,
                'icon' => 'x-circle',
                'tone' => 'danger',
            ],
            self::STATUS_CANCELLED => [
                'label' => 'Withdrawn',
                'user' => $this->requestedBy?->name,
                'timestamp' => $this->reviewed_at ?? $this->updated_at,
                'icon' => 'minus-circle',
                'tone' => 'neutral',
            ],
            default => [
                'label' => $this->claimed_by ? 'Being reviewed by '.$this->claimer?->name : 'Awaiting HR review',
                'user' => null,
                'timestamp' => null,
                'icon' => 'clock',
                'tone' => 'pending',
            ],
        };

        return $steps;
    }
}
