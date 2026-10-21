<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyAssignment;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Which leave policy applies to an employee on a date, and where it came from
 * (Leave V2). Precedence: employee assignment > department assignment >
 * company assignment > the employee's legacy leave_policy_id > the default
 * policy. Every step is effective-dated, so a past request is always judged
 * against the policy that applied then.
 */
class LeavePolicyAssignmentResolver
{
    public const SOURCE_EMPLOYEE = 'employee';

    public const SOURCE_DEPARTMENT = 'department';

    public const SOURCE_COMPANY = 'company';

    /** employees.leave_policy_id, set before assignments existed. */
    public const SOURCE_EMPLOYEE_RECORD = 'employee_record';

    public const SOURCE_DEFAULT = 'default';

    public const SOURCE_LABELS = [
        self::SOURCE_EMPLOYEE => 'Employee Override',
        self::SOURCE_DEPARTMENT => 'Department Policy',
        self::SOURCE_COMPANY => 'Company Policy',
        self::SOURCE_EMPLOYEE_RECORD => 'Employee Record',
        self::SOURCE_DEFAULT => 'Default Policy',
    ];

    /** @var array<string, array{policy: ?LeavePolicy, source: string, assignment: ?LeavePolicyAssignment}> */
    private array $memo = [];

    /**
     * @return array{policy: ?LeavePolicy, source: string, source_label: string, assignment: ?LeavePolicyAssignment}
     */
    public function resolve(Employee $employee, ?CarbonInterface $on = null): array
    {
        $on ??= Carbon::today();
        $key = $employee->id.'|'.$employee->department_id.'|'.$on->toDateString();

        $resolved = $this->memo[$key] ??= $this->lookup($employee, $on);

        return $resolved + ['source_label' => self::SOURCE_LABELS[$resolved['source']]];
    }

    public function policyFor(Employee $employee, ?CarbonInterface $on = null): ?LeavePolicy
    {
        return $this->resolve($employee, $on)['policy'];
    }

    public function flush(): void
    {
        $this->memo = [];
    }

    /** @return array{policy: ?LeavePolicy, source: string, assignment: ?LeavePolicyAssignment} */
    private function lookup(Employee $employee, CarbonInterface $on): array
    {
        $candidates = LeavePolicyAssignment::query()
            ->with('policy')
            ->inForceOn($on)
            ->where(function ($q) use ($employee) {
                $q->where(fn ($q) => $q->where('scope', LeavePolicyAssignment::SCOPE_EMPLOYEE)->where('scope_id', $employee->id))
                    ->orWhere(fn ($q) => $q->where('scope', LeavePolicyAssignment::SCOPE_DEPARTMENT)->where('scope_id', $employee->department_id))
                    ->orWhere('scope', LeavePolicyAssignment::SCOPE_COMPANY);
            })
            // The most recent start wins within a scope.
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        foreach (LeavePolicyAssignment::PRECEDENCE as $scope) {
            $match = $candidates->first(fn (LeavePolicyAssignment $a) => $a->scope === $scope
                && ($scope === LeavePolicyAssignment::SCOPE_COMPANY || $a->scope_id !== null)
                && $a->policy !== null && ! $a->policy->trashed() && $a->policy->is_active);

            if ($match) {
                return ['policy' => $match->policy, 'source' => $scope, 'assignment' => $match];
            }
        }

        if ($employee->leave_policy_id && ($policy = $employee->leavePolicy) && $policy->is_active) {
            return ['policy' => $policy, 'source' => self::SOURCE_EMPLOYEE_RECORD, 'assignment' => null];
        }

        return ['policy' => LeavePolicy::default(), 'source' => self::SOURCE_DEFAULT, 'assignment' => null];
    }
}
