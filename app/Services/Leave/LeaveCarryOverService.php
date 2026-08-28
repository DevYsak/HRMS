<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Carrying unused leave from one leave year into the next.
 *
 * The previous implementation had four defects, each of which silently changed
 * someone's entitlement:
 *
 *   1. It wrote the carried amount into `allocated_days`, replacing the new
 *      year's fresh entitlement instead of adding to it. An employee carrying
 *      2 days forward ended the operation with 2 days for the year rather
 *      than 30.
 *   2. It set `used_days` to 0 unconditionally, so running it twice — or
 *      running it after anyone had booked leave — erased their usage.
 *   3. It computed remaining as allocated minus used, ignoring `encashed_days`,
 *      so days already paid out were carried forward as well and counted
 *      twice.
 *   4. It worked in calendar years (`$targetYear - 1`), which cannot express a
 *      1 July to 30 June leave year at all.
 *
 * The rule here is:
 *
 *   eligible = allocated - used - encashed
 *   carry    = min(eligible, policy limit)
 *   new year = fresh entitlement + carry
 *
 * and it is idempotent: `carried_forward_days` records what was brought in, so
 * a second run recognises its own work and recalculates rather than stacking.
 */
class LeaveCarryOverService
{
    /**
     * What a carry-over run would do, without doing it.
     *
     * Preview first is the point: the old command had none, so the only way to
     * discover what it would change was to let it change it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function preview(LeaveYear $from, LeaveYear $to): Collection
    {
        $rows = collect();

        foreach ($this->eligibleEmployees() as $employee) {
            foreach ($this->carryableTypes() as $type) {
                $balance = $this->balanceFor($employee, $type, $from);

                if (! $balance) {
                    continue;
                }

                // A closed year we hold no usage figure for cannot yield an
                // eligible amount. Deriving one from used_days = 0 would offer
                // HR the whole allocation as carryable on the strength of a
                // placeholder nobody measured.
                $figuresKnown = ! $balance->used_days_unknown && ! $balance->encashed_days_unknown;

                $eligible = $figuresKnown ? $this->eligibleDays($balance) : null;
                $carry = $figuresKnown ? $this->cappedCarry($eligible, $employee) : 0.0;

                // Rows with unknown figures still surface: HR has to see the
                // recorded closing balance in order to decide an amount. Rows
                // with known figures and nothing to carry do not.
                if ($figuresKnown && $carry <= 0) {
                    continue;
                }

                $target = $this->balanceFor($employee, $type, $to);

                $rows->push([
                    // Whether the eligible figure below is a calculation or a
                    // gap. Everything downstream branches on this rather than
                    // on a zero that could mean either.
                    'figures_known' => $figuresKnown,
                    'closing_balance' => (float) $balance->allocated_days,
                    'employee_id' => $employee->id,
                    'employee' => $employee->user?->name,
                    'leave_type_id' => $type->id,
                    'leave_type' => $type->name,
                    'allocated' => (float) $balance->allocated_days,
                    'used' => (float) $balance->used_days,
                    'encashed' => (float) ($balance->encashed_days ?? 0),
                    'eligible' => $eligible,
                    // From the policy, which is canonical. null means unlimited.
                    'limit' => $employee->leavePolicy?->max_carry_over_days !== null
                        ? (float) $employee->leavePolicy->max_carry_over_days
                        : null,
                    'carry' => $carry,
                    // What the target year holds now, so the preview shows the
                    // before as well as the after.
                    'target_allocated_now' => $target ? (float) $target->allocated_days : 0.0,
                    'target_carried_now' => $target ? (float) $target->carried_forward_days : 0.0,
                    'already_applied' => $figuresKnown && $target && (float) $target->carried_forward_days === $carry,
                ]);
            }
        }

        return $rows;
    }

    /**
     * Apply the carry-over.
     *
     * Fresh entitlement is preserved: the carried days are added on top of
     * whatever the target year already allocates, and `used_days` is never
     * touched. Re-running replaces the previously carried figure rather than
     * adding to it, so the operation converges instead of compounding.
     *
     * @return array{employees:int, rows:int, days:float}
     */
    public function execute(LeaveYear $from, LeaveYear $to): array
    {
        $applied = 0;
        $days = 0.0;
        $employees = [];

        DB::transaction(function () use ($from, $to, &$applied, &$days, &$employees) {
            foreach ($this->preview($from, $to) as $row) {
                $target = LeaveBalance::firstOrNew([
                    'employee_id' => $row['employee_id'],
                    'leave_type_id' => $row['leave_type_id'],
                    'year' => $to->legacyYear(),
                ]);

                // Fresh entitlement minus whatever a previous run of this same
                // operation had added, so the base is the entitlement itself
                // and not the result of the last run.
                $fresh = (float) ($target->allocated_days ?? 0) - (float) ($target->carried_forward_days ?? 0);

                $target->leave_year_id = $to->id;
                $target->allocated_days = round($fresh + $row['carry'], 2);
                $target->carried_forward_days = $row['carry'];
                $target->used_days = (float) ($target->used_days ?? 0);
                $target->encashed_days = (float) ($target->encashed_days ?? 0);
                $target->comp_off_credits = (float) ($target->comp_off_credits ?? 0);
                $target->save();

                $applied++;
                $days += $row['carry'];
                $employees[$row['employee_id']] = true;
            }
        });

        return ['employees' => count($employees), 'rows' => $applied, 'days' => round($days, 2)];
    }

    /**
     * Days genuinely available to carry.
     *
     * Encashed days have already been paid out, so carrying them forward would
     * hand the employee the same days twice.
     */
    public function eligibleDays(LeaveBalance $balance): float
    {
        return round(max(0, (float) $balance->allocated_days
            - (float) $balance->used_days
            - (float) ($balance->encashed_days ?? 0)), 2);
    }

    /**
     * The ceiling on what may be carried, from the employee's leave policy.
     *
     * The policy is canonical. leave_types.carry_forward_limit is kept for
     * reference and deliberately not consulted: two limits for one decision is
     * two answers, and the type-level number was the older of the pair.
     *
     *   null   unlimited — no numeric ceiling
     *   0      no carry-forward permitted under this policy
     *   > 0    cap in days
     *
     * Unlimited removes the ceiling, never the approval. Nothing carries
     * without an explicit HR decision, and nothing carries more than the
     * eligible amount calculated from the previous year.
     */
    private function cappedCarry(float $eligible, Employee $employee): float
    {
        $policy = $employee->leavePolicy;

        if ($policy === null) {
            return $eligible;
        }

        $limit = $policy->max_carry_over_days;

        if ($limit === null) {
            return $eligible;
        }

        return min($eligible, (float) $limit);
    }

    private function balanceFor(Employee $employee, LeaveType $type, LeaveYear $year): ?LeaveBalance
    {
        return LeaveBalance::where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where(function ($query) use ($year) {
                // Prefer the explicit leave-year link; fall back to the legacy
                // integer for balances created before leave years existed.
                $query->where('leave_year_id', $year->id)
                    ->orWhere(function ($legacy) use ($year) {
                        $legacy->whereNull('leave_year_id')->where('year', $year->legacyYear());
                    });
            })
            ->first();
    }

    /** @return Collection<int, Employee> */
    private function eligibleEmployees(): Collection
    {
        return Employee::with(['user', 'leavePolicy'])->where('status', 'active')->get();
    }

    /** @return Collection<int, LeaveType> */
    private function carryableTypes(): Collection
    {
        return LeaveType::where('allow_carry_forward', true)->get();
    }
}
