<?php

namespace App\Livewire\TimeOff;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyAssignment;
use App\Models\LeavePolicyRule;
use App\Models\LeaveType;
use App\Services\Leave\LeaveBalanceSource;
use App\Services\Leave\LeaveCategory;
use App\Services\Leave\LeavePolicyAdminService;
use App\Services\Leave\LeavePolicyCap;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * HR → Leave Management → Leave Policies (Leave V2).
 *
 *  - The policy: name, annual paid leave cap, policy year, and the leave type
 *    that holds its ONE Earned Leave balance.
 *  - Earned Leave: annual entitlement, accrual, carry forward and excess
 *    handling — checked against the cap (Earned Leave 12 / 12).
 *  - Allowed paid leave reasons (Casual, Sick, Privilege, Personal, Other…):
 *    a tick plus optional attachment, approval, consecutive-day and notice
 *    rules. Never a balance or an allocation.
 *  - Other leave types with their own balance (special leave, Comp Off).
 *  - Where the policy applies (company, department or one employee).
 *
 * Writes go through LeavePolicyAdminService (cap-checked and audited).
 */
class LeavePolicyEditor extends Component
{
    #[Url(as: 'policy')]
    public ?int $policyId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var array<int, array<string, mixed>> leave_type_id => rule fields, for types with their own balance */
    public array $rules = [];

    /** @var array<string, mixed> The Earned Leave balance's rule: entitlement, accrual, carry forward. */
    public array $earned = [];

    /** @var array<int, array<string, mixed>> leave_type_id => allowed + validation rules, for Earned Leave reasons */
    public array $reasons = [];

    /** @var array<string, mixed> */
    public array $assign = ['scope' => 'company', 'scope_id' => null, 'effective_from' => '', 'effective_to' => '', 'notes' => ''];

    public function mount(): void
    {
        $this->authorizeAccess();
        $this->policyId ??= LeavePolicy::query()->where('is_active', true)->orderByDesc('is_default')->value('id');
        $this->load();
    }

    public function selectPolicy(?int $id): void
    {
        $this->policyId = $id;
        $this->resetErrorBag();
        $this->load();
    }

    public function newPolicy(): void
    {
        $this->selectPolicy(null);
    }

    public function savePolicy(LeavePolicyAdminService $admin): void
    {
        $this->authorizeAccess();
        $this->validate([
            'form.name' => 'required|string|max:120',
            'form.annual_paid_leave_cap' => 'nullable|numeric|min:0|max:365',
            'form.policy_year_type' => 'required|in:jul_jun,jan_dec,apr_mar,anniversary,custom',
            'form.policy_year_start_month' => 'nullable|required_if:form.policy_year_type,custom|integer|min:1|max:12',
            'form.effective_from' => 'nullable|date',
            'form.effective_to' => 'nullable|date|after_or_equal:form.effective_from',
            'form.is_active' => 'boolean',
            'form.earned_leave_type_id' => 'nullable|integer|exists:leave_types,id',
        ], [], ['form.name' => 'policy name', 'form.annual_paid_leave_cap' => 'annual paid leave cap', 'form.earned_leave_type_id' => 'Earned Leave balance']);

        try {
            $policy = $admin->savePolicy([
                'name' => $this->form['name'],
                'description' => $this->form['description'] ?: null,
                'annual_paid_leave_cap' => $this->form['annual_paid_leave_cap'] === '' ? null : $this->form['annual_paid_leave_cap'],
                'policy_year_type' => $this->form['policy_year_type'],
                'policy_year_start_month' => $this->form['policy_year_start_month'] ?: null,
                'effective_from' => $this->form['effective_from'] ?: null,
                'effective_to' => $this->form['effective_to'] ?: null,
                'is_active' => (bool) $this->form['is_active'],
                'earned_leave_type_id' => $this->form['earned_leave_type_id'] ?: null,
            ], $this->policyId, Auth::user());
        } catch (\DomainException $e) {
            $this->addError('form.annual_paid_leave_cap', $e->getMessage());

            return;
        }

        $this->policyId = $policy->id;
        $this->load();
        \Flux::toast('Policy saved.', variant: 'success');
    }

