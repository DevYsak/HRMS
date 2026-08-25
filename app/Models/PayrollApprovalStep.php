<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-payroll instance tracking for one step of a configured approval chain —
 * snapshotted by value from PayrollApprovalPolicy at submit time, so editing
 * the policy later never changes an in-flight payroll's required steps.
 */
#[Fillable([
    'payroll_id', 'level', 'label', 'approver_type', 'specific_user_id',
    'status', 'approver_id', 'acted_at', 'note',
])]
class PayrollApprovalStep extends Model
{
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'acted_at' => 'datetime',
        ];
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function specificUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'specific_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /** Can this user act on this step — role match (super_admin always passes) or the exact specific_user. */
    public function isEligible(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($this->approver_type === 'specific_user') {
            return $user->id === $this->specific_user_id;
        }

        return $user->role?->value === $this->approver_type;
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
