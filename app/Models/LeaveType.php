<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name', 'code', 'is_paid',
    'allow_paid_request', 'allow_unpaid_request', 'allow_hr_override', 'hr_remark_required',
    'color', 'category',
    'allow_carry_forward', 'carry_forward_limit',
    'allow_encashment', 'max_encashable_days', 'encashment_rate_multiplier', 'allow_current_year_encashment',
    'is_sandwich_applicable', 'allow_half_day',
    'is_monthly_accrual', 'accrual_days_per_month',
    'gender_restriction', 'probation_restricted', 'notice_period_restricted',
    'max_consecutive_days', 'attachment_required',
    'annual_allocation_days', 'is_system_controlled',
])]
class LeaveType extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean',
            'allow_paid_request' => 'boolean',
            'allow_unpaid_request' => 'boolean',
            'allow_hr_override' => 'boolean',
            'hr_remark_required' => 'boolean',
            'allow_carry_forward' => 'boolean',
            'carry_forward_limit' => 'integer',
            'allow_encashment' => 'boolean',
            'max_encashable_days' => 'integer',
            'encashment_rate_multiplier' => 'decimal:2',
            'allow_current_year_encashment' => 'boolean',
            'is_sandwich_applicable' => 'boolean',
            'allow_half_day' => 'boolean',
            'is_monthly_accrual' => 'boolean',
            'accrual_days_per_month' => 'decimal:2',
            'probation_restricted' => 'boolean',
            'notice_period_restricted' => 'boolean',
            'max_consecutive_days' => 'integer',
            'attachment_required' => 'boolean',
            'annual_allocation_days' => 'decimal:2',
            'is_system_controlled' => 'boolean',
        ];
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function approvalRules(): HasMany
    {
        return $this->hasMany(LeaveApprovalRule::class);
    }

    public function accrualLogs(): HasMany
    {
        return $this->hasMany(LeaveAccrualLog::class);
    }

    public function balanceAdjustments(): HasMany
    {
        return $this->hasMany(LeaveBalanceAdjustment::class);
    }

    /** Whether the employee's gender is eligible to request this leave type. */
    public function isGenderEligible(?string $gender): bool
    {
        if ($this->gender_restriction === 'none') {
            return true;
        }

        return $this->gender_restriction === $gender;
    }
}