    public function saveRules(LeavePolicyAdminService $admin): void
    {
        $this->authorizeAccess();
        abort_unless($this->policyId, 404);

        $this->validate([
            'rules.*.fixed_days' => 'nullable|numeric|min:0|max:365',
            'rules.*.accrual_method' => 'required|in:annual_upfront,monthly,quarterly,half_yearly,one_time,manual,unlimited',
            'rules.*.accrual_amount' => 'nullable|numeric|min:0|max:365',
            'rules.*.carry_forward_max_days' => 'nullable|numeric|min:0|max:365',
            'rules.*.max_balance' => 'nullable|numeric|min:0|max:999',
            'rules.*.excess_handling' => 'required|in:block,convert_to_lwp',
            'rules.*.attachment_after_days' => 'nullable|numeric|min:0.5|max:365',
            'rules.*.notice_days' => 'nullable|integer|min:0|max:365',
            'rules.*.hr_after_days' => 'nullable|numeric|min:0.5|max:365',
            'earned.fixed_days' => 'nullable|numeric|min:0|max:365',
            'earned.accrual_method' => 'nullable|in:annual_upfront,monthly,quarterly,half_yearly,one_time,manual,unlimited',
            'earned.accrual_amount' => 'nullable|numeric|min:0|max:365',
            'earned.carry_forward_max_days' => 'nullable|numeric|min:0|max:365',
            'earned.excess_handling' => 'nullable|in:block,convert_to_lwp',
            'reasons.*.attachment_after_days' => 'nullable|numeric|min:0.5|max:365',
            'reasons.*.max_consecutive_days' => 'nullable|integer|min:1|max:365',
            'reasons.*.notice_days' => 'nullable|integer|min:0|max:365',
            'reasons.*.hr_after_days' => 'nullable|numeric|min:0.5|max:365',
        ], [], ['earned.fixed_days' => 'Earned Leave annual entitlement']);

        $blank = fn ($v) => $v === '' || $v === null ? null : $v;
        $workflow = fn ($hrAfter) => $blank($hrAfter) !== null
            ? ['steps' => ['manager'], 'conditions' => [['min_days' => (float) $hrAfter, 'steps' => ['manager', 'hr']]]]
            : ['steps' => ['manager']];

        $rows = collect($this->rules)->map(fn (array $r) => [
            'include' => (bool) $r['include'],
            'fixed_days' => $blank($r['fixed_days']),
            'accrual_method' => $r['accrual_method'],
            'accrual_amount' => $blank($r['accrual_amount']),
            'carry_forward_enabled' => (bool) $r['carry_forward_enabled'],
            'carry_forward_max_days' => $blank($r['carry_forward_max_days']),
            'max_balance' => $blank($r['max_balance']),
            'excess_handling' => $r['excess_handling'],
            'attachment_after_days' => $blank($r['attachment_after_days']),
            'notice_days' => $blank($r['notice_days']),
            'allow_half_day' => (bool) $r['allow_half_day'],
            'approval_workflow' => $workflow($r['hr_after_days']),
        ])->all();

        // The one Earned Leave balance: entitlement, accrual, carry forward.
        if ($pool = $this->poolType) {
            $rows[$pool->id] = [
                'include' => true,
                'fixed_days' => $blank($this->earned['fixed_days']),
                'accrual_method' => $this->earned['accrual_method'] ?: LeavePolicyRule::ACCRUAL_UPFRONT,
                'accrual_amount' => $blank($this->earned['accrual_amount']),
                'carry_forward_enabled' => (bool) $this->earned['carry_forward_enabled'],
                'carry_forward_max_days' => $blank($this->earned['carry_forward_max_days']),
                'max_balance' => null,
                'excess_handling' => $this->earned['excess_handling'] ?: 'block',
                'allow_half_day' => true,
                'approval_workflow' => ['steps' => ['manager']],
            ];
        }

        // Reasons: allowed or not, plus validation. Never an allocation.
        foreach ($this->reasons as $typeId => $r) {
            $rows[$typeId] = [
                'include' => (bool) $r['include'],
                'attachment_after_days' => $blank($r['attachment_after_days']),
                'max_consecutive_days' => $blank($r['max_consecutive_days']),
                'notice_days' => $blank($r['notice_days']),
                'allow_half_day' => (bool) $r['allow_half_day'],
                'approval_workflow' => $workflow($r['hr_after_days']),
            ];
        }

        try {
            $admin->saveRules(LeavePolicy::findOrFail($this->policyId), $rows, Auth::user());
        } catch (\DomainException $e) {
            $this->addError('rules', $e->getMessage());

            return;
        }

        $this->load();
        \Flux::toast('Leave rules saved.', variant: 'success');
    }

    public function assignPolicy(LeavePolicyAdminService $admin): void
    {
        $this->authorizeAccess();
        abort_unless($this->policyId, 404);
        $this->validate([
            'assign.scope' => 'required|in:company,department,employee',
            'assign.effective_from' => 'required|date',
            'assign.effective_to' => 'nullable|date',
            'assign.notes' => 'nullable|string|max:255',
        ], [], ['assign.effective_from' => 'start date']);

        try {
            $admin->assign(
                LeavePolicy::findOrFail($this->policyId), $this->assign['scope'],
                $this->assign['scope_id'] ? (int) $this->assign['scope_id'] : null,
                $this->assign['effective_from'], $this->assign['effective_to'] ?: null, $this->assign['notes'] ?: null, Auth::user(),
            );
        } catch (\DomainException $e) {
            $this->addError('assign', $e->getMessage());

            return;
        }

        $this->assign = ['scope' => 'company', 'scope_id' => null, 'effective_from' => '', 'effective_to' => '', 'notes' => ''];
        unset($this->assignments);
        \Flux::toast('Policy assigned.', variant: 'success');
    }

