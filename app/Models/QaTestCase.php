<?php

namespace App\Models;

use App\Enums\QaSeverity;
use App\Enums\QaTestStatus;
use Database\Factories\QaTestCaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'code', 'qa_phase_id', 'module', 'feature', 'role', 'scope', 'title', 'description',
    'precondition', 'steps', 'expected_result', 'route_name', 'severity', 'automated_reference',
    'status', 'last_tested_at', 'created_by',
])]
class QaTestCase extends Model
{
    /** @use HasFactory<QaTestCaseFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => QaTestStatus::class,
            'severity' => QaSeverity::class,
            'last_tested_at' => 'datetime',
        ];
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(QaPhase::class, 'qa_phase_id');
    }

    /** Every result ever recorded, newest first. Never overwritten. */
    public function results(): HasMany
    {
        return $this->hasMany(QaTestResult::class)->latest('tested_at')->latest('id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(QaFinding::class);
    }

    public function evidence(): MorphMany
    {
        return $this->morphMany(QaEvidence::class, 'evidenceable')->latest();
    }
}
