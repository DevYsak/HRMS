<?php

namespace App\Services\Leave;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyAssignment;
use App\Models\LeavePolicyRule;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * HR changes to leave policies, their rules and assignments (Leave V2).
 *
 * A policy names the leave type that holds its one Earned Leave balance and
 * gives it the annual entitlement, accrual and carry forward. Casual, Sick,
 * Privilege… are allowed reasons with validation rules only — a reason is
 * refused any allocation of its own. Every write is audited, the Earned Leave
 * entitlement is checked against the annual paid leave cap, and assignments
 * are revoked rather than deleted. Nothing here touches a balance, a ledger
 * entry or a request.
 */
class LeavePolicyAdminService
{
    /** Validation fields a reason rule may carry (never an allocation). */
    public const REASON_FIELDS = [
        'max_consecutive_days', 'attachment_after_days', 'notice_days', 'allow_past_dates', 'past_days_limit',
        'future_days_limit', 'allow_half_day', 'probation_restricted', 'approval_workflow',
    ];

    /** Rule fields HR edits in the policy editor. */
    public const RULE_FIELDS = [
        'fixed_days', 'accrual_method', 'accrual_amount', 'carry_forward_enabled', 'carry_forward_max_days',
        'carry_forward_expiry_months', 'max_balance', 'excess_handling', 'negative_balance_limit',
        'attachment_after_days', 'notice_days', 'allow_past_dates', 'past_days_limit', 'future_days_limit',
        'allow_half_day', 'probation_restricted', 'approval_workflow', 'max_consecutive_days',
    ];

