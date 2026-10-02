<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one leave type behaves under one leave policy. Null settings defer to
 * the leave type's own configuration (see LeaveRuleResolver).
 */
#[Fillable([
    'leave_policy_id', 'leave_type_id',
    'entitlement_method', 'fixed_days',
    'accrual_method', 'accrual_amount', 'accrual_day', 'joining_month_rule', 'accrual_start', 'max_accumulation',
    'carry_forward_enabled', 'carry_forward_max_days', 'carry_forward_percent', 'carry_forward_expiry_months',
    'carry_forward_expiry_date', 'max_balance', 'carry_forward_eligible_statuses',
    'probation_restricted', 'notice_period_restricted', 'max_consecutive_days', 'attachment_required', 'allow_half_day',
    'is_active',
])]
class LeavePolicyRule extends Model
{
    public const ENTITLEMENT_UK_ENGINE = 'uk_engine';

    public const ENTITLEMENT_FIXED = 'fixed_days';

    public const ENTITLEMENT_NONE = 'none';

    public const ACCRUAL_UPFRONT = 'annual_upfront';

    public const ACCRUAL_MONTHLY = 'monthly';

    public const ACCRUAL_QUARTERLY = 'quarterly';

    public const JOINING_FULL = 'full';

    public const JOINING_HALF_MONTH = 'half_month';

    public const JOINING_NONE = 'none';

    public const START_JOINING = 'joining';

    public const START_CONFIRMATION = 'confirmation';

    protected function casts(): array
    {
        return [
            'fixed_days' => 'decimal:2',
            'accrual_amount' => 'decimal:2',
            'accrual_day' => 'integer',
            'max_accumulation' => 'decimal:2',
            'carry_forward_enabled' => 'boolean',
            'carry_forward_max_days' => 'decimal:2',
            'carry_forward_percent' => 'decimal:2',
            'carry_forward_expiry_months' => 'integer',
            'max_balance' => 'decimal:2',
            'carry_forward_eligible_statuses' => 'array',
            'probation_restricted' => 'boolean',
            'notice_period_restricted' => 'boolean',
            'max_consecutive_days' => 'integer',
            'attachment_required' => 'boolean',
            'allow_half_day' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(LeavePolicy::class, 'leave_policy_id')->withTrashed();
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class)->withTrashed();
    }
}
