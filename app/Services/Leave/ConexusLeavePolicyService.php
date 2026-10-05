<?php

namespace App\Services\Leave;

use App\Models\DecemberMandatoryDay;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveCarryForwardTransaction;
use App\Models\LeaveEncashment;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyRule;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Audit\AuditService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Conexus Standard Leave Policy, as data.
 *
 *   CSL  Casual / Sick Leave — 12 days a year, earned 1 day per COMPLETED
 *        calendar month of the July–June year (HR-confirmed; posted by
 *        ConexusCslAccrualService), half-day, unlimited carry forward that
 *        never lapses, encashable with approval. The existing "Paid Leave"
 *        type — the one holding the reconciled history — becomes CSL; no
 *        second CSL balance is ever created.
 *   MDL  Six fixed December shutdown dates (december_mandatory_days). Never a
 *        balance; recorded on the policy as mandatory_leave_days = 6.
 *   CO   Comp Off — earned only, carries forward, no expiry, not encashable.
 *
 * The 28-day "Annual Leave" (AL) of the UK Standard policy is not Conexus
 * policy. AL is retired for new use (soft-deleted, like CL/EL before it); its
 * balances and ledger history are kept, and the register reconciliation
 * reverses the 2026/27 entitlement through the ledger. A separate legacy
 * Casual Leave or Sick Leave type is retired the same way: no new requests,
 * no provisioning, history untouched.
 *
 * The CSL is never created. It is the existing "Paid Leave" type — the one
 * holding the reconciled balances, carry forward, usage, ledger and
 * encashment history — renamed and reconfigured IN PLACE, so its id and every
 * row pointing at it stay exactly as they are. When no such type exists, or
 * the choice is ambiguous, the plan BLOCKS instead of creating or guessing.
 *
 * plan() reads only. apply() writes in one transaction. Both are idempotent.
 */
class ConexusLeavePolicyService
{
    public const POLICY_NAME = 'Conexus Standard Leave Policy';

    public const CSL_CODE = 'CSL';

    public const CSL_NAME = 'Casual / Sick Leave';

    public const COMP_OFF_CODE = 'CO';

    public const LEGACY_ANNUAL_CODE = LeaveProvisioningService::ANNUAL_CODE;

    public const CSL_ANNUAL_DAYS = 12.0;

    public const MDL_DAYS = 6;

    /** Earlier names the CSL type may already exist under. */
    private const CSL_ALIASES = ['casual / sick leave', 'csl (casual & sick leave)', 'casual & sick leave', 'casual/sick leave'];

    /** The type that may already hold the reconciled CSL ledger history. */
    private const PAID_LEAVE_NAME = 'paid leave';

    /**
     * Production's code for that type. Never matched on its own: the seeder
     * gives PL to Paternity Leave, so only a type NAMED Paid Leave is taken.
     */
    public const PAID_LEAVE_CODE = 'PL';

    /** Separate legacy types the CSL replaces (retired, history kept). */
    private const LEGACY_SPLIT_CODES = ['CL', 'SL'];

    private const LEGACY_SPLIT_NAMES = ['casual leave', 'sick leave'];

    public function cslType(): ?LeaveType
    {
        return LeaveType::where('code', self::CSL_CODE)->first();
    }

    public function compOffType(): ?LeaveType
    {
        return LeaveType::where('code', self::COMP_OFF_CODE)->first()
            ?? LeaveType::where('category', 'comp_off')->first();
    }

    /** The legacy Annual Leave type, retired or not. */
    public function legacyAnnualType(): ?LeaveType
    {
        return LeaveType::withTrashed()->where('code', self::LEGACY_ANNUAL_CODE)->first();
    }

    public function policy(): ?LeavePolicy
    {
        return LeavePolicy::where('name', self::POLICY_NAME)->first();
    }

