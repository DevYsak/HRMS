<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One reviewer on a performance review (v4 Phase D). Each participant rates
 * the same component set independently; the composite review score weights
 * every submitted participant by weight_percent.
 */
#[Fillable([
    'performance_review_id', 'reviewer_id', 'reviewer_role',
    'weight_percent', 'status', 'submitted_at',
])]
class ReviewParticipant extends Model
{
    public const ROLES = ['self', 'team_lead', 'department_head', 'additional'];

    protected function casts(): array
    {
        return [
            'weight_percent' => 'float',
            'submitted_at' => 'datetime',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(PerformanceReview::class, 'performance_review_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(ReviewParticipantScore::class, 'participant_id');
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }

    public function roleLabel(): string
    {
        return match ($this->reviewer_role) {
            'self' => 'Self-assessment',
            'team_lead' => 'Team Lead',
            'department_head' => 'Department Head',
            'additional' => 'Additional Reviewer',
            default => ucfirst(str_replace('_', ' ', $this->reviewer_role)),
        };
    }
}
