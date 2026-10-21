<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use Carbon\CarbonInterface;

/**
 * The canonical Leave Policy interface (Leave V2).
 *
 * One front door for "which policy, which rules, what would this request do,
 * what does the employee hold". It delegates to the existing canonical parts —
 * LeaveRuleResolver (rules), LeaveRequestEvaluator (requests),
 * LeaveBalanceCalculator via LeaveCategoryBalances (balances), LeavePolicyCap —
 * so no page can compute a balance its own way.
 *
 * Gated by config('leave_provisioning.policy_engine_v2'): off locally and in
 * production until the migration preview is approved; on in the V2 tests.
 */
class LeavePolicyEngine
{
    public function __construct(
        private readonly LeavePolicyAssignmentResolver $assignments,
        private readonly LeaveRuleResolver $rules,
        private readonly LeaveRequestEvaluator $evaluator,
        private readonly LeaveCategoryBalances $balances,
        private readonly LeavePolicyCap $cap,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('leave_provisioning.policy_engine_v2', false);
    }

    /** @return array{policy: ?LeavePolicy, source: string, source_label: string, assignment: mixed} */
    public function policyFor(Employee $employee, ?CarbonInterface $on = null): array
    {
        return $this->assignments->resolve($employee, $on);
    }

    /** @return array<string, mixed> */
    public function settings(Employee $employee, LeaveType $type, ?CarbonInterface $on = null): array
    {
        return $this->rules->settings($employee, $type, $on);
    }

    /** @return array<string, mixed> */
    public function evaluate(Employee $employee, LeaveType $type, CarbonInterface $start, CarbonInterface $end, bool $isHalfDay = false, ?int $excludeRequestId = null): array
    {
        return $this->evaluator->evaluate($employee, $type, $start, $end, $isHalfDay, $excludeRequestId);
    }

    /** @return array<string, mixed> */
    public function balances(Employee $employee, LeaveYear $year): array
    {
        return $this->balances->forEmployee($employee, $year);
    }

    /** @return array<string, mixed> */
    public function cap(LeavePolicy $policy, ?array $proposed = null): array
    {
        return $this->cap->summary($policy, $proposed);
    }
}