    public function revoke(int $assignmentId, LeavePolicyAdminService $admin): void
    {
        $this->authorizeAccess();
        $admin->revoke(LeavePolicyAssignment::where('leave_policy_id', $this->policyId)->findOrFail($assignmentId), Auth::user());
        unset($this->assignments);
        \Flux::toast('Assignment revoked.');
    }

    #[Computed]
    public function policies(): Collection
    {
        return LeavePolicy::orderByDesc('is_active')->orderBy('name')->get();
    }

    /** Requestable leave types: every type HR has put in a category. */
    #[Computed]
    public function types(): Collection
    {
        return LeaveType::orderBy('display_order')->orderBy('name')->get()
            ->filter(fn (LeaveType $t) => LeaveCategory::of($t) !== null)
            ->values();
    }

    /** Types that can hold the Earned Leave balance (Earned Leave category). */
    #[Computed]
    public function earnedTypes(): Collection
    {
        return $this->types->filter(fn (LeaveType $t) => LeaveCategory::of($t) === LeaveCategory::EARNED)->values();
    }

    /** The saved policy's Earned Leave balance type. */
    #[Computed]
    public function poolType(): ?LeaveType
    {
        return app(LeaveBalanceSource::class)->poolOf($this->policyId ? LeavePolicy::find($this->policyId) : null);
    }

    /** Reasons that draw on Earned Leave: Casual, Sick… (and special leave set to draw on it). */
    #[Computed]
    public function reasonTypes(): Collection
    {
        $pool = $this->poolType;

        return $this->types->filter(fn (LeaveType $t) => $pool !== null && $t->id !== $pool->id
            && app(LeaveBalanceSource::class)->forPool($t, $pool)['source'] === LeaveBalanceSource::EARNED_POOL)->values();
    }

    /** Types with a balance of their own besides Earned Leave: special leave, Comp Off, unpaid. */
    #[Computed]
    public function otherTypes(): Collection
    {
        $exclude = $this->reasonTypes->pluck('id')->push($this->poolType?->id)
            // Without an Earned Leave balance yet, Earned types are still reasons-to-be: never allocated.
            ->merge($this->poolType ? [] : $this->earnedTypes->pluck('id'));

        return $this->types->reject(fn (LeaveType $t) => $exclude->contains($t->id))->values();
    }

    /** Live cap check over the unsaved form values. */
    #[Computed]
    public function capSummary(): ?array
    {
        if (! $this->policyId) {
            return null;
        }

        $policy = LeavePolicy::find($this->policyId);
        if (! $policy) {
            return null;
        }

        $policy->annual_paid_leave_cap = $this->form['annual_paid_leave_cap'] === '' ? null : $this->form['annual_paid_leave_cap'];
        $proposed = collect($this->rules)->map(fn ($r) => $r['include'] ? ($r['fixed_days'] === '' ? 0 : $r['fixed_days']) : 0)->all();
        if ($pool = $this->poolType) {
            $proposed[$pool->id] = ($this->earned['fixed_days'] ?? '') === '' ? 0 : $this->earned['fixed_days'];
        }

        return app(LeavePolicyCap::class)->summary($policy, $proposed);
    }

    #[Computed]
    public function assignments(): Collection
    {
        return $this->policyId
            ? LeavePolicyAssignment::where('leave_policy_id', $this->policyId)->latest('effective_from')->latest('id')->get()
            : collect();
    }

