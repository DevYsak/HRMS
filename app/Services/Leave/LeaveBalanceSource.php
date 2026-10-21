<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use Carbon\CarbonInterface;

/**
 * Which balance a leave type's requests charge (Leave V2).
 *
 * Earned Leave is ONE shared balance. The employee's policy names the leave
 * type that holds it (leave_policies.earned_leave_type_id); Casual, Sick,
 * Privilege, Personal, Other… are reasons in the Earned Leave category that
 * hold no balance and charge that one pool:
 *
 *     Earned Leave 8 → Sick 2 → 6 → Casual 1 → 5 → Personal 3 → 2
 *
 * Special leave (maternity, paternity, bereavement…) and Comp Off keep their
 * own balances; HR can make a special type draw on Earned Leave instead
 * (leave_types.consumes_earned_leave). Unpaid leave charges nothing.
 */
class LeaveBalanceSource
{
    /** Charges its own balance (the Earned Leave pool itself, special leave, Comp Off). */
    public const OWN = 'own';

    /** Charges the shared Earned Leave balance. */
    public const EARNED_POOL = 'earned_pool';

    /** Charges nothing (Unpaid Leave / LWP). */
    public const NONE = 'none';

    /** Should charge Earned Leave, but the policy names no Earned Leave balance. */
    public const MISSING_POOL = 'missing_pool';

    public function __construct(private readonly LeavePolicyAssignmentResolver $assignments) {}

    /** The leave type holding the employee's Earned Leave balance on $on, if the policy names one. */
    public function earnedPool(Employee $employee, ?CarbonInterface $on = null): ?LeaveType
    {
        return $this->poolOf($this->assignments->policyFor($employee, $on));
    }

    public function poolOf(?LeavePolicy $policy): ?LeaveType
    {
        // Read fresh: the resolver memoises policies, and HR may have just
        // named the Earned Leave type.
        $poolId = $policy ? LeavePolicy::whereKey($policy->id)->value('earned_leave_type_id') : null;

        return $poolId ? LeaveType::find($poolId) : null;
    }

    /**
     * @return array{source: string, type: ?LeaveType, pool: ?LeaveType}
     */
    public function for(Employee $employee, LeaveType $type, ?CarbonInterface $on = null): array
    {
        return $this->forPool($type, $this->earnedPool($employee, $on));
    }

    /**
     * @return array{source: string, type: ?LeaveType, pool: ?LeaveType}
     */
    public function forPool(LeaveType $type, ?LeaveType $pool): array
    {
        $category = LeaveCategory::of($type);
        $drawsOnPool = $category === LeaveCategory::EARNED
            || ($category === LeaveCategory::SPECIAL && $type->consumes_earned_leave);

        return match (true) {
            $category === LeaveCategory::UNPAID => ['source' => self::NONE, 'type' => null, 'pool' => $pool],
            $pool !== null && $type->id === $pool->id => ['source' => self::OWN, 'type' => $type, 'pool' => $pool],
            $drawsOnPool && $pool !== null => ['source' => self::EARNED_POOL, 'type' => $pool, 'pool' => $pool],
            $drawsOnPool => ['source' => self::MISSING_POOL, 'type' => null, 'pool' => null],
            default => ['source' => self::OWN, 'type' => $type, 'pool' => $pool],
        };
    }

    /** A reason that draws on Earned Leave (never a balance of its own). */
    public function isReason(LeaveType $type, ?LeaveType $pool): bool
    {
        return $this->forPool($type, $pool)['source'] === self::EARNED_POOL
            || ($pool === null && LeaveCategory::of($type) === LeaveCategory::EARNED);
    }
}
