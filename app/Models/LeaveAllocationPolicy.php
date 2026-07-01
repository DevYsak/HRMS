<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A conditional default-allocation rule for a leave type. Non-null conditions
 * must all match an employee for the policy to apply; the most specific matching
 * policy wins (see LeaveBalanceService::resolveAllocation()).
 */
class LeaveAllocationPolicy extends Model
{
    protected $fillable = [
        'leave_type_id',
        'department_id',
        'job_title_id',
        'employment_type_id',
        'gender',
        'role',
        'min_service_months',
        'requires_probation_complete',
        'allocated_days',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_service_months' => 'integer',
            'requires_probation_complete' => 'boolean',
            'allocated_days' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class);
    }

    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(EmploymentType::class);
    }

    /** Number of specified (non-null) conditions — higher = more specific. */
    public function specificity(): int
    {
        return (int) (bool) $this->department_id
            + (int) (bool) $this->job_title_id
            + (int) (bool) $this->employment_type_id
            + (int) (bool) $this->gender
            + (int) (bool) $this->role
            + ($this->min_service_months > 0 ? 1 : 0)
            + ($this->requires_probation_complete ? 1 : 0);
    }
}
