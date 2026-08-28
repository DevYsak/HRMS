<?php

namespace App\Services\Leave;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Services\LeaveBalanceService;

/**
 * What a new employee starts with.
 *
 * Onboarding used to seed a flat allocation per leave type, keyed on
 * now()->year — a calendar year, in a company whose leave year runs 1 July to
 * 30 June — and never assigned a leave policy at all, so the UK entitlement
 * engine was built, correct, and connected to nobody.
 *
 * Annual leave is now calculated: policy, then working pattern, then the
 * engine. Nothing else about the previous year is invented. A new employee has
 * no history, so they get no previous-year balance and no carry-forward
 * transaction — carry forward is a decision HR makes about a year that
 * actually happened.
 *
 * Where the data needed to calculate an entitlement is missing, this reports
 * the gap rather than defaulting past it. An entitlement resting on an assumed
 * working pattern is a guess with a number in front of it.
 */
class LeaveProvisioningService
{
    /** The leave type the policy engine governs. */
    public const ANNUAL_CODE = 'AL';

    public function __construct(
        private readonly LeaveEntitlementService $entitlements,
        private readonly LeaveYearResolver $years,
        private readonly LeaveBalanceService $balances,
    ) {}

    /**
     * Resolve the policy that should govern this employee.
     *
     * Priority: an explicitly supplied policy, then one already on the
     * employee, then the company default. Never a silent fall back to flat
     * per-type allocation — that is the legacy behaviour this replaces.
     */
    public function resolvePolicy(Employee $employee, ?LeavePolicy $explicit = null): ?LeavePolicy
    {
        return $explicit
            ?? $employee->leavePolicy
            ?? LeavePolicy::where('is_default', true)->where('is_active', true)->first()
            ?? LeavePolicy::where('is_active', true)->first();
    }

    /**
     * What this employee would be given, without giving it.
     *
     * The import preview renders this, so HR sees the policy, the pattern and
     * the resulting entitlement before a row is written.
     *
     * @return array{policy:?LeavePolicy, policy_name:string, pattern:string, pattern_verified:bool, entitlement:?float, carry_forward:string, issues:array<int,string>}
     */
    public function preview(Employee $employee, ?LeavePolicy $explicit = null): array
    {
        $policy = $this->resolvePolicy($employee, $explicit);
        $issues = [];

        if ($policy === null) {
            $issues[] = 'No leave policy is configured. Annual leave cannot be calculated.';
        }

        // An employee with no pattern of their own is measured against the
        // approved company default, if one is configured. Configured means
        // stated by the business; absent means provisioning stops rather than
        // guesses.
        //
        // Applied to a copy, never to the caller's model. Mutating the employee
        // here and saving it later would write an invented working pattern onto
        // their record as though somebody had confirmed it.
        $subject = clone $employee;

        if (! $this->entitlements->hasRecordedPattern($subject)) {
            $default = config('leave_provisioning.default_working_days_per_week');

            if ($default !== null && $default > 0) {
                $subject->working_pattern = 'regular';
                $subject->working_days_per_week = (float) $default;
                $subject->working_days = config('leave_provisioning.default_working_days');
            }
        }

        $verified = $this->entitlements->hasRecordedPattern($subject);

        if (! $verified) {
            $issues[] = 'Working pattern required for verified entitlement';
        }

        $entitlement = null;

        if ($policy !== null && $verified) {
            // Calculated against the employee with the resolved policy applied,
            // not against whatever they happen to be linked to right now.
            $probe = clone $subject;
            $probe->setRelation('leavePolicy', $policy);

            $entitlement = round($this->entitlements->for($probe, $this->years->current())->totalDays(), 2);
        }

        return [
            'policy' => $policy,
            'policy_name' => $policy?->name ?? 'None',
            'pattern' => $this->describePattern($subject),
            'pattern_verified' => $verified,
            'entitlement' => $entitlement,
            // Always. A new employee has no previous year to carry from.
            'carry_forward' => 'None / not imported',
            'issues' => $issues,
        ];
    }

