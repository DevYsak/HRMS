<?php

namespace App\Services\Leave;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeLeaveOverride;
use App\Models\LeaveAllocationPolicy;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyRule;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Services\LeaveBalanceService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The one place that decides what an employee is entitled to (Phase 2B).
 *
 * Priority, highest first:
 *   1. EmployeeLeaveOverride   — an exception for this one person;
 *   2. LeaveAllocationPolicy   — a conditional group override;
 *   3. LeavePolicyRule         — the leave type's rule inside their policy;
 *   4. LeaveType               — the type's own legacy settings, kept only so
 *                                types nobody has written a rule for still work.
 *
 * Annual Leave with no rule (or a uk_engine rule) is calculated by the UK
 * weeks-based engine: 5.6 weeks, working pattern, part-time and joiner
 * pro-rata all live there and are not reimplemented here.
 */
class LeaveRuleResolver
{
    /** Statuses that hold a leave entitlement. */
    public const ELIGIBLE_STATUSES = ['onboarding', 'probation', 'confirmed', 'active', 'notice_period', 'resigned', 'on-leave'];

    /** @var array<int, Collection<int, LeaveAllocationPolicy>>|null */
    private ?array $groupPolicies = null;

    public function __construct(
        private readonly LeaveEntitlementService $engine,
        private readonly LeaveBalanceService $balances,
    ) {}

    public function policyFor(Employee $employee): ?LeavePolicy
    {
        return $employee->leavePolicy ?? LeavePolicy::default();
    }

    public function ruleFor(Employee $employee, LeaveType $type): ?LeavePolicyRule
    {
        $policy = $this->policyFor($employee);

        return $policy
            ? LeavePolicyRule::where('leave_policy_id', $policy->id)->where('leave_type_id', $type->id)->where('is_active', true)->first()
            : null;
    }

