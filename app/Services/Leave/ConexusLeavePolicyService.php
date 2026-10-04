<?php

namespace App\Services\Leave;

use App\Models\DecemberMandatoryDay;
use App\Models\Employee;
use App\Models\LeaveBalance;
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
 *   CSL  Casual / Sick Leave — 12 days a year (policy metadata), half-day,
 *        unlimited carry forward that never lapses, encashable with approval.
 *        The grant schedule is NOT stated by the policy, so the rule uses
 *        accrual_method "manual": nothing is credited automatically until HR
 *        chooses upfront/monthly/quarterly.
 *   MDL  Six fixed December shutdown dates (december_mandatory_days). Never a
 *        balance; recorded on the policy as mandatory_leave_days = 6.
 *   CO   Comp Off — earned only, carries forward, no expiry, not encashable.
 *
 * The 28-day "Annual Leave" (AL) of the UK Standard policy is not Conexus
 * policy. AL is retired for new use (soft-deleted, like CL/EL before it); its
 * balances and ledger history are kept, and the register reconciliation
 * reverses the 2026/27 entitlement through the ledger.
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
    public function plan(?LeaveYear $year = null): array
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

        $csl = $this->resolveCsl();
        if ($csl['action'] === 'blocked') {
            $blocked[] = $csl['detail'];
        } else {
            $actions[] = 'CSL: '.$csl['detail'];
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
    public function apply(?User $actor = null): array
    {
        $plan = $this->plan();

        if ($plan['blocked'] !== []) {
            throw new DomainException(implode(' ', $plan['blocked']));
        }

        return DB::transaction(function () use ($actor) {
            $csl = $this->establishCsl($actor);
            $compOff = $this->configureCompOff();
            $annual = $this->retireAnnualLeave($actor);
            $policy = $this->establishPolicy($csl, $compOff);

            $ids = $this->employeesToAssign($policy)->pluck('id');
            // A query-builder update on purpose: the observer's policy-change
            // recalculation would reverse and re-post bases for every type,
            // which is exactly what the register reconciliation does
            // deliberately and visibly instead.
            Employee::query()->whereKey($ids)->update(['leave_policy_id' => $policy->id]);

            app(AuditService::class)->event('CONEXUS_LEAVE_POLICY_APPLIED', AuditService::LEAVE, $policy,
                new: ['csl_type_id' => $csl->id, 'assigned_employees' => $ids->count(), 'actor' => $actor?->email],
                reason: '12 CSL + 6 MDL + earned Comp Off; 28-day Annual Leave retired.');

            return ['csl' => $csl, 'comp_off' => $compOff, 'legacy_annual' => $annual, 'policy' => $policy, 'assigned' => $ids->count()];
        });
    }

    /**
     * Which existing type becomes CSL — never two of them.
     *
     * @return array{action: string, type_id: ?int, detail: string}
     */
    public function resolveCsl(): array
    {
        $byCode = LeaveType::withTrashed()->where('code', self::CSL_CODE)->first();
        $paid = LeaveType::whereRaw('LOWER(TRIM(name)) = ?', [self::PAID_LEAVE_NAME])->get();
        $aliases = LeaveType::whereIn(DB::raw('LOWER(TRIM(name))'), self::CSL_ALIASES)
            ->where(fn ($q) => $q->whereNull('code')->orWhere('code', '!=', self::CSL_CODE))->get();

        $candidates = collect([$byCode])->filter()->merge($paid)->merge($aliases)->unique('id')->values();

        if ($candidates->count() > 1) {
            $withBalances = $candidates->filter(fn (LeaveType $t) => LeaveBalance::where('leave_type_id', $t->id)->exists());

            if ($withBalances->count() > 1) {
                return ['action' => 'blocked', 'type_id' => null, 'detail' => 'Several types could be CSL and more than one holds balances ('
                    .$withBalances->map(fn ($t) => "#{$t->id} {$t->name}")->implode(', ').'). Choose one before reconciling.'];
            }

            $chosen = $withBalances->first() ?? $byCode ?? $candidates->first();

            return ['action' => 'rename', 'type_id' => $chosen->id, 'detail' => "use type #{$chosen->id} \"{$chosen->name}\" (it holds the balances) as CSL; other candidates hold none."];
        }

        if ($byCode) {
            return ['action' => 'keep', 'type_id' => $byCode->id, 'detail' => "type #{$byCode->id} already has code CSL".($byCode->trashed() ? ' (restored from retired)' : '').'.'];
        }

        if ($candidate = $candidates->first()) {
            return ['action' => 'rename', 'type_id' => $candidate->id, 'detail' => "rename type #{$candidate->id} \"{$candidate->name}\" to \"".self::CSL_NAME.'" (CSL), keeping its ledger history.'];
        }

        return ['action' => 'create', 'type_id' => null, 'detail' => 'create "'.self::CSL_NAME.'" (CSL).'];
    }

    private function establishCsl(?User $actor): LeaveType
    {
        $resolution = $this->resolveCsl();

        $type = $resolution['type_id'] ? LeaveType::withTrashed()->findOrFail($resolution['type_id']) : new LeaveType;

        if ($type->trashed()) {
            $type->restore();
        }

        $before = $type->exists ? $type->only(['name', 'code', 'allow_encashment', 'carry_forward_mode']) : null;

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
            // what bulk tools default to, and the policy does not grant up front.
            'annual_allocation_days' => null,
            'is_monthly_accrual' => false,
            'accrual_days_per_month' => 0,
            'is_system_controlled' => false,
            'color' => $type->color ?: '#F97316',
        ])->save();

        if ($before !== null && $before['code'] !== self::CSL_CODE) {
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

        $type->forceFill(['allow_paid_request' => false, 'allow_unpaid_request' => false, 'allow_encashment' => false])->save();
        $type->delete();

        app(AuditService::class)->event('LEAVE_TYPE_RETIRED', AuditService::LEAVE, $type,
            new: ['code' => self::LEGACY_ANNUAL_CODE, 'actor' => $actor?->email],
            reason: 'Not part of the Conexus leave policy (12 CSL + 6 MDL).');

        return $type;
    }

    private function establishPolicy(LeaveType $csl, ?LeaveType $compOff): LeavePolicy
    {
        $policy = LeavePolicy::firstOrNew(['name' => self::POLICY_NAME]);
        $policy->forceFill([
            'description' => '12 days Casual / Sick Leave a year (no lapse, unlimited carry forward, encashable with approval), '
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

        LeavePolicyRule::updateOrCreate(
            ['leave_policy_id' => $policy->id, 'leave_type_id' => $csl->id],
            [
                'entitlement_method' => LeavePolicyRule::ENTITLEMENT_FIXED,
                'fixed_days' => self::CSL_ANNUAL_DAYS,
                // The policy does not say how the 12 days are released.
                'accrual_method' => LeavePolicyRule::ACCRUAL_MANUAL,
                'accrual_amount' => null,
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
