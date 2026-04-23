<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'title', 'description', 'file_path', 'file_name', 'mime_type', 'file_size',
    'version', 'parent_id', 'category', 'visibility',
    'department_id', 'employee_id', 'requires_acknowledgement',
    'expires_at', 'uploaded_by',
])]
class Document extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'expires_at'              => 'date',
            'requires_acknowledgement' => 'boolean',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderByDesc('version');
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(DocumentAcknowledgement::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /** Returns true when the document expires within 30 days (instance helper for Blade). */
    public function expiringSoon(int $days = 30): bool
    {
        return $this->expires_at
            && ! $this->isExpired()
            && $this->expires_at->lte(now()->addDays($days));
    }

    public function isAcknowledgedBy(Employee $employee): bool
    {
        return $this->acknowledgements()
            ->where('employee_id', $employee->id)
            ->exists();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<static> */
    public function scopeExpiringSoon(\Illuminate\Database\Eloquent\Builder $query, int $days = 30): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', now()->addDays($days))
            ->whereDate('expires_at', '>=', now());
    }
}