    /**
     * What apply() would do, without writing.
     *
     * @return array{csl: array{action: string, type_id: ?int, detail: string}, blocked: array<int, string>, actions: array<int, string>, warnings: array<int, string>, employees_to_assign: int}
     */
    public function plan(?LeaveYear $year = null, ?int $cslTypeId = null): array
    {
        $blocked = [];
        $actions = [];
        $warnings = [];

        if ($year !== null) {
            // MDL dates are HR's to configure (Settings › Holidays); they are
            // reported here, never invented.
            $mdl = DecemberMandatoryDay::forLeaveYear($year);
            $actions[] = sprintf('MDL: %d shutdown date(s) configured for %s%s.', $mdl->count(), $year->label,
                $mdl->isNotEmpty() ? ' ('.$mdl->map(fn ($d) => $d->date->format('j M'))->implode(', ').')' : '');
            if ($mdl->count() !== self::MDL_DAYS) {
                $warnings[] = 'The policy expects '.self::MDL_DAYS." MDL dates in {$year->label}; {$mdl->count()} are configured. Add them under Settings › Holidays.";
            }
        }

        $csl = $this->resolveCsl($cslTypeId);
        if ($csl['action'] === 'blocked') {
            $blocked[] = $csl['detail'];
        } else {
            $actions[] = 'CSL: '.$csl['detail'];
            $actions[] = 'CSL: no new leave type will be created; no balance is copied or re-created.';
        }

        $actions[] = 'CSL accrual: 1 day for each completed calendar month (effective its last day), at most 12 a year; '
            .'a joining month counts only when worked in full; carry forward unlimited, no expiry.';

        if ($csl['action'] === 'rename' && $csl['type_id']
            && LeaveType::withTrashed()->whereKey($csl['type_id'])->value('code') === self::PAID_LEAVE_CODE) {
            $warnings[] = 'Code '.self::PAID_LEAVE_CODE.' is freed by the rename. LeaveTypeSeeder uses '.self::PAID_LEAVE_CODE
                .' for Paternity Leave, so running that seeder later would add Paternity Leave as its own type.';
        }

        foreach ($this->legacySplitTypes($csl['type_id']) as $split) {
            $actions[] = "{$split->name}: retire type #{$split->id} for new requests and provisioning (soft delete); its history is kept.";
            $live = LeaveBalance::where('leave_type_id', $split->id)
                ->when($year, fn ($q) => $q->where(fn ($w) => $w->where('leave_year_id', $year->id)->orWhere('year', $year->legacyYear())))
                ->get()
                ->filter(fn (LeaveBalance $b) => abs(app(LeaveBalanceCalculator::class)->summary($b)['approved_available']) > 0.005)
                ->count();
            if ($live > 0) {
                $warnings[] = "{$split->name}: {$live} balance(s) still hold days".($year ? " in {$year->label}" : '')
                    .'. They are not CSL and never count toward Available Leave; HR decides whether any should move to CSL.';
            }
            $pending = LeaveRequest::where('leave_type_id', $split->id)
                ->whereIn('status', LeaveBalanceCalculator::RESERVING_STATUSES)->count();
            if ($pending > 0) {
                $warnings[] = "{$split->name}: {$pending} request(s) are still awaiting a decision; HR must decide or re-file them as CSL.";
            }
        }

        $compOff = $this->compOffType();
        $actions[] = $compOff
            ? "Comp Off: keep type #{$compOff->id} ({$compOff->name}); earned only, carry forward, no expiry, not encashable."
            : 'Comp Off: no type found — created as CO on first credit (LeaveService::creditCompOff).';

        $annual = LeaveType::where('code', self::LEGACY_ANNUAL_CODE)->first();
        if ($annual) {
            $actions[] = "Annual Leave: retire type #{$annual->id} for new use (soft delete); balances and ledger history kept.";
            $pending = LeaveRequest::where('leave_type_id', $annual->id)
                ->whereIn('status', LeaveBalanceCalculator::RESERVING_STATUSES)->count();
            if ($pending > 0) {
                $warnings[] = "{$pending} Annual Leave request(s) are still awaiting a decision; HR must decide or re-file them as CSL.";
            }
        } else {
            $actions[] = 'Annual Leave: already retired or absent.';
        }

        $policy = $this->policy();
        $actions[] = $policy
            ? 'Policy: update "'.self::POLICY_NAME."\" (#{$policy->id}) and make it the default."
            : 'Policy: create "'.self::POLICY_NAME.'" as the default.';
        foreach (LeavePolicy::where('name', '!=', self::POLICY_NAME)->where('is_active', true)->get() as $other) {
            $actions[] = "Policy: deactivate \"{$other->name}\" (#{$other->id}) — kept for history.";
        }

        $toAssign = $this->employeesToAssign($policy)->count();
        $actions[] = "Employees: assign {$toAssign} employee(s) to the Conexus policy (no recalculation is triggered).";

        return [
            'csl' => $csl,
            'blocked' => $blocked,
            'actions' => $actions,
            'warnings' => $warnings,
            'employees_to_assign' => $toAssign,
        ];
    }