    /**
     * The effective settings for one employee and leave type, rule over type.
     *
     * @return array{
     *     entitlement_method: string, fixed_days: ?float, accrual_method: string, accrual_amount: float,
     *     accrual_day: int, joining_month_rule: string, accrual_start: string, max_accumulation: ?float,
     *     carry_forward_enabled: bool, carry_forward_max_days: ?float, carry_forward_percent: ?float,
     *     carry_forward_expiry_months: ?int, carry_forward_expiry_date: ?string, max_balance: ?float,
     *     carry_forward_eligible_statuses: ?array<int, string>, probation_restricted: bool,
     *     notice_period_restricted: bool, max_consecutive_days: ?int, attachment_required: bool,
     *     allow_half_day: bool, rule_id: ?int, policy_id: ?int, policy_name: ?string
     * }
     */
    public function settings(Employee $employee, LeaveType $type): array
    {
        $rule = $this->ruleFor($employee, $type);
        $policy = $this->policyFor($employee);
        $pick = fn (string $ruleKey, mixed $fallback) => $rule && $rule->{$ruleKey} !== null ? $rule->{$ruleKey} : $fallback;

        $legacyMethod = $type->code === LeaveProvisioningService::ANNUAL_CODE
            ? LeavePolicyRule::ENTITLEMENT_UK_ENGINE
            : LeavePolicyRule::ENTITLEMENT_FIXED;
        // CSL's release schedule is not stated by the Conexus policy, so even
        // without a rule (an employee on another or no policy, a stale model)
        // it is manual: never granted up front from a type-level figure, and
        // never withdrawn by a recalculation. A rule may still choose one.
        $legacyAccrual = match (true) {
            $type->code === ConexusLeavePolicyService::CSL_CODE => LeavePolicyRule::ACCRUAL_MANUAL,
            $type->is_monthly_accrual && (float) $type->accrual_days_per_month > 0 => LeavePolicyRule::ACCRUAL_MONTHLY,
            default => LeavePolicyRule::ACCRUAL_UPFRONT,
        };

        $carryLegacy = $type->allow_carry_forward && $type->carry_forward_mode !== LeaveType::CARRY_NONE;

        return [
            'entitlement_method' => $rule?->entitlement_method ?? $legacyMethod,
            'fixed_days' => $rule?->fixed_days !== null ? (float) $rule->fixed_days
                : ($type->annual_allocation_days !== null ? (float) $type->annual_allocation_days : null),
            'accrual_method' => $rule?->accrual_method ?? $legacyAccrual,
            'accrual_amount' => (float) ($rule?->accrual_amount ?? $type->accrual_days_per_month ?? 0),
            'accrual_day' => (int) ($rule?->accrual_day ?? 1),
            'joining_month_rule' => $rule?->joining_month_rule ?? LeavePolicyRule::JOINING_HALF_MONTH,
            'accrual_start' => $rule?->accrual_start ?? LeavePolicyRule::START_JOINING,
            'max_accumulation' => $rule?->max_accumulation !== null ? (float) $rule->max_accumulation : null,
            'carry_forward_enabled' => (bool) $pick('carry_forward_enabled', $carryLegacy),
            // True only when the policy rule itself enables carry forward —
            // the rule then states the decision, so no HR approval is needed.
            'carry_forward_by_rule' => $rule?->carry_forward_enabled === true,
            // The policy is canonical for the cap; leave_types.carry_forward_limit
            // is deliberately not consulted (see LeaveCarryOverService).
            'carry_forward_max_days' => ($v = $pick('carry_forward_max_days', $policy?->max_carry_over_days)) !== null ? (float) $v : null,
            'carry_forward_percent' => $rule?->carry_forward_percent !== null ? (float) $rule->carry_forward_percent : null,
            'carry_forward_expiry_months' => ($v = $pick('carry_forward_expiry_months', $policy?->carry_over_expiry_months)) !== null ? (int) $v : null,
            'carry_forward_expiry_date' => $rule?->carry_forward_expiry_date,
            'max_balance' => $rule?->max_balance !== null ? (float) $rule->max_balance : null,
            'carry_forward_eligible_statuses' => $rule?->carry_forward_eligible_statuses,
            'probation_restricted' => (bool) $pick('probation_restricted', $type->probation_restricted),
            'notice_period_restricted' => (bool) $pick('notice_period_restricted', $type->notice_period_restricted),
            'max_consecutive_days' => ($v = $pick('max_consecutive_days', $type->max_consecutive_days)) !== null ? (int) $v : null,
            'attachment_required' => (bool) $pick('attachment_required', $type->attachment_required),
            'allow_half_day' => (bool) $pick('allow_half_day', $type->allow_half_day),
            'rule_id' => $rule?->id,
            'policy_id' => $policy?->id,
            'policy_name' => $policy?->name,
        ];
    }

    /** The expiry date a carried-forward lot gets in the year it lands in: a fixed MM-DD, or N months in. */
    public function carryForwardExpiry(array $settings, LeaveYear $to): ?string
    {
        if ($settings['carry_forward_expiry_date']) {
            [$month, $day] = array_map('intval', explode('-', $settings['carry_forward_expiry_date']));
            $date = Carbon::create($to->starts_on->year, $month, $day);

            if ($date->lt($to->starts_on)) {
                $date = $date->addYear();
            }

            return $date->toDateString();
        }

        return $settings['carry_forward_expiry_months']
            ? $to->starts_on->copy()->addMonths($settings['carry_forward_expiry_months'])->subDay()->toDateString()
            : null;
    }

    /** Whether the employee holds an entitlement at all in this year. */
    public function isEligible(Employee $employee, LeaveType $type, LeaveYear $year): bool
    {
        $status = $employee->status instanceof EmployeeStatus ? $employee->status->value : (string) $employee->status;

        if (! in_array($status, self::ELIGIBLE_STATUSES, true)) {
            return false;
        }

        if ($employee->joining_date && Carbon::parse($employee->joining_date)->gt($year->ends_on)) {
            return false;
        }

        // Gender restrictions apply when leave is requested, as they always
        // have; the balance itself is still provisioned.
        return true;
    }

