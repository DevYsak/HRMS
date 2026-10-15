<?php

namespace App\Models;

use App\Enums\QaFindingStatus;
use App\Enums\QaSeverity;
use Database\Factories\QaFindingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'code', 'qa_phase_id', 'qa_test_case_id', 'kind', 'module', 'title', 'description', 'severity', 'risk',
    'role_affected', 'scope_affected', 'route', 'page', 'viewport', 'theme', 'status', 'root_cause',
    'fix_summary', 'retest_result', 'environment', 'found_at', 'closed_at', 'created_by',
])]
class QaFinding extends Model
{
    /** @use HasFactory<QaFindingFactory> */
    use HasFactory;

    /** @var array<string, string> */
    public const KINDS = ['functional' => 'Functional', 'security' => 'Security', 'rbac' => 'RBAC / Scope', 'ui' => 'UI / UX'];

    protected function casts(): array
    {
        return [
            'status' => QaFindingStatus::class,
            'severity' => QaSeverity::class,
            'found_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return array<int, string> */
    public static function openStatuses(): array
    {
        return collect(QaFindingStatus::cases())->filter(fn (QaFindingStatus $s) => $s->isOpen())->map(fn (QaFindingStatus $s) => $s->value)->values()->all();
    }

    /** @param  Builder<QaFinding>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', self::openStatuses());
    }

    /** Security and RBAC findings weigh on production readiness. */
    public function isSecurity(): bool
    {
        return in_array($this->kind, ['security', 'rbac'], true);
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(QaPhase::class, 'qa_phase_id');
    }

    public function testCase(): BelongsTo
    {
        return $this->belongsTo(QaTestCase::class, 'qa_test_case_id');
    }

    public function fixes(): HasMany
    {
        return $this->hasMany(QaFixLink::class)->latest('fixed_at');
    }

    public function evidence(): MorphMany
    {
        return $this->morphMany(QaEvidence::class, 'evidenceable')->latest();
    }
}