    /**
     * Put the policy in place. Idempotent; refuses when the CSL type is
     * ambiguous rather than guessing which type holds the history.
     *
     * @return array{csl: LeaveType, comp_off: ?LeaveType, legacy_annual: ?LeaveType, policy: LeavePolicy, assigned: int}
     */
    public function apply(?User $actor = null, ?int $cslTypeId = null): array
    {
        $plan = $this->plan(null, $cslTypeId);

        if ($plan['blocked'] !== []) {
            throw new DomainException(implode(' ', $plan['blocked']));
        }

        return DB::transaction(function () use ($actor, $plan) {
            $csl = $this->establishCsl($plan['csl']['type_id'], $actor);
            $compOff = $this->configureCompOff();
            $annual = $this->retireAnnualLeave($actor);
            foreach ($this->legacySplitTypes($csl->id) as $split) {
                $this->retire($split, $actor, 'Replaced by Casual / Sick Leave (CSL) under the Conexus leave policy.');
            }
            $policy = $this->establishPolicy($csl, $compOff);

            $ids = $this->employeesToAssign($policy)->pluck('id');
            // A query-builder update on purpose: the observer's policy-change
            // recalculation would reverse and re-post bases for every type,
            // which is exactly what the register reconciliation does
            // deliberately and visibly instead.
            Employee::query()->whereKey($ids)->update(['leave_policy_id' => $policy->id]);

            app(AuditService::class)->event('CONEXUS_LEAVE_POLICY_APPLIED', AuditService::LEAVE, $policy,
                new: ['csl_type_id' => $csl->id, 'assigned_employees' => $ids->count(), 'actor' => $actor?->email],
                reason: '12 CSL (1 per completed month) + 6 MDL + earned Comp Off; 28-day Annual Leave retired.');

            return ['csl' => $csl, 'comp_off' => $compOff, 'legacy_annual' => $annual, 'policy' => $policy, 'assigned' => $ids->count()];
        });
    }