    public function __construct(
        private readonly LeavePolicyCap $cap,
        private readonly AuditService $audit,
        private readonly LeavePolicyAssignmentResolver $assignments,
        private readonly LeaveBalanceSource $sources,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function savePolicy(array $data, ?int $id, User $actor): LeavePolicy
    {
        return DB::transaction(function () use ($data, $id, $actor) {
            $policy = $id ? LeavePolicy::findOrFail($id) : new LeavePolicy([
                // UK weeks are not used by fixed-day V2 policies.
                'statutory_weeks' => 0, 'contractual_additional_weeks' => 0, 'is_default' => false,
            ]);
            $old = $policy->exists ? $policy->only(array_keys($data)) : null;

            if (! empty($data['earned_leave_type_id'])) {
                $earned = LeaveType::find($data['earned_leave_type_id']);
                if (! $earned || LeaveCategory::of($earned) !== LeaveCategory::EARNED) {
                    throw new \DomainException('The Earned Leave balance must be a leave type in the Earned Leave category.');
                }
            }

            if ($policy->exists && (array_key_exists('annual_paid_leave_cap', $data) || array_key_exists('earned_leave_type_id', $data))) {
                $probe = $policy->replicate();
                $probe->id = $policy->id;
                $probe->fill(array_intersect_key($data, array_flip(['annual_paid_leave_cap', 'earned_leave_type_id'])));
                if ($this->cap->summary($probe)['over']) {
                    throw new \DomainException('The current subtype allocations exceed this cap. Reduce them first, or set a higher cap.');
                }
            }

            $policy->fill($data)->save();

            $this->audit->event($old ? 'LEAVE_POLICY_UPDATED' : 'LEAVE_POLICY_CREATED', AuditService::LEAVE, $policy,
                old: $old, new: $policy->only(array_keys($data)), module: AuditService::SETTINGS, actor: $actor);

            return $policy;
        });
    }

    /**
     * Replace the policy's rules for the given leave types. Rows with
     * include=false deactivate that type's rule (kept, never deleted).
     *
     * The Earned Leave type's rule carries the entitlement, accrual and carry
     * forward. A reason that draws on Earned Leave (Casual, Sick…) carries
     * validation only and is refused any allocation: there is no Casual 6 +
     * Sick 6, only Earned Leave 12.
     *
     * @param  array<int, array<string, mixed>>  $rows  leave_type_id => fields (+ include)
     */
    public function saveRules(LeavePolicy $policy, array $rows, User $actor): void
    {
        $pool = $this->sources->poolOf($policy);
        $types = LeaveType::whereIn('id', array_keys($rows))->get()->keyBy('id');
        $reasons = [];
        $proposed = [];
        foreach ($rows as $typeId => $row) {
            $type = $types->get((int) $typeId);
            $isReason = $type !== null && ($pool === null || $type->id !== $pool->id) && $this->sources->isReason($type, $pool);

            if ($isReason && ! empty($row['include']) && (float) ($row['fixed_days'] ?? 0) > 0) {
                throw new \DomainException("{$type->name} is a reason that draws on Earned Leave; it cannot have its own allocation. Set the days on Earned Leave.");
            }

            $reasons[(int) $typeId] = $isReason;
            $proposed[(int) $typeId] = ! empty($row['include']) && ! $isReason ? ($row['fixed_days'] ?? 0) : 0;
        }
        $this->cap->assertWithinCap($policy, $proposed);

        DB::transaction(function () use ($policy, $rows, $actor, $reasons) {
            foreach ($rows as $typeId => $row) {
                $rule = LeavePolicyRule::firstOrNew(['leave_policy_id' => $policy->id, 'leave_type_id' => (int) $typeId]);
                $old = $rule->exists ? $rule->only(array_merge(self::RULE_FIELDS, ['is_active'])) : null;

                if (empty($row['include'])) {
                    if ($rule->exists && $rule->is_active) {
                        $rule->update(['is_active' => false]);
                        $this->audit->event('LEAVE_POLICY_RULE_DEACTIVATED', AuditService::LEAVE, $rule, old: $old, new: ['is_active' => false], module: AuditService::SETTINGS, actor: $actor);
                    }

                    continue;
                }

                if ($reasons[(int) $typeId]) {
                    // A reason: validation only, no entitlement, never a balance.
                    $rule->fill(array_intersect_key($row, array_flip(self::REASON_FIELDS)) + [
                        'entitlement_method' => LeavePolicyRule::ENTITLEMENT_NONE,
                        'fixed_days' => null,
                        'accrual_method' => LeavePolicyRule::ACCRUAL_UPFRONT,
                        'accrual_amount' => null,
                        'carry_forward_enabled' => false,
                        'carry_forward_max_days' => null,
                        'max_balance' => null,
                        'is_active' => true,
                    ])->save();
                } else {
                    $fields = array_intersect_key($row, array_flip(self::RULE_FIELDS));
                    $rule->fill($fields + [
                        'entitlement_method' => LeavePolicyRule::ENTITLEMENT_FIXED,
                        'accrual_method' => $fields['accrual_method'] ?? LeavePolicyRule::ACCRUAL_UPFRONT,
                        'is_active' => true,
                    ])->save();
                }

                $this->audit->event($old ? 'LEAVE_POLICY_RULE_UPDATED' : 'LEAVE_POLICY_RULE_CREATED', AuditService::LEAVE, $rule,
                    old: $old, new: $rule->only(array_merge(self::RULE_FIELDS, ['is_active'])), module: AuditService::SETTINGS, actor: $actor);
            }
        });
    }

    public function assign(LeavePolicy $policy, string $scope, ?int $scopeId, string $from, ?string $to, ?string $notes, User $actor): LeavePolicyAssignment
    {
        if (! in_array($scope, LeavePolicyAssignment::PRECEDENCE, true)) {
            throw new \DomainException('Unknown assignment scope.');
        }

        if ($scope === LeavePolicyAssignment::SCOPE_COMPANY) {
            $scopeId = null;
        } elseif (! $scopeId || ! ($scope === LeavePolicyAssignment::SCOPE_DEPARTMENT ? Department::whereKey($scopeId)->exists() : Employee::whereKey($scopeId)->exists())) {
            throw new \DomainException('Choose the '.($scope === LeavePolicyAssignment::SCOPE_DEPARTMENT ? 'department' : 'employee').' this policy applies to.');
        }

        if ($to !== null && $to !== '' && Carbon::parse($to)->lt(Carbon::parse($from))) {
            throw new \DomainException('The end date is before the start date.');
        }

        $assignment = LeavePolicyAssignment::create([
            'leave_policy_id' => $policy->id, 'scope' => $scope, 'scope_id' => $scopeId,
            'effective_from' => $from, 'effective_to' => $to ?: null, 'notes' => $notes ?: null, 'created_by' => $actor->id,
        ]);

        $this->assignments->flush();
        $this->audit->event('LEAVE_POLICY_ASSIGNED', AuditService::LEAVE, $assignment,
            new: $assignment->only(['leave_policy_id', 'scope', 'scope_id', 'effective_from', 'effective_to']), module: AuditService::SETTINGS, actor: $actor);

        return $assignment;
    }

    public function revoke(LeavePolicyAssignment $assignment, User $actor, ?string $reason = null): void
    {
        if ($assignment->revoked_at !== null) {
            return;
        }

        $assignment->update(['revoked_at' => now(), 'revoked_by' => $actor->id]);
        $this->assignments->flush();
        $this->audit->event('LEAVE_POLICY_ASSIGNMENT_REVOKED', AuditService::LEAVE, $assignment,
            old: ['revoked_at' => null], new: ['revoked_at' => (string) $assignment->revoked_at], reason: $reason, module: AuditService::SETTINGS, actor: $actor);
    }
}