    /**
     * The base entitlement this employee should hold for this type and year,
     * and how it was reached. days is null when the type grants nothing up
     * front (accrual-only, no rule, or not calculable — see issues).
     *
     * @return array{days: ?float, source: string, method: string, explanation: string, issues: array<int, string>, breakdown: array<string, mixed>}
     */
    public function entitlement(Employee $employee, LeaveType $type, LeaveYear $year): array
    {
        $settings = $this->settings($employee, $type);
        $issues = [];
        $breakdown = ['policy' => $settings['policy_name'], 'rule_id' => $settings['rule_id']];

        $days = null;
        $source = 'none';
        $method = $settings['entitlement_method'];
        $explanation = 'No up-front entitlement.';

        // 2. Group override.
        $group = $this->groupAllocation($employee, $type);

        if ($group !== null) {
            // A group allocation states the figure itself, as it always has.
            $days = (float) $group->allocated_days;
            $source = 'allocation_policy';
            $breakdown['allocation_policy_id'] = $group->id;
            $explanation = sprintf('Group allocation %s day(s)', $days);
        } elseif ($settings['accrual_method'] === LeavePolicyRule::ACCRUAL_MANUAL) {
            // The annual figure is policy metadata only; credits are posted by
            // HR. Never an automatic base, and never a withdrawn one.
            $source = 'manual';
            $explanation = sprintf(
                'Policy entitlement %s day(s) a year; credited by HR — no automatic grant schedule is configured.',
                $settings['fixed_days'] !== null ? (float) $settings['fixed_days'] : 'not stated',
            );
        } elseif ($settings['accrual_method'] !== LeavePolicyRule::ACCRUAL_UPFRONT) {
            $source = 'accrual';
            $explanation = 'Credited by '.$settings['accrual_method'].' accrual, not up front.';
        } elseif ($method === LeavePolicyRule::ENTITLEMENT_UK_ENGINE) {
            [$days, $explanation, $issues, $breakdown['working_pattern']] = $this->ukEngine($employee, $year);
            $source = 'uk_engine';
        } elseif ($method === LeavePolicyRule::ENTITLEMENT_FIXED && $settings['fixed_days'] !== null) {
            // A policy rule pro-rates a mid-year joiner by whole months. A
            // type's legacy flat figure is kept exactly as it always applied.
            $factor = $settings['rule_id'] ? $this->proRataFactor($employee, $year, $settings['joining_month_rule']) : 1.0;
            $days = $factor < 1 ? $this->roundHalf($settings['fixed_days'] * $factor) : $settings['fixed_days'];
            $source = $settings['rule_id'] ? 'policy_rule' : 'leave_type';
            $explanation = sprintf('%s day(s)%s', $settings['fixed_days'], $factor < 1 ? sprintf(', pro-rated x%s for joining mid-year', round($factor, 4)) : '');
        }

        $breakdown['before_override'] = $days;

        // 1. Employee override, on top of (or instead of) everything above.
        foreach ($this->overrides($employee, $type, $year) as $override) {
            if ($override->mode === EmployeeLeaveOverride::MODE_SET) {
                $days = (float) $override->days;
                $explanation = sprintf('Employee override: set to %s day(s)', (float) $override->days);
            } else {
                $days = round(($days ?? 0) + (float) $override->days, 2);
                $explanation .= sprintf('; employee override %+g day(s)', (float) $override->days);
            }
            $source = 'employee_override';
            $breakdown['overrides'][] = ['id' => $override->id, 'mode' => $override->mode, 'days' => (float) $override->days];
            $issues = [];
        }

        return [
            'days' => $days !== null ? round(max(0, $days), 2) : null,
            'source' => $source,
            'method' => $method,
            'explanation' => $explanation,
            'issues' => $issues,
            'breakdown' => $breakdown,
        ];
    }