    /**
     * Which EXISTING type is the CSL. Never creates one: the answer is the
     * existing "Paid Leave" type (renamed in place, id kept), or a type that
     * already carries code CSL from an earlier run — otherwise BLOCKED.
     *
     * @param  int|null  $explicitId  the type HR names (--csl-type) when the choice is ambiguous
     * @return array{action: 'keep'|'rename'|'blocked', type_id: ?int, detail: string, holdings: array<string, int>}
     */
    public function resolveCsl(?int $explicitId = null): array
    {
        $blocked = fn (string $why) => ['action' => 'blocked', 'type_id' => null, 'detail' => $why, 'holdings' => []];
        $byCode = LeaveType::withTrashed()->where('code', self::CSL_CODE)->first();
        $paid = LeaveType::withTrashed()->whereRaw('LOWER(TRIM(name)) = ?', [self::PAID_LEAVE_NAME])->get();
        $aliases = LeaveType::withTrashed()->whereIn(DB::raw('LOWER(TRIM(name))'), self::CSL_ALIASES)
            ->where(fn ($q) => $q->whereNull('code')->orWhere('code', '!=', self::CSL_CODE))->get();

        if ($explicitId !== null) {
            $type = LeaveType::withTrashed()->find($explicitId);

            if ($type === null) {
                return $blocked("There is no leave type #{$explicitId}.");
            }

            $isPaidLeave = in_array(strtolower(trim($type->name)), [self::PAID_LEAVE_NAME, ...self::CSL_ALIASES], true);
            if ($type->code !== self::CSL_CODE && ! $isPaidLeave) {
                return $blocked("Type #{$type->id} \"{$type->name}\" is not the Paid Leave type. CSL is only ever the existing Paid Leave type; maternity, paternity and other special leave are never merged into it.");
            }

            if ($byCode && $byCode->id !== $type->id) {
                return $blocked("Code CSL is already used by type #{$byCode->id} \"{$byCode->name}\"; leave_types.code is unique. Resolve that type before reconciling.");
            }

            return $this->resolved($type);
        }

        if ($byCode) {
            $others = $paid->merge($aliases)->reject(fn (LeaveType $t) => $t->id === $byCode->id);
            $holdingOthers = $others->filter(fn (LeaveType $t) => array_sum($this->holdings($t)) > 0);

            if ($holdingOthers->isNotEmpty()) {
                return $blocked("Type #{$byCode->id} already has code CSL, but ".$holdingOthers->map(fn ($t) => "#{$t->id} \"{$t->name}\"")->implode(', ')
                    .' also holds leave data. Only one type may be the CSL; HR must decide which before reconciling.');
            }

            return $this->resolved($byCode);
        }

        $candidates = $paid->isNotEmpty() ? $paid : $aliases;

        if ($candidates->count() > 1) {
            return $blocked('Several types could be the CSL ('.$candidates->map(fn ($t) => "#{$t->id} \"{$t->name}\"")->implode(', ')
                .'). Name the one holding the reconciled balances with --csl-type=<id>.');
        }

        if ($candidates->isEmpty()) {
            return $blocked('No existing "Paid Leave" type was found. The CSL must be the existing Paid Leave type that holds the reconciled balances — '
                .'this command never creates a leave type. If the type has another name, name it with --csl-type=<id>.');
        }

        return $this->resolved($candidates->first());
    }

    /**
     * What a type holds — every row that keeps pointing at its id.
     *
     * @return array{balances: int, ledger_entries: int, requests: int, encashments: int, carry_forward_transactions: int}
     */
    public function holdings(LeaveType $type): array
    {
        return [
            'balances' => LeaveBalance::where('leave_type_id', $type->id)->count(),
            'ledger_entries' => LeaveLedgerEntry::where('leave_type_id', $type->id)->count(),
            'requests' => LeaveRequest::where('leave_type_id', $type->id)->count(),
            'encashments' => LeaveEncashment::where('leave_type_id', $type->id)->count(),
            'carry_forward_transactions' => LeaveCarryForwardTransaction::where('leave_type_id', $type->id)->count(),
        ];
    }

    /** @return array{action: 'keep'|'rename', type_id: int, detail: string, holdings: array<string, int>} */
    private function resolved(LeaveType $type): array
    {
        $holdings = $this->holdings($type);
        $held = sprintf('%d balance(s), %d ledger entr(ies), %d request(s), %d encashment(s), %d carry-forward record(s) stay linked to id #%d',
            $holdings['balances'], $holdings['ledger_entries'], $holdings['requests'], $holdings['encashments'], $holdings['carry_forward_transactions'], $type->id);

        if ($type->code === self::CSL_CODE) {
            return ['action' => 'keep', 'type_id' => $type->id, 'holdings' => $holdings,
                'detail' => "type #{$type->id} \"{$type->name}\" is already the canonical CSL".($type->trashed() ? ' (restored from retired)' : '')."; {$held}."];
        }

        return ['action' => 'rename', 'type_id' => $type->id, 'holdings' => $holdings,
            'detail' => "reuse existing {$type->name} type #{$type->id}".($type->code ? " (code {$type->code})" : '')
                .' as canonical CSL — renamed in place to "'.self::CSL_NAME."\", code CSL, id unchanged; {$held}."];
    }

