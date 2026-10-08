<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\RequiresPayrollModule;
use App\Models\PayrollApprovalPolicy;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Admin config for the payroll multi-step approval chain (Phase 8). Deliberately
 * gated by manage-settings (hr_admin/super_admin) rather than run_payroll —
 * finance also holds run_payroll and is a participant in the chain being
 * configured, so it shouldn't be able to edit/reassign its own approval steps.
 */
class ApprovalPolicySettings extends Component
{
    use RequiresPayrollModule;

    private const FINANCE_REQUIRED = 'The approval chain must keep an active Finance step — payroll cannot be finalised without Finance sign-off.';

    private const APPROVER_CANNOT_APPROVE = 'This approver cannot open Finance Approval. Grant Finance Approval or Run Payroll in Roles & Permissions first.';

    public bool $showModal = false;

    public ?int $editingId = null;

    public string $label = '';

    public string $approver_type = 'hr_admin';

    public ?int $specific_user_id = null;

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorize('manage-settings');
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $policy = PayrollApprovalPolicy::findOrFail($id);
        $this->editingId = $id;
        $this->label = $policy->label;
        $this->approver_type = $policy->approver_type;
        $this->specific_user_id = $policy->specific_user_id;
        $this->is_active = $policy->is_active;
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    public function save(): void
    {
        $this->authorize('manage-settings');

        $data = $this->validate([
            'label' => ['required', 'string', 'max:100'],
            'approver_type' => ['required', Rule::in(['hr_admin', 'finance', 'director', 'super_admin', 'specific_user'])],
            'specific_user_id' => ['nullable', Rule::requiredIf($this->approver_type === 'specific_user'), 'exists:users,id'],
            'is_active' => ['boolean'],
        ]);

        if ($data['approver_type'] !== 'specific_user') {
            $data['specific_user_id'] = null;
        }

        if (($data['is_active'] ?? true) && ! $this->approverCanOpenApprovals($data['approver_type'], $data['specific_user_id'])) {
            $this->addError('approver_type', self::APPROVER_CANNOT_APPROVE);

            return;
        }

        $editing = $this->editingId;
        $kept = $this->keepingFinanceSignOff(function () use ($data, $editing) {
            if ($editing) {
                PayrollApprovalPolicy::findOrFail($editing)->update($data);
            } else {
                PayrollApprovalPolicy::create($data + ['level' => (PayrollApprovalPolicy::max('level') ?? 0) + 1]);
            }

            PayrollApprovalPolicy::renumber();
        });

        if (! $kept) {
            $this->addError('approver_type', self::FINANCE_REQUIRED);

            return;
        }

        \Flux::toast($editing ? 'Approval step updated.' : 'Approval step added.', variant: 'success');
        $this->closeModal();
    }

    public function delete(int $id): void
    {
        $this->authorize('manage-settings');

        $kept = $this->keepingFinanceSignOff(function () use ($id) {
            PayrollApprovalPolicy::findOrFail($id)->delete();
            PayrollApprovalPolicy::renumber();
        });

        \Flux::toast($kept ? 'Approval step removed.' : self::FINANCE_REQUIRED, variant: $kept ? 'warning' : 'danger');
    }

    public function toggleActive(int $id): void
    {
        $this->authorize('manage-settings');

        $policy = PayrollApprovalPolicy::findOrFail($id);

        if (! $policy->is_active && ! $this->approverCanOpenApprovals($policy->approver_type, $policy->specific_user_id)) {
            \Flux::toast(self::APPROVER_CANNOT_APPROVE, variant: 'danger');

            return;
        }

        $kept = $this->keepingFinanceSignOff(function () use ($policy) {
            $policy->update(['is_active' => ! $policy->is_active]);
        });

        if (! $kept) {
            \Flux::toast(self::FINANCE_REQUIRED, variant: 'danger');
        }
    }

    public function moveUp(int $id): void
    {
        $this->authorize('manage-settings');
        $this->swapWithNeighbor($id, -1);
    }

    public function moveDown(int $id): void
    {
        $this->authorize('manage-settings');
        $this->swapWithNeighbor($id, 1);
    }

    /**
     * Apply a change to the chain only if it still ends in Finance's sign-off
     * (spec §3.5, §4.1): HR configures the chain but cannot route payroll
     * around Finance. The change is rolled back otherwise.
     */
    private function keepingFinanceSignOff(callable $change): bool
    {
        try {
            DB::transaction(function () use ($change) {
                $change();

                if (! PayrollApprovalPolicy::chainHasFinanceSignOff()) {
                    throw new \DomainException(self::FINANCE_REQUIRED);
                }
            });
        } catch (\DomainException) {
            return false;
        }

        return true;
    }

    /**
     * Whether the step's approver can open Finance Approval (run-payroll or
     * approve-finance) — otherwise a run would wait on someone who cannot act.
     * A Director needs Finance Approval granted in Roles & Permissions (D1).
     */
    private function approverCanOpenApprovals(string $approverType, ?int $specificUserId): bool
    {
        if ($approverType === 'super_admin') {
            return true;
        }

        if ($approverType === 'specific_user') {
            $user = User::find($specificUserId);

            return $user !== null && ($user->canRunPayroll() || $user->canApproveFinance());
        }

        $role = Role::where('slug', $approverType)->first();

        return $role === null || $role->hasPermission('run_payroll') || $role->hasPermission('approve_finance');
    }

    private function swapWithNeighbor(int $id, int $direction): void
    {
        $ordered = PayrollApprovalPolicy::orderBy('level')->get();
        $index = $ordered->search(fn (PayrollApprovalPolicy $p) => $p->id === $id);
        $neighborIndex = $index + $direction;

        if ($index === false || ! $ordered->has($neighborIndex)) {
            return;
        }

        $current = $ordered->get($index);
        $neighbor = $ordered->get($neighborIndex);
        [$currentLevel, $neighborLevel] = [$current->level, $neighbor->level];

        $current->update(['level' => $neighborLevel]);
        $neighbor->update(['level' => $currentLevel]);
    }

    public function render()
    {
        return view('livewire.settings.approval-policy-settings', [
            'policies' => PayrollApprovalPolicy::with('specificUser')->orderBy('level')->get(),
            'users' => User::orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.app', ['title' => 'Payroll Approval Policy']);
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->label = '';
        $this->approver_type = 'hr_admin';
        $this->specific_user_id = null;
        $this->is_active = true;
    }
}
