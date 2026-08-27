<?php

namespace App\Livewire\TimeOff;

use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Services\LeaveService;
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

    public int $carry_forward_limit = 0;

    public bool $allow_encashment = false;

    public ?int $max_encashable_days = null;

    public float $encashment_rate_multiplier = 1.00;

    public bool $allow_current_year_encashment = false;

    public bool $is_sandwich_applicable = false;

    public int $sandwich_min_days = 0;

    public bool $allow_half_day = true;

    // ── Accrual ──────────────────────────────────────────────────────────────
    public bool $is_monthly_accrual = false;

    public float $accrual_days_per_month = 0;

    // ── Restrictions ─────────────────────────────────────────────────────────
    public string $gender_restriction = 'none';

    public bool $probation_restricted = false;

    public bool $notice_period_restricted = false;

    public ?int $max_consecutive_days = null;

    public bool $attachment_required = false;

    // ── Allocation & control ──────────────────────────────────────────────────
    public ?float $annual_allocation_days = null;

    public bool $is_system_controlled = false;

    // ── Modal state ──────────────────────────────────────────────────────────
    public $editingId = null;

    public bool $showModal = false;

    public function openModal($id = null): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

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
        } else {
            $this->reset([
                'name', 'code', 'is_paid', 'color', 'category',
                'allow_paid_request', 'allow_unpaid_request', 'allow_hr_override', 'hr_remark_required',
                'allow_carry_forward', 'carry_forward_limit',
                'allow_encashment', 'max_encashable_days', 'encashment_rate_multiplier', 'allow_current_year_encashment',
                'is_sandwich_applicable', 'sandwich_min_days', 'allow_half_day',
                'is_monthly_accrual', 'accrual_days_per_month',
                'gender_restriction', 'probation_restricted', 'notice_period_restricted',
                'max_consecutive_days', 'attachment_required',
                'annual_allocation_days', 'is_system_controlled',
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
            'carry_forward_limit' => $this->carry_forward_limit,
            'allow_encashment' => $this->allow_encashment,
            'max_encashable_days' => $this->max_encashable_days ?: null,
            'encashment_rate_multiplier' => $this->encashment_rate_multiplier,
            'allow_current_year_encashment' => $this->allow_current_year_encashment,
            'is_sandwich_applicable' => $this->is_sandwich_applicable,
            'sandwich_min_days' => $this->is_sandwich_applicable ? $this->sandwich_min_days : 0,
            'allow_half_day' => $this->allow_half_day,
            'is_monthly_accrual' => $this->is_monthly_accrual,
            'accrual_days_per_month' => $this->accrual_days_per_month,
            'gender_restriction' => $this->gender_restriction,
            'probation_restricted' => $this->probation_restricted,
            'notice_period_restricted' => $this->notice_period_restricted,
            'max_consecutive_days' => $this->max_consecutive_days ?: null,
            'attachment_required' => $this->attachment_required,
            'annual_allocation_days' => $this->annual_allocation_days ?: null,
            'is_system_controlled' => $this->is_system_controlled,
        ], $this->editingId);

        $this->showModal = false;
        \Flux::toast('Leave type saved successfully.');
    }

    public function delete($id): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        app(LeaveService::class)->deleteLeaveType(LeaveType::findOrFail($id));
        \Flux::toast('Leave type deleted.');
    }

    public function render()
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        return view('livewire.time-off.time-off-settings', [
            // Only live types. Retired ones keep their history but must not
            // appear as something HR can still assign.
            'leaveTypes' => LeaveType::orderBy('name')->get(),
            'retiredCount' => LeaveType::onlyTrashed()->count(),
            // The carry-forward ceiling is a property of the leave policy, not
            // of the type, so the screen has to read it from there or it will
            // describe a limit that no longer decides anything.
            'defaultPolicy' => LeavePolicy::where('is_default', true)->first()
                ?? LeavePolicy::where('is_active', true)->first(),
        ])->layout('layouts.app', ['title' => 'Leave Settings']);
    }
}