    private function establishCsl(?int $typeId, ?User $actor): LeaveType
    {
        if ($typeId === null) {
            throw new DomainException('No existing type was resolved as CSL; refusing to create one.');
        }

        $type = LeaveType::withTrashed()->findOrFail($typeId);

        if ($type->trashed()) {
            $type->restore();
        }

        $before = $type->only(['name', 'code', 'allow_encashment', 'carry_forward_mode']);

        $type->forceFill([
            'name' => self::CSL_NAME,
            'code' => self::CSL_CODE,
            'category' => 'annual',
            'is_paid' => true,
            'payment_mode' => 'paid',
            'allow_paid_request' => true,
            'allow_unpaid_request' => (bool) ($type->allow_unpaid_request ?? false),
            'allow_half_day' => true,
            'allow_carry_forward' => true,
            // The policy rule enables carry forward, which makes it automatic
            // and uncapped (LeaveRuleResolver::carry_forward_by_rule).
            'carry_forward_mode' => LeaveType::CARRY_AUTOMATIC,
            // Not consulted: the policy rule states the cap (none). NOT NULL column.
            'carry_forward_limit' => 0,
            'allow_encashment' => true,
            'allow_current_year_encashment' => true,
            'max_encashable_days' => null,
            // The 12 days live on the policy rule only: a type-level figure is
            // what bulk tools default to, and CSL is never granted up front.
            'annual_allocation_days' => null,
            // Earned 1 day per completed month — by ConexusCslAccrualService,
            // which the generic start-of-month accrual leaves CSL to.
            'is_monthly_accrual' => true,
            'accrual_days_per_month' => ConexusCslAccrualService::MONTHLY_CREDIT,
            'is_system_controlled' => false,
            'color' => $type->color ?: '#F97316',
        ])->save();

        if ($before['code'] !== self::CSL_CODE) {
            app(AuditService::class)->event('LEAVE_TYPE_RENAMED_TO_CSL', AuditService::LEAVE, $type,
                old: $before, new: ['name' => self::CSL_NAME, 'code' => self::CSL_CODE, 'actor' => $actor?->email]);
        }

        return $type->fresh();
    }

    private function configureCompOff(): ?LeaveType
    {
        $type = $this->compOffType();

        $type?->forceFill([
            'allow_carry_forward' => true,
            'allow_encashment' => false,
            'is_monthly_accrual' => false,
            'annual_allocation_days' => null,
        ])->save();

        return $type?->fresh();
    }

    private function retireAnnualLeave(?User $actor): ?LeaveType
    {
        $type = LeaveType::withTrashed()->where('code', self::LEGACY_ANNUAL_CODE)->first();

        if ($type === null || $type->trashed()) {
            return $type;
        }

        return $this->retire($type, $actor, 'Not part of the Conexus leave policy (12 CSL + 6 MDL).');
    }

    /**
     * Retire a type for new use: no requests, no encashment, no provisioning
     * (soft delete). Every balance, request and ledger entry keeps pointing
     * at it, and history screens load it withTrashed().
     */
    private function retire(LeaveType $type, ?User $actor, string $reason): LeaveType
    {
        $type->forceFill([
            'allow_paid_request' => false, 'allow_unpaid_request' => false, 'allow_encashment' => false,
            'is_monthly_accrual' => false, 'accrual_days_per_month' => 0,
        ])->save();
        $type->delete();

        app(AuditService::class)->event('LEAVE_TYPE_RETIRED', AuditService::LEAVE, $type,
            new: ['code' => $type->code, 'actor' => $actor?->email], reason: $reason);

        return $type;
    }