    /** @return Collection<int, EmployeeLeaveOverride> */
    public function overrides(Employee $employee, LeaveType $type, LeaveYear $year): Collection
    {
        return EmployeeLeaveOverride::where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->applicableTo($year)
            ->orderBy('id')
            ->get();
    }

    /**
     * Share of the year the employee is employed for, in whole months. The
     * joining month counts in full, only when joined by the 15th
     * (half_month), or not at all.
     */
    public function proRataFactor(Employee $employee, LeaveYear $year, string $joiningMonthRule): float
    {
        if (! $employee->joining_date) {
            return 1.0;
        }

        $joined = Carbon::parse($employee->joining_date)->startOfDay();

        if ($joined->lte($year->starts_on)) {
            return 1.0;
        }

        if ($joined->gt($year->ends_on)) {
            return 0.0;
        }

        // Whole months after the joining month, to the end of the year.
        $monthsAfter = $this->monthsBetween($joined->copy()->startOfMonth()->addMonth(), $year->ends_on);

        $joiningMonthCounts = match ($joiningMonthRule) {
            LeavePolicyRule::JOINING_FULL => true,
            LeavePolicyRule::JOINING_NONE => $joined->day === 1,
            default => $joined->day <= 15,
        };

        return min(1.0, ($monthsAfter + ($joiningMonthCounts ? 1 : 0)) / 12);
    }

    /** The day a monthly/quarterly accrual may start crediting from. */
    public function accrualStart(Employee $employee, string $accrualStart): ?CarbonInterface
    {
        if ($accrualStart === LeavePolicyRule::START_CONFIRMATION) {
            $status = $employee->status instanceof EmployeeStatus ? $employee->status->value : (string) $employee->status;

            if (in_array($status, ['draft', 'onboarding', 'probation'], true)) {
                return null;
            }

            return $employee->probation_end_date ? Carbon::parse($employee->probation_end_date) : ($employee->joining_date ? Carbon::parse($employee->joining_date) : null);
        }

        return $employee->joining_date ? Carbon::parse($employee->joining_date) : Carbon::create(2000, 1, 1);
    }

    private function groupAllocation(Employee $employee, LeaveType $type): ?LeaveAllocationPolicy
    {
        $this->groupPolicies ??= LeaveAllocationPolicy::where('is_active', true)->get()->groupBy('leave_type_id')->all();
        $policies = $this->groupPolicies[$type->id] ?? collect();

        if ($policies->isEmpty()) {
            return null;
        }

        return $policies
            ->filter(fn (LeaveAllocationPolicy $p) => $this->matches($employee, $type, $p))
            ->sortByDesc(fn (LeaveAllocationPolicy $p) => $p->specificity())
            ->first();
    }

    private function matches(Employee $employee, LeaveType $type, LeaveAllocationPolicy $policy): bool
    {
        // resolveAllocation() is the existing matcher. It falls back to the
        // type's flat default when nothing matches; a probe type without one
        // makes it return null exactly when the policy does not match.
        $probe = $type->replicate();
        $probe->annual_allocation_days = null;

        return $this->balances->resolveAllocation($employee, $probe, collect([$policy])) !== null;
    }

    /** @return array{0: ?float, 1: string, 2: array<int, string>, 3: string} */
    private function ukEngine(Employee $employee, LeaveYear $year): array
    {
        $preview = app(LeaveProvisioningService::class)->preview($employee, null, $year);

        if ($preview['entitlement'] === null) {
            return [null, 'UK engine: not calculable.', $preview['issues'], $preview['pattern']];
        }

        return [(float) $preview['entitlement'], 'UK engine: '.$preview['policy_name'].', '.$preview['pattern'], [], $preview['pattern']];
    }

    private function monthsBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        if ($from->gt($to)) {
            return 0;
        }

        return ($to->year - $from->year) * 12 + ($to->month - $from->month) + 1;
    }

    private function roundHalf(float $days): float
    {
        return round($days * 2) / 2;
    }
}
