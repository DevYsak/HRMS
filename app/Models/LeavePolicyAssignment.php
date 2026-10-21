<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which leave policy applies to a company, a department or one employee, and
 * from when (Leave V2). Precedence is resolved by LeavePolicyAssignmentResolver:
 * employee > department > company. Assignments are revoked, never deleted, so
 * the policy that applied on any past date can always be explained.
 */
#[Fillable([
    'leave_policy_id', 'scope', 'scope_id', 'effective_from', 'effective_to',
    'notes', 'created_by', 'revoked_at', 'revoked_by',
])]
class LeavePolicyAssignment extends Model
{
    public const SCOPE_COMPANY = 'company';

    public const SCOPE_DEPARTMENT = 'department';

    public const SCOPE_EMPLOYEE = 'employee';

    /** Highest precedence first. */
    public const PRECEDENCE = [self::SCOPE_EMPLOYEE, self::SCOPE_DEPARTMENT, self::SCOPE_COMPANY];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'revoked_at' => 'datetime',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(LeavePolicy::class, 'leave_policy_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** In force on the given date and not revoked. */
    public function scopeInForceOn(Builder $query, CarbonInterface $date): Builder
    {
        $day = $date->toDateString();

        return $query->whereNull('revoked_at')
            ->whereDate('effective_from', '<=', $day)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day));
    }

    public function sourceLabel(): string
    {
        return match ($this->scope) {
            self::SCOPE_EMPLOYEE => 'Employee Override',
            self::SCOPE_DEPARTMENT => 'Department Policy',
            default => 'Company Policy',
        };
    }
}