    /**
     * A separate Casual Leave / Sick Leave type still open for use — never the
     * one chosen as CSL.
     *
     * @return Collection<int, LeaveType>
     */
    private function legacySplitTypes(?int $cslTypeId): Collection
    {
        return LeaveType::query()
            ->where(fn ($q) => $q->whereIn('code', self::LEGACY_SPLIT_CODES)
                ->orWhereIn(DB::raw('LOWER(TRIM(name))'), self::LEGACY_SPLIT_NAMES))
            ->when($cslTypeId, fn ($q, $id) => $q->whereKeyNot($id))
            ->get();
    }

    private function establishPolicy(LeaveType $csl, ?LeaveType $compOff): LeavePolicy
    {
        $policy = LeavePolicy::firstOrNew(['name' => self::POLICY_NAME]);
        $policy->forceFill([
            'description' => '12 days Casual / Sick Leave a year, earned 1 day per completed month (no lapse, unlimited carry forward, encashable with approval), '
                .'6 fixed December shutdown days (MDL), and Comp Off earned by working an MDL day or applicable public holiday.',
            'statutory_weeks' => 0,
            'contractual_additional_weeks' => 0,
            'bank_holiday_treatment' => LeavePolicy::BANK_HOLIDAYS_ADDITIONAL,
            'max_carry_over_days' => null,
            'carry_over_expiry_months' => null,
            'irregular_accrual_rate' => 0,
            'mandatory_leave_days' => self::MDL_DAYS,
            'is_default' => true,
            'is_active' => true,
        ])->save();

        LeavePolicy::where('id', '!=', $policy->id)->update(['is_default' => false, 'is_active' => false]);

        $rule = LeavePolicyRule::firstOrNew(['leave_policy_id' => $policy->id, 'leave_type_id' => $csl->id]);
        // The joining month: HR's rule if one was set once monthly accrual
        // existed. A new rule — or one written while CSL was still manual,
        // which only ever carried the column default — gets the confirmed
        // fallback: a month is earned only when worked in full.
        $joiningRule = $rule->exists && $rule->accrual_method !== LeavePolicyRule::ACCRUAL_MANUAL && $rule->joining_month_rule
            ? $rule->joining_month_rule
            : LeavePolicyRule::JOINING_NONE;

        LeavePolicyRule::updateOrCreate(
            ['leave_policy_id' => $policy->id, 'leave_type_id' => $csl->id],
            [
                'entitlement_method' => LeavePolicyRule::ENTITLEMENT_FIXED,
                'fixed_days' => self::CSL_ANNUAL_DAYS,
                // 1 day per completed month (ConexusCslAccrualService).
                'accrual_method' => LeavePolicyRule::ACCRUAL_MONTHLY,
                'accrual_amount' => ConexusCslAccrualService::MONTHLY_CREDIT,
                'joining_month_rule' => $joiningRule,
                'carry_forward_enabled' => true,
                'carry_forward_max_days' => null,
                'carry_forward_percent' => null,
                'carry_forward_expiry_months' => null,
                'carry_forward_expiry_date' => null,
                'max_balance' => null,
                'allow_half_day' => true,
                'is_active' => true,
            ],
        );

        if ($compOff) {
            LeavePolicyRule::updateOrCreate(
                ['leave_policy_id' => $policy->id, 'leave_type_id' => $compOff->id],
                [
                    'entitlement_method' => LeavePolicyRule::ENTITLEMENT_NONE,
                    'fixed_days' => null,
                    'accrual_method' => LeavePolicyRule::ACCRUAL_MANUAL,
                    'carry_forward_enabled' => true,
                    'carry_forward_max_days' => null,
                    'carry_forward_percent' => null,
                    'carry_forward_expiry_months' => null,
                    'carry_forward_expiry_date' => null,
                    'max_balance' => null,
                    'is_active' => true,
                ],
            );
        }

        return $policy->fresh();
    }

    /** @return Collection<int, Employee> */
    private function employeesToAssign(?LeavePolicy $policy): Collection
    {
        return Employee::query()
            ->when($policy, fn ($q) => $q->where(fn ($w) => $w->whereNull('leave_policy_id')->orWhere('leave_policy_id', '!=', $policy->id)))
            ->get(['id']);
    }
}
