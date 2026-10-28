<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeavePolicyRule;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use Illuminate\Support\Collection;

/**
 * An employee's balances the way they are asked for (Leave V2):
 *
 *  - Earned Leave: ONE shared balance (the policy's Earned Leave type), with
 *    its source breakdown (EarnedLeaveBreakdown) and a usage-by-reason
 *    breakdown — Casual 2, Sick 1… — that is reporting only. Casual, Sick,
 *    Privilege… never have balances of their own.
 *  - Special Leave: statutory types with their own entitlement.
 *  - Comp Off: its own balance.
 *  - Unpaid Leave: no balance; days taken this year.
 *  - Other paid leave: a paid balance of a type that is still active but not
 *    in a V2 category — shown, never added to Earned Leave. Old balances of a
 *    reason (a standalone Sick row) or of a historical type are history only:
 *    counted, not shown as days available.
 *
 * Every figure comes from LeaveBalanceCalculator on the balance row itself.
 */
class LeaveCategoryBalances
{
    public function __construct(
        private readonly LeaveBalanceCalculator $calculator,
        private readonly LeaveRuleResolver $rules,
        private readonly LeavePolicyAssignmentResolver $assignments,
        private readonly LeavePolicyCap $cap,
        private readonly LeaveBalanceSource $sources,
        private readonly EarnedLeaveBreakdown $breakdown,
    ) {}