    #[Computed]
    public function departments(): Collection
    {
        return Department::orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function employees(): Collection
    {
        return Employee::with('user:id,name')->whereNotIn('status', ['inactive', 'archived'])->get(['id', 'user_id', 'employee_id'])
            ->sortBy(fn ($e) => $e->user?->name)->values();
    }

    public function assignmentLabel(LeavePolicyAssignment $a): string
    {
        return match ($a->scope) {
            LeavePolicyAssignment::SCOPE_DEPARTMENT => 'Department: '.($this->departments->firstWhere('id', $a->scope_id)?->name ?? '#'.$a->scope_id),
            LeavePolicyAssignment::SCOPE_EMPLOYEE => 'Employee: '.($this->employees->firstWhere('id', $a->scope_id)?->user?->name ?? '#'.$a->scope_id),
            default => 'Whole company',
        };
    }

    public function render()
    {
        $this->authorizeAccess();

        return view('livewire.time-off.leave-policy-editor')->layout('layouts.app', ['title' => 'Leave Policies']);
    }

    private function load(): void
    {
        $policy = $this->policyId ? LeavePolicy::find($this->policyId) : null;

        $this->form = [
            'name' => $policy?->name ?? '',
            'description' => $policy?->description ?? '',
            'annual_paid_leave_cap' => $policy?->annual_paid_leave_cap !== null ? (float) $policy->annual_paid_leave_cap : '',
            'policy_year_type' => $policy?->policy_year_type ?? 'jul_jun',
            'policy_year_start_month' => $policy?->policy_year_start_month ?? '',
            'effective_from' => $policy?->effective_from?->toDateString() ?? '',
            'effective_to' => $policy?->effective_to?->toDateString() ?? '',
            'is_active' => $policy?->is_active ?? true,
            'earned_leave_type_id' => $policy?->earned_leave_type_id ?? '',
        ];
        unset($this->poolType, $this->reasonTypes, $this->otherTypes);

        $existing = $policy ? LeavePolicyRule::where('leave_policy_id', $policy->id)->get()->keyBy('leave_type_id') : collect();

        $pool = $this->poolType;
        $poolRule = $pool ? $existing->get($pool->id) : null;
        $this->earned = [
            'fixed_days' => $poolRule?->fixed_days !== null ? (float) $poolRule->fixed_days : '',
            'accrual_method' => $poolRule?->accrual_method ?? LeavePolicyRule::ACCRUAL_MONTHLY,
            'accrual_amount' => $poolRule?->accrual_amount !== null ? (float) $poolRule->accrual_amount : '',
            'carry_forward_enabled' => (bool) ($poolRule?->carry_forward_enabled ?? false),
            'carry_forward_max_days' => $poolRule?->carry_forward_max_days !== null ? (float) $poolRule->carry_forward_max_days : '',
            'excess_handling' => $poolRule?->excess_handling ?? 'block',
        ];

        $hrAfterOf = fn (?LeavePolicyRule $r) => collect($r?->approval_workflow['conditions'] ?? [])->first(fn ($c) => in_array('hr', $c['steps'] ?? [], true))['min_days'] ?? '';

        $this->reasons = $this->reasonTypes->mapWithKeys(function (LeaveType $type) use ($existing, $hrAfterOf) {
            /** @var LeavePolicyRule|null $r */
            $r = $existing->get($type->id);

            return [$type->id => [
                'include' => (bool) ($r?->is_active ?? false),
                'attachment_after_days' => $r?->attachment_after_days !== null ? (float) $r->attachment_after_days : '',
                'max_consecutive_days' => $r?->max_consecutive_days ?? '',
                'notice_days' => $r?->notice_days ?? '',
                'allow_half_day' => (bool) ($r?->allow_half_day ?? $type->allow_half_day),
                'hr_after_days' => $hrAfterOf($r),
            ]];
        })->all();

        $this->rules = $this->otherTypes->mapWithKeys(function (LeaveType $type) use ($existing) {
            /** @var LeavePolicyRule|null $r */
            $r = $existing->get($type->id);
            $workflow = $r?->approval_workflow ?? [];
            $hrAfter = collect($workflow['conditions'] ?? [])->first(fn ($c) => in_array('hr', $c['steps'] ?? [], true))['min_days'] ?? '';

            return [$type->id => [
                'include' => (bool) ($r?->is_active ?? false),
                'fixed_days' => $r?->fixed_days !== null ? (float) $r->fixed_days : '',
                'accrual_method' => $r?->accrual_method ?? LeavePolicyRule::ACCRUAL_UPFRONT,
                'accrual_amount' => $r?->accrual_amount !== null ? (float) $r->accrual_amount : '',
                'carry_forward_enabled' => (bool) ($r?->carry_forward_enabled ?? false),
                'carry_forward_max_days' => $r?->carry_forward_max_days !== null ? (float) $r->carry_forward_max_days : '',
                'max_balance' => $r?->max_balance !== null ? (float) $r->max_balance : '',
                'excess_handling' => $r?->excess_handling ?? 'block',
                'attachment_after_days' => $r?->attachment_after_days !== null ? (float) $r->attachment_after_days : '',
                'notice_days' => $r?->notice_days ?? '',
                'allow_half_day' => (bool) ($r?->allow_half_day ?? $type->allow_half_day),
                'hr_after_days' => $hrAfter,
            ]];
        })->all();

        unset($this->capSummary, $this->assignments);
    }

    private function authorizeAccess(): void
    {
        abort_unless(Auth::user()?->canManageSettings(), 403);
    }
}