    /**
     * Give a new employee their policy and their current-year annual leave.
     *
     * Idempotent: an employee who already holds a policy keeps it, and an
     * existing annual balance is never overwritten. Re-importing somebody must
     * not reset the leave they have already taken.
     *
     * @return array{provisioned:bool, entitlement:?float, issues:array<int,string>}
     */
    public function provision(Employee $employee, ?LeavePolicy $explicit = null): array
    {
        $type = LeaveType::where('code', self::ANNUAL_CODE)->first();
        $year = $this->years->current();

        // Every other leave type keeps its configured allocation, and gets it
        // whatever happens to annual leave below. Sick leave does not depend on
        // whether somebody's annual entitlement can be calculated, and refusing
        // it because the working pattern is unrecorded would deny a real
        // entitlement over an unrelated gap.
        //
        // Two faults of the legacy call are fixed here rather than inherited:
        // this is keyed on the LEAVE year, and annual leave is excluded so a
        // flat annual_allocation_days can never stand in for a calculated one.
        $this->balances->initializeFromPolicy(
            $employee,
            $year->legacyYear(),
            $type !== null ? [$type->id] : [],
        );

        $preview = $this->preview($employee, $explicit);

        if ($preview['policy'] === null || ! $preview['pattern_verified']) {
            // Reported, not worked around. A flat fallback here is how an
            // unverified number becomes somebody's entitlement.
            return ['provisioned' => false, 'entitlement' => null, 'issues' => $preview['issues']];
        }

        // An existing assignment is a decision somebody made; this is not the
        // place to revisit it.
        if ($employee->leave_policy_id === null) {
            $employee->forceFill(['leave_policy_id' => $preview['policy']->id])->save();
        }

        if ($type === null) {
            return [
                'provisioned' => false,
                'entitlement' => $preview['entitlement'],
                'issues' => ['Annual Leave ('.self::ANNUAL_CODE.') is not configured.'],
            ];
        }

        $existing = LeaveBalance::where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where('year', $year->legacyYear())
            ->first();

        if ($existing !== null) {
            // Already has this year's annual leave. Rewriting it would discard
            // whatever has been taken, carried or encashed since.
            return ['provisioned' => false, 'entitlement' => (float) $existing->allocated_days, 'issues' => []];
        }

        $balance = LeaveBalance::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'leave_year_id' => $year->id,
            'year' => $year->legacyYear(),
            'allocated_days' => $preview['entitlement'],
            'used_days' => 0,
            // No history to carry from, and none invented.
            'carried_forward_days' => 0,
            'encashed_days' => 0,
            'comp_off_credits' => 0,
        ]);

        AuditLog::record(
            $balance,
            'leave.entitlement_provisioned',
            null,
            [
                'employee_id' => $employee->id,
                'leave_type' => $type->name,
                'leave_type_id' => $type->id,
                'leave_year' => $year->label,
                'leave_year_id' => $year->id,
                'leave_policy' => $preview['policy']->name,
                'leave_policy_id' => $preview['policy']->id,
                'working_pattern' => $preview['pattern'],
                'allocated_days' => $preview['entitlement'],
                'carried_forward_days' => 0,
                'source' => 'onboarding',
            ],
            'Calculated from leave policy and working pattern',
            $employee->id,
        );

        return ['provisioned' => true, 'entitlement' => $preview['entitlement'], 'issues' => []];
    }

    private function describePattern(Employee $employee): string
    {
        if (! $this->entitlements->hasRecordedPattern($employee)) {
            return 'Not recorded';
        }

        $days = $employee->working_days;

        if (is_array($days) && $days !== []) {
            $short = array_map(fn (string $day) => substr($day, 0, 3), $days);

            return count($short) === 5 && $short[0] === 'Mon'
                ? 'Mon-Fri / '.count($short).' days'
                : implode(', ', $short).' / '.count($short).' days';
        }

        return rtrim(rtrim((string) $employee->working_days_per_week, '0'), '.').' days/week';
    }
}
