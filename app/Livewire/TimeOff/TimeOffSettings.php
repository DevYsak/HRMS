<?php

namespace App\Livewire\TimeOff;

use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Services\Leave\LeaveCategory;
use App\Services\Leave\LeavePolicyEngine;
use App\Services\LeaveService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class TimeOffSettings extends Component
{
    // ── Core fields ──────────────────────────────────────────────────────────
    public string $name = '';

    public string $code = '';

    public bool $is_paid = true;

    public string $color = '#1DB77A';

    public string $category = 'other';

    // ── Paid/Unpaid policy ───────────────────────────────────────────────────
    public bool $allow_paid_request = true;

    public bool $allow_unpaid_request = true;

    public bool $allow_hr_override = true;

    public bool $hr_remark_required = true;

    // ── Leave rules ──────────────────────────────────────────────────────────
    public bool $allow_carry_forward = false;

    /** none | hr_approval | automatic. Default matches company policy. */
    public string $carry_forward_mode = 'hr_approval';

    public int|float|string|null $carry_forward_limit = 0;

    public bool $allow_encashment = false;

    public int|float|string|null $max_encashable_days = null;

    public int|float|string|null $encashment_rate_multiplier = 1.00;

    public bool $allow_current_year_encashment = false;

    public bool $is_sandwich_applicable = false;

    public int|float|string|null $sandwich_min_days = 0;

    public bool $allow_half_day = true;

    // ── Accrual ──────────────────────────────────────────────────────────────
    public bool $is_monthly_accrual = false;

    public int|float|string|null $accrual_days_per_month = 0;

    // ── Restrictions ─────────────────────────────────────────────────────────
    public string $gender_restriction = 'none';

    public bool $probation_restricted = false;

    public bool $notice_period_restricted = false;

    public int|float|string|null $max_consecutive_days = null;

    public bool $attachment_required = false;

    // ── Allocation & control ──────────────────────────────────────────────────
    public int|float|string|null $annual_allocation_days = null;

    public bool $is_system_controlled = false;

    // ── Leave V2 ──────────────────────────────────────────────────────────────
    /** earned | special | comp_off | unpaid, or '' (only unambiguous legacy categories are derived). */
    public string $leave_category = '';

    public bool $counts_toward_annual_cap = false;

    /** Special leave only: draw on the Earned Leave balance instead of an entitlement of its own. */
    public bool $consumes_earned_leave = false;

    public string $description = '';

    public int|string|null $display_order = 0;

    public bool $is_active = true;

    /** active | historical | inactive — only an active type is offered for new requests. */
    public string $availability = LeaveType::AVAILABILITY_ACTIVE;

    // ── Modal state ──────────────────────────────────────────────────────────
    public $editingId = null;

    public bool $showModal = false;

    /** Bumped on every open so the modal is a fresh element, never a hidden leftover. */
    public int $modalVersion = 0;

    public function openModal($id = null): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        // A fresh form every time: errors from a previous attempt must not
        // reappear (or block Save) on the next open.
        $this->resetValidation();
        $this->modalVersion++;
        $this->editingId = $id;

        if ($id) {
            $type = LeaveType::findOrFail($id);
            $this->name = $type->name;
            $this->code = $type->code ?? '';
            $this->is_paid = (bool) $type->is_paid;
            $this->color = $type->color;
            $this->category = $type->category ?? 'other';
            $this->allow_paid_request = (bool) $type->allow_paid_request;
            $this->allow_unpaid_request = (bool) $type->allow_unpaid_request;
            $this->allow_hr_override = (bool) $type->allow_hr_override;
            $this->hr_remark_required = (bool) $type->hr_remark_required;
            $this->allow_carry_forward = (bool) $type->allow_carry_forward;
            $this->carry_forward_mode = $type->carry_forward_mode ?: LeaveType::CARRY_HR_APPROVAL;
            $this->carry_forward_limit = (int) $type->carry_forward_limit;
            $this->allow_encashment = (bool) $type->allow_encashment;
            $this->max_encashable_days = $type->max_encashable_days ? (int) $type->max_encashable_days : null;
            $this->encashment_rate_multiplier = (float) ($type->encashment_rate_multiplier ?? 1.00);
            $this->allow_current_year_encashment = (bool) $type->allow_current_year_encashment;
            $this->is_sandwich_applicable = (bool) $type->is_sandwich_applicable;
            $this->sandwich_min_days = (int) $type->sandwich_min_days;
            $this->allow_half_day = (bool) $type->allow_half_day;
            $this->is_monthly_accrual = (bool) $type->is_monthly_accrual;
            $this->accrual_days_per_month = (float) $type->accrual_days_per_month;
            $this->gender_restriction = $type->gender_restriction ?? 'none';
            $this->probation_restricted = (bool) $type->probation_restricted;
            $this->notice_period_restricted = (bool) $type->notice_period_restricted;
            $this->max_consecutive_days = $type->max_consecutive_days ? (int) $type->max_consecutive_days : null;
            $this->attachment_required = (bool) $type->attachment_required;
            $this->annual_allocation_days = $type->annual_allocation_days ? (float) $type->annual_allocation_days : null;
            $this->is_system_controlled = (bool) $type->is_system_controlled;
            $this->leave_category = (string) ($type->leave_category ?? '');
            $this->counts_toward_annual_cap = (bool) $type->counts_toward_annual_cap;
            $this->consumes_earned_leave = (bool) $type->consumes_earned_leave;
            $this->description = (string) ($type->description ?? '');
            $this->display_order = (int) $type->display_order;
            $this->is_active = (bool) ($type->is_active ?? true);
            $this->availability = $type->availability ?? LeaveType::AVAILABILITY_ACTIVE;
        } else {
            $this->reset([
                'name', 'code', 'is_paid', 'color', 'category',
                'allow_paid_request', 'allow_unpaid_request', 'allow_hr_override', 'hr_remark_required',
                'allow_carry_forward', 'carry_forward_mode', 'carry_forward_limit',
                'allow_encashment', 'max_encashable_days', 'encashment_rate_multiplier', 'allow_current_year_encashment',
                'is_sandwich_applicable', 'sandwich_min_days', 'allow_half_day',
                'is_monthly_accrual', 'accrual_days_per_month',
                'gender_restriction', 'probation_restricted', 'notice_period_restricted',
                'max_consecutive_days', 'attachment_required',
                'annual_allocation_days', 'is_system_controlled',
                'leave_category', 'counts_toward_annual_cap', 'consumes_earned_leave', 'description', 'display_order', 'is_active', 'availability',
            ]);
            $this->is_paid = true;
            $this->color = '#1DB77A';
            $this->category = 'other';
            $this->allow_paid_request = true;
            $this->allow_unpaid_request = true;
            $this->allow_hr_override = true;
            $this->hr_remark_required = true;
            $this->allow_half_day = true;
            $this->gender_restriction = 'none';
        }

        $this->showModal = true;
    }

    /**
     * Close and reset. The view hides the modal client-side first, so a slow
     * or failed request can never leave it stuck open.
     */
    public function closeModal(): void
    {
        $this->showModal = false;
        $this->editingId = null;
        $this->resetValidation();
    }

    public function save(): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        $this->validate([
            'name' => 'required|string|max:50',
            'code' => ['nullable', 'string', 'max:20', Rule::unique('leave_types', 'code')->ignore($this->editingId)],
            'is_paid' => 'required|boolean',
            'color' => 'required|string|size:7',
            'category' => 'required|in:annual,sick,maternity,comp_off,encashment,unpaid,other,unauthorized,bereavement,paternity,wfh,lwp,custom',
            'allow_paid_request' => 'required|boolean',
            'allow_unpaid_request' => 'required|boolean',
            'allow_hr_override' => 'required|boolean',
            'hr_remark_required' => 'required|boolean',
            'allow_carry_forward' => 'required|boolean',
            'carry_forward_mode' => 'required|in:none,hr_approval,automatic',
            'carry_forward_limit' => 'required|integer|min:0',
            'allow_encashment' => 'required|boolean',
            'max_encashable_days' => 'nullable|integer|min:1|max:365',
            'encashment_rate_multiplier' => 'required|numeric|min:0.1|max:10',
            'allow_current_year_encashment' => 'required|boolean',
            'is_sandwich_applicable' => 'required|boolean',
            'sandwich_min_days' => 'required|integer|min:0|max:31',
            'allow_half_day' => 'required|boolean',
            'is_monthly_accrual' => 'required|boolean',
            'accrual_days_per_month' => 'required|numeric|min:0|max:31',
            'gender_restriction' => 'required|in:none,male,female',
            'probation_restricted' => 'required|boolean',
            'notice_period_restricted' => 'required|boolean',
            'max_consecutive_days' => 'nullable|integer|min:1|max:365',
            'attachment_required' => 'required|boolean',
            'annual_allocation_days' => 'nullable|numeric|min:0|max:365',
            'is_system_controlled' => 'required|boolean',
            'leave_category' => 'nullable|in:earned,special,comp_off,unpaid',
            'counts_toward_annual_cap' => 'required|boolean',
            'consumes_earned_leave' => 'required|boolean',
            'description' => 'nullable|string|max:255',
            'display_order' => 'nullable|integer|min:0|max:999',
            'is_active' => 'required|boolean',
            'availability' => 'required|in:active,historical,inactive',
        ]);

        app(LeaveService::class)->saveLeaveType([
            'name' => $this->name,
            'code' => $this->code ?: null,
            'is_paid' => $this->is_paid,
            'color' => $this->color,
            'category' => $this->category,
            'allow_paid_request' => $this->allow_paid_request,
            'allow_unpaid_request' => $this->allow_unpaid_request,
            'allow_hr_override' => $this->allow_hr_override,
            'hr_remark_required' => $this->hr_remark_required,
            'allow_carry_forward' => $this->allow_carry_forward,
            'carry_forward_mode' => $this->carry_forward_mode,
            'carry_forward_limit' => (int) $this->carry_forward_limit,
            'allow_encashment' => $this->allow_encashment,
            'max_encashable_days' => $this->max_encashable_days ? (int) $this->max_encashable_days : null,
            'encashment_rate_multiplier' => (float) $this->encashment_rate_multiplier,
            'allow_current_year_encashment' => $this->allow_current_year_encashment,
            'is_sandwich_applicable' => $this->is_sandwich_applicable,
            'sandwich_min_days' => $this->is_sandwich_applicable ? (int) $this->sandwich_min_days : 0,
            'allow_half_day' => $this->allow_half_day,
            'is_monthly_accrual' => $this->is_monthly_accrual,
            'accrual_days_per_month' => (float) $this->accrual_days_per_month,
            'gender_restriction' => $this->gender_restriction,
            'probation_restricted' => $this->probation_restricted,
            'notice_period_restricted' => $this->notice_period_restricted,
            'max_consecutive_days' => $this->max_consecutive_days ? (int) $this->max_consecutive_days : null,
            'attachment_required' => $this->attachment_required,
            'annual_allocation_days' => $this->annual_allocation_days ? (float) $this->annual_allocation_days : null,
            'is_system_controlled' => $this->is_system_controlled,
            'leave_category' => $this->leave_category ?: null,
            'counts_toward_annual_cap' => $this->counts_toward_annual_cap,
            'consumes_earned_leave' => $this->consumes_earned_leave,
            'description' => $this->description ?: null,
            'display_order' => (int) $this->display_order,
            // Availability decides whether the type is offered; is_active follows it.
            'availability' => $this->availability,
            'is_active' => $this->availability === LeaveType::AVAILABILITY_ACTIVE,
        ], $this->editingId);

        $this->closeModal();
        \Flux::toast('Leave type saved successfully.');
    }

    public function delete($id): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        app(LeaveService::class)->deleteLeaveType(LeaveType::findOrFail($id));
        \Flux::toast('Leave type deleted.');
    }

    /**
     * How Leave V2 treats a type, for the settings list: its role, and whether
     * employees can request it now.
     *
     * @param  Collection<int, int>  $pools  leave types holding a policy's Earned Leave balance
     * @return array{label: string, requestable: bool, note: ?string}
     */
    public static function v2Role(LeaveType $type, Collection $pools): array
    {
        $category = LeaveCategory::of($type);
        $availability = $type->availability ?? LeaveType::AVAILABILITY_ACTIVE;

        $label = match (true) {
            $availability === LeaveType::AVAILABILITY_HISTORICAL => $type->is_system_controlled ? 'Historical / system' : 'Historical',
            $type->is_system_controlled => 'System',
            $availability === LeaveType::AVAILABILITY_INACTIVE => 'Inactive',
            $pools->contains($type->id) => 'Earned balance',
            $category === LeaveCategory::EARNED => 'Earned reason',
            $category === LeaveCategory::SPECIAL => $type->consumes_earned_leave ? 'Special · draws on Earned' : 'Special leave',
            $category === LeaveCategory::COMP_OFF => 'Comp Off',
            $category === LeaveCategory::UNPAID => 'Unpaid leave',
            default => 'Uncategorised',
        };

        return [
            'label' => $label,
            'requestable' => $type->isRequestable() && $category !== null,
            'note' => match (true) {
                $type->category === 'wfh' => 'Use the WFH module',
                $type->is_system_controlled => 'Not available for employee requests',
                default => null,
            },
        ];
    }

    public function render()
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        return view('livewire.time-off.time-off-settings', [
            // Only live types. Retired ones keep their history but must not
            // appear as something HR can still assign.
            'leaveTypes' => LeaveType::orderBy('name')->get(),
            'retiredCount' => LeaveType::onlyTrashed()->count(),
            // Leave V2: each type's role (Earned balance, Earned reason,
            // Special, Comp Off, Unpaid, Historical…) and request availability.
            'v2Enabled' => LeavePolicyEngine::enabled(),
            'earnedPools' => LeavePolicy::whereNotNull('earned_leave_type_id')->pluck('earned_leave_type_id'),
            // The carry-forward ceiling is a property of the leave policy, not
            // of the type, so the screen has to read it from there or it will
            // describe a limit that no longer decides anything.
            'defaultPolicy' => LeavePolicy::where('is_default', true)->first()
                ?? LeavePolicy::where('is_active', true)->first(),
        ])->layout('layouts.app', ['title' => 'Leave Settings']);
    }
}
