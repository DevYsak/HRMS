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
    'allow_carry_forward',
    // How a rule applies, not merely whether. Without these in fillable
    // every mass assignment silently dropped them and the record kept the
    // column default.
    'payment_mode',
    'sandwich_mode',
    'carry_forward_mode', 'carry_forward_limit',
    'allow_encashment', 'max_encashable_days', 'encashment_rate_multiplier', 'allow_current_year_encashment',
    'is_sandwich_applicable', 'sandwich_min_days', 'allow_half_day',
    'is_monthly_accrual', 'accrual_days_per_month',
    'gender_restriction', 'probation_restricted', 'notice_period_restricted',
    'max_consecutive_days', 'attachment_required',
    'annual_allocation_days', 'is_system_controlled',
])]
class LeaveType extends Model
{
    use SoftDeletes;

    /**
     * How carry forward is decided for this type.
     *
     * The column existed and was written by the master-data migration, but
     * nothing read it — a type set to NONE was still carried forward, because
     * only allow_carry_forward was ever consulted. These make the setting mean
     * something.
     */
    public const CARRY_NONE = 'none';

    public const CARRY_HR_APPROVAL = 'hr_approval';

    /**
     * Reserved. Nothing applies carry forward unattended: carry forward is
     * never triggered by a balance existing, a year ending, or a type
     * permitting it. A type stored as automatic therefore behaves as
     * hr_approval until that decision is revisited, and the settings screen
     * says so rather than implying otherwise.
     */
    public const CARRY_AUTOMATIC = 'automatic';

    /** The effective mode, defaulting from the older boolean flag. */
    public function carryForwardMode(): string
    {
        if (! $this->allow_carry_forward) {
            return self::CARRY_NONE;
        }

        return $this->carry_forward_mode ?: self::CARRY_HR_APPROVAL;
    }

    /** Whether this type may be carried forward at all. */
    public function permitsCarryForward(): bool
    {
        return $this->carryForwardMode() !== self::CARRY_NONE;
    }

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
            'sandwich_min_days' => 'integer',
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
