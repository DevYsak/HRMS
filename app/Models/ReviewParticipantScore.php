<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A participant's score for one component of a review (v4 Phase D). */
#[Fillable(['participant_id', 'component_id', 'score', 'comment'])]
class ReviewParticipantScore extends Model
{
    protected function casts(): array
    {
        return ['score' => 'float'];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(ReviewParticipant::class, 'participant_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PerformanceComponent::class, 'component_id');
    }
}