    /**
     * @return array{
     *     policy: ?string, policy_source: string, year: LeaveYear, annual_cap: ?float,
     *     earned: ?array<string, mixed>,
     *     reasons: array<int, array{id: int, name: string, code: ?string, description: ?string}>,
     *     special: array{available: float, credits: float, used: float, pending: float, subtypes: array<int, array<string, mixed>>},
     *     comp_off: array{available: float, credits: float, used: float, pending: float, subtypes: array<int, array<string, mixed>>},
     *     unpaid: array{used: float, pending: float, types: array<int, array{id: int, name: string, code: ?string}>},
     *     other_paid: array<int, array<string, mixed>>,
     *     historical_balances: int,
     *     historical: array<int, array<string, mixed>>
     * }
     */
    public function forEmployee(Employee $employee, LeaveYear $year): array
    {
        // The policy in force on today's date within this leave year, or on the
        // year's nearest edge for a past or future year.
        $on = now()->lt($year->starts_on) ? $year->starts_on : (now()->gt($year->ends_on) ? $year->ends_on : now());
        $policy = $this->assignments->resolve($employee, $on);
        $pool = $this->sources->poolOf($policy['policy']);
        $ruleDays = $policy['policy']
            ? LeavePolicyRule::where('leave_policy_id', $policy['policy']->id)->where('is_active', true)->pluck('fixed_days', 'leave_type_id')
            : collect();

        $balances = LeaveBalance::where('employee_id', $employee->id)
            ->where('leave_year_id', $year->id)
            ->get()
            ->keyBy('leave_type_id');

        $types = LeaveType::withTrashed()
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNull('deleted_at')->where('is_active', true)->where('availability', LeaveType::AVAILABILITY_ACTIVE))
                ->orWhereIn('id', $balances->keys()))
            ->orderBy('display_order')->orderBy('name')
            ->get()
            ->filter(fn (LeaveType $t) => $t->isGenderEligible($employee->gender))
            ->keyBy('id');

        $row = fn (LeaveType $type) => $this->row($employee, $type, $balances->get($type->id), $ruleDays, $year);

        // Special leave and Comp Off: their own balances, shown when the policy
        // grants them or a balance is held. Special leave that HR set to draw
        // on Earned Leave has no balance of its own.
        // A special type the policy names without any entitlement, and that
        // the employee holds no balance in, is not shown as "0 days".
        $own = fn (string $category) => $types
            ->filter(fn (LeaveType $t) => LeaveCategory::of($t) === $category
                && $this->sources->forPool($t, $pool)['source'] === LeaveBalanceSource::OWN
                && ($balances->has($t->id)
                    || ($ruleDays->has($t->id) && ($category === LeaveCategory::COMP_OFF || $ruleDays->get($t->id) !== null))))
            ->map($row)->values();

        $unpaidTypes = $types->filter(fn (LeaveType $t) => LeaveCategory::of($t) === LeaveCategory::UNPAID && $t->isRequestable());
        $held = $balances
            ->map(fn (LeaveBalance $b) => $types->get($b->leave_type_id) ?? LeaveType::withTrashed()->find($b->leave_type_id))
            ->filter();

        return [
            'policy' => $policy['policy']?->name,
            'policy_source' => $policy['source_label'],
            'year' => $year,
            'annual_cap' => $policy['policy'] ? $this->cap->summary($policy['policy'])['cap'] : null,
            // The canonical Earned Leave breakdown (carry forward, current year,
            // migrated opening, HR adjustments…) every screen shows.
            'earned' => $pool ? $row($pool) + [
                'usage_by_reason' => $this->usageByReason($employee, $pool, $year),
                'breakdown' => $this->breakdown->for($employee, $year, $pool),
            ] : null,
            'reasons' => $this->reasons($types, $pool, $ruleDays),
            'special' => $this->group($own(LeaveCategory::SPECIAL)),
            'comp_off' => $this->group($own(LeaveCategory::COMP_OFF)),
            'unpaid' => [
                'used' => $this->unpaidTaken($employee, $year, $unpaidTypes->pluck('id')->all()),
                'pending' => round((float) $unpaidTypes->sum(fn (LeaveType $t) => $this->calculator->pendingFor($employee->id, $t->id, $year)), 2),
                'types' => $unpaidTypes->map(fn (LeaveType $t) => ['id' => $t->id, 'name' => $t->name, 'code' => $t->code])->values()->all(),
            ],
            // A paid type still active but not in a V2 category: shown,
            // never added to Earned Leave or any other total.
            'other_paid' => $held
                ->filter(fn (LeaveType $t) => $this->isOtherPaid($t))
                ->map($row)
                ->filter(fn (array $r) => $r['credits'] != 0 || $r['used'] != 0)
                ->values()->all(),
            // Old balances of a reason (a standalone Sick row) or of a
            // historical type: kept in the leave history, never offered.
            'historical_balances' => $held
                ->filter(fn (LeaveType $t) => $this->isHistoricalBalance($t, $pool))
                ->count(),
            // The same rows, for the Historical Leave Balances list (read only).
            'historical' => $held
                ->filter(fn (LeaveType $t) => $this->isHistoricalBalance($t, $pool))
                ->map($row)
                ->filter(fn (array $r) => $r['credits'] != 0 || $r['used'] != 0)
                ->values()->all(),
        ];
    }

    /** A paid type still offered for requests that HR has not put in a V2 category. */
    public function isOtherPaid(LeaveType $type): bool
    {
        return $type->is_paid
            && $type->isRequestable()
            && LeaveCategory::of($type) === null
            && ! in_array((string) $type->category, LeaveCategory::NOT_REQUESTABLE, true);
    }

    /** A balance that is history under V2: a reason's own old balance, or a historical/retired paid type. */
    public function isHistoricalBalance(LeaveType $type, ?LeaveType $pool): bool
    {
        if (! $type->is_paid || ($pool !== null && $type->id === $pool->id)) {
            return false;
        }

        $source = $this->sources->forPool($type, $pool)['source'];

        return $source === LeaveBalanceSource::EARNED_POOL
            || $source === LeaveBalanceSource::MISSING_POOL
            || (! $type->isRequestable() && ! $type->is_system_controlled
                && ! in_array(LeaveCategory::of($type), [LeaveCategory::SPECIAL, LeaveCategory::COMP_OFF], true));
    }

    /**
     * The reasons an Earned Leave request can be given: the Earned Leave
     * reasons the policy allows. Labels only — they hold no balance.
     *
     * @return array<int, array{id: int, name: string, code: ?string, description: ?string}>
     */
    private function reasons(Collection $types, ?LeaveType $pool, Collection $ruleDays): array
    {
        if ($pool === null) {
            return [];
        }

        $reasons = $types
            ->filter(fn (LeaveType $t) => $t->isRequestable() && $t->id !== $pool->id
                && $this->sources->forPool($t, $pool)['source'] === LeaveBalanceSource::EARNED_POOL
                && $ruleDays->has($t->id))
            ->map(fn (LeaveType $t) => ['id' => $t->id, 'name' => $t->name, 'code' => $t->code, 'description' => $t->description])
            ->values()->all();

        // A policy with no reasons configured: the request is for Earned Leave itself.
        return $reasons !== [] ? $reasons : [['id' => $pool->id, 'name' => $pool->name, 'code' => $pool->code, 'description' => $pool->description]];
    }

    /** @return array<string, mixed> */
    private function row(Employee $employee, LeaveType $type, ?LeaveBalance $balance, Collection $ruleDays, LeaveYear $year): array
    {
        $summary = $balance ? $this->calculator->summary($balance) : null;
        $settings = $this->rules->settings($employee, $type, $year->starts_on);

        return [
            'id' => $type->id,
            'name' => $type->name,
            'code' => $type->code,
            'category' => LeaveCategory::of($type),
            'color' => $type->color,
            'icon' => $type->icon,
            'description' => $type->description,
            'retired' => $type->trashed(),
            'requestable' => $type->isRequestable(),
            'annual_entitlement' => $ruleDays->has($type->id) && $ruleDays->get($type->id) !== null ? (float) $ruleDays->get($type->id) : null,
            'accrual_method' => $settings['accrual_method'],
            'unlimited' => $settings['accrual_method'] === LeavePolicyRule::ACCRUAL_UNLIMITED,
            'opening' => (float) ($summary['opening'] ?? 0),
            'carry_forward' => (float) ($summary['carry_forward'] ?? 0),
            'accrued' => round((float) ($summary['base'] ?? 0) + (float) ($summary['accrued'] ?? 0), 2),
            'adjustment' => round((float) ($summary['add_on'] ?? 0) + (float) ($summary['adjustment_credit'] ?? 0) - (float) ($summary['adjustment_debit'] ?? 0), 2),
            'credits' => (float) ($summary['credits'] ?? 0),
            'used' => (float) ($summary['used'] ?? 0),
            'pending' => (float) ($summary['pending'] ?? $this->calculator->pendingFor($employee->id, $type->id, $year)),
            'approved_available' => (float) ($summary['approved_available'] ?? 0),
            'available' => (float) ($summary['available_to_request'] ?? 0),
            'has_balance' => $summary !== null,
            'leave_balance_id' => $balance?->id,
            'in_policy' => $ruleDays->has($type->id),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{available: float, credits: float, used: float, pending: float, subtypes: array<int, array<string, mixed>>}
     */
    private function group(Collection $rows): array
    {
        return [
            'available' => round((float) $rows->where('unlimited', false)->sum('available'), 2),
            'credits' => round((float) $rows->sum('credits'), 2),
            'used' => round((float) $rows->sum('used'), 2),
            'pending' => round((float) $rows->sum('pending'), 2),
            'subtypes' => $rows->all(),
        ];
    }

    /**
     * Earned Leave days by reason this leave year — usage statistics, not
     * balances. Requests charged to the Earned Leave balance, or given an
     * Earned Leave reason (a Combined request may be all unpaid), grouped by
     * reason: paid days used, unpaid days, and paid days still pending.
     *
     * @return array<int, array{id: int, name: string, code: ?string, used: float, unpaid: float, pending: float}>
     */
    public function usageByReason(Employee $employee, LeaveType $pool, LeaveYear $year): array
    {
        $paid = fn (LeaveRequest $r) => $r->effectiveLeaveStatus() === 'paid' ? $r->paidDays() : 0.0;
        $unpaid = fn (LeaveRequest $r) => $r->effectiveLeaveStatus() === 'paid' ? $r->unpaidUnits() : (float) $r->days;

        return LeaveRequest::with(['leaveType' => fn ($q) => $q->withTrashed()])
            ->where('employee_id', $employee->id)
            ->where(fn ($q) => $q->where('balance_leave_type_id', $pool->id)->orWhere('main_category', LeaveCategory::EARNED))
            ->whereIn('status', array_merge(['approved'], LeaveBalanceCalculator::RESERVING_STATUSES))
            ->whereDate('start_date', '>=', $year->starts_on->toDateString())
            ->whereDate('start_date', '<=', $year->ends_on->toDateString())
            ->get()
            ->groupBy('leave_type_id')
            ->map(fn (Collection $requests) => [
                'id' => (int) $requests->first()->leave_type_id,
                'name' => $requests->first()->leaveType?->name ?? 'Reason #'.$requests->first()->leave_type_id,
                'code' => $requests->first()->leaveType?->code,
                'used' => round((float) $requests->where('status', 'approved')->sum($paid), 2),
                'unpaid' => round((float) $requests->where('status', 'approved')->sum($unpaid), 2),
                'pending' => round((float) $requests->where('status', '!=', 'approved')->sum($paid), 2),
            ])
            ->sortBy('name')->values()->all();
    }

    /**
     * Unpaid days taken this leave year: requests of an unpaid type plus the
     * LWP portion of paid requests their balance could not cover.
     *
     * @param  array<int, int>  $unpaidTypeIds
     */
    private function unpaidTaken(Employee $employee, LeaveYear $year, array $unpaidTypeIds): float
    {
        $requests = LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '>=', $year->starts_on->toDateString())
            ->whereDate('start_date', '<=', $year->ends_on->toDateString())
            ->get(['leave_type_id', 'days', 'lwp_days']);

        return round((float) $requests->sum(fn (LeaveRequest $r) => in_array($r->leave_type_id, $unpaidTypeIds, true)
            ? (float) $r->days
            : (float) ($r->lwp_days ?? 0)), 2);
    }
}
