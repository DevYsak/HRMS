<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'employee_id', 'issued_by', 'department_id',
    'warning_type', 'reason', 'description',
    'issue_date', 'next_review_date', 'attachment_path',
    'status', 'hr_comments', 'manager_comments',
    'previous_warning_id', 'closed_at', 'closed_by',
])]
class WarningLetter extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'next_review_date' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function previousWarning(): BelongsTo
    {
        return $this->belongsTo(WarningLetter::class, 'previous_warning_id');
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(WarningLetter::class, 'previous_warning_id');
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(WarningAcknowledgement::class, 'warning_letter_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function pipRecord(): HasOne
    {
        return $this->hasOne(PipRecord::class, 'warning_letter_id');
    }

    /** Documents attached to this warning letter (issued copy, evidence, signed acknowledgement, etc.). */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledgements()->exists();
    }

    public function warningTypeLabel(): string
    {
        return match ($this->warning_type) {
            'verbal' => 'Verbal Warning',
            'first_written' => 'First Written Warning',
            'final_written' => 'Final Warning',
            'pip' => 'PIP',
            'termination_review' => 'Termination Review',
            default => ucfirst($this->warning_type),
        };
    }

    public function warningTypeColor(): string
    {
        return match ($this->warning_type) {
            'verbal' => 'blue',
            'first_written' => 'amber',
            'final_written' => 'orange',
            'pip' => 'red',
            'termination_review' => 'rose',
            default => 'zinc',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft' => 'Draft',
            'issued' => 'Issued',
            'acknowledged' => 'Acknowledged',
            'under_review' => 'Under Review',
            'closed' => 'Closed',
            default => ucfirst($this->status),
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'draft' => 'warning-draft',
            'issued' => 'warning-issued',
            'acknowledged' => 'warning-acknowledged',
            'under_review' => 'warning-under_review',
            'closed' => 'warning-closed',
            default => 'warning-draft',
        };
    }

    public function nextWarningType(): ?string
    {
        return match ($this->warning_type) {
            'verbal' => 'first_written',
            'first_written' => 'final_written',
            'final_written' => 'pip',
            'pip' => 'termination_review',
            default => null,
        };
    }
}
