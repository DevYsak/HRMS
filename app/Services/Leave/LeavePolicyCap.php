<?php

namespace App\Services\Leave;

use App\Models\LeavePolicy;
use App\Models\LeavePolicyRule;
use App\Models\LeaveType;

/**
 * The annual paid leave cap of a policy (Leave V2).
 *
 * The standard paid allowance is ONE Earned Leave balance, so the cap is
 * checked against the Earned Leave entitlement:
 *
 *     Earned Leave 12 = 12 / 12          (ok)
 *     Earned Leave 14 = 14 / 12          (over by 2: refused)
 *
 * Casual, Sick, Privilege… are reasons that draw on Earned Leave and are
 * never allocated days of their own, so they never appear here. Any other
 * own-balance type HR flags as counting toward the cap is added; Comp Off,
 * special and unpaid types are not flagged.
 */
class LeavePolicyCap
{
    /**
     * @param  array<int, float|int|string|null>|null  $proposed  leave_type_id => annual days, to validate unsaved edits
     * @return array{cap: ?float, allocated: float, remaining: ?float, over: bool, rows: array<int, array{leave_type_id: int, name: string, code: ?string, days: float}>}
     */
    public function summary(LeavePolicy $policy, ?array $proposed = null): array
    {
        $sources = app(LeaveBalanceSource::class);
        // The policy as given — the editor passes unsaved changes.
        $pool = $policy->earned_leave_type_id ? LeaveType::find($policy->earned_leave_type_id) : null;

        $rules = LeavePolicyRule::with('leaveType')
            ->where('leave_policy_id', $policy->id)
            ->where('is_active', true)
            ->get()
            ->keyBy('leave_type_id');

        $typeIds = collect($rules->keys())->merge(array_keys($proposed ?? []))->unique();
        $types = LeaveType::whereIn('id', $typeIds)->get()->keyBy('id');

        $rows = [];
        foreach ($typeIds as $typeId) {
            $type = $types->get($typeId);
            // The Earned Leave balance always counts; a reason never does.
            $isPool = $pool !== null && $type?->id === $pool->id;
            if (! $type || (! $isPool && ($sources->isReason($type, $pool) || ! $type->counts_toward_annual_cap))) {
                continue;
            }

            $days = $proposed !== null && array_key_exists($typeId, $proposed)
                ? (float) ($proposed[$typeId] ?? 0)
                : (float) ($rules->get($typeId)?->fixed_days ?? 0);

            $rows[] = ['leave_type_id' => (int) $typeId, 'name' => $type->name, 'code' => $type->code, 'days' => round($days, 2)];
        }

        $allocated = round(array_sum(array_column($rows, 'days')), 2);
        $cap = $policy->annual_paid_leave_cap !== null ? (float) $policy->annual_paid_leave_cap : null;

        return [
            'cap' => $cap,
            'allocated' => $allocated,
            'remaining' => $cap !== null ? round($cap - $allocated, 2) : null,
            'over' => $cap !== null && $allocated > $cap + 0.001,
            'rows' => $rows,
        ];
    }

    /**
     * Refuse a set of allocations that exceeds the policy's cap.
     *
     * @param  array<int, float|int|string|null>  $proposed
     */
    public function assertWithinCap(LeavePolicy $policy, array $proposed): void
    {
        $summary = $this->summary($policy, $proposed);

        if ($summary['over']) {
            throw new \DomainException(sprintf(
                'Paid leave allocations (the Earned Leave entitlement) total %s days, more than the policy\'s annual paid leave cap of %s. Reduce the allocations or raise the cap.',
                $summary['allocated'] + 0, $summary['cap'] + 0,
            ));
        }
    }
}
