<?php

namespace App\Livewire\Settings;

use App\Models\PayrollApprovalPolicy;
use App\Models\User;
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

        if ($this->editingId) {
            PayrollApprovalPolicy::findOrFail($this->editingId)->update($data);
            \Flux::toast('Approval step updated.', variant: 'success');
        } else {
            PayrollApprovalPolicy::create($data + ['level' => (PayrollApprovalPolicy::max('level') ?? 0) + 1]);
            \Flux::toast('Approval step added.', variant: 'success');
        }

        PayrollApprovalPolicy::renumber();
        $this->closeModal();
    }

    public function delete(int $id): void
    {
        $this->authorize('manage-settings');

        PayrollApprovalPolicy::findOrFail($id)->delete();
        PayrollApprovalPolicy::renumber();
        \Flux::toast('Approval step removed.', variant: 'warning');
    }

    public function toggleActive(int $id): void
    {
        $this->authorize('manage-settings');

        $policy = PayrollApprovalPolicy::findOrFail($id);
        $policy->update(['is_active' => ! $policy->is_active]);
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
