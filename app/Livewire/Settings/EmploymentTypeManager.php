<?php

namespace App\Livewire\Settings;

use App\Models\EmploymentType;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

class EmploymentTypeManager extends Component
{
    // ── List state ────────────────────────────────────────────────────────────
    public bool $showTrashed = false;

    // ── Form state ────────────────────────────────────────────────────────────
    public bool $showModal = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $slug = '';

    public int $probation_days = 90;

    public int $notice_period_days = 30;

    public bool $leave_eligible = true;

    public bool $payroll_eligible = true;

    public bool $ot_eligible = true;

    public bool $is_active = true;

    public int $sort_order = 0;

    public function mount(): void
    {
        $this->authorize('manage-settings');
    }

    // ── Watchers ─────────────────────────────────────────────────────────────

    public function updatedName(string $value): void
    {
        if (! $this->editingId) {
            $this->slug = Str::slug($value);
        }
    }

    // ── Modal helpers ─────────────────────────────────────────────────────────

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $type = EmploymentType::withTrashed()->findOrFail($id);
        $this->editingId = $id;
        $this->name = $type->name;
        $this->slug = $type->slug;
        $this->probation_days = $type->probation_days;
        $this->notice_period_days = $type->notice_period_days;
        $this->leave_eligible = $type->leave_eligible;
        $this->payroll_eligible = $type->payroll_eligible;
        $this->ot_eligible = $type->ot_eligible;
        $this->is_active = $type->is_active;
        $this->sort_order = $type->sort_order;
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    // ── CRUD ─────────────────────────────────────────────────────────────────

    public function save(): void
    {
        $this->authorize('manage-settings');

        $data = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:60', Rule::unique('employment_types', 'slug')->ignore($this->editingId)->whereNull('deleted_at')],
            'probation_days' => ['required', 'integer', 'min:0', 'max:730'],
            'notice_period_days' => ['required', 'integer', 'min:0', 'max:365'],
            'leave_eligible' => ['boolean'],
            'payroll_eligible' => ['boolean'],
            'ot_eligible' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        if ($this->editingId) {
            EmploymentType::withTrashed()->findOrFail($this->editingId)->update($data);
            \Flux::toast('Employment type updated.', variant: 'success');
        } else {
            EmploymentType::create($data);
            \Flux::toast('Employment type created.', variant: 'success');
        }

        $this->closeModal();
    }

    public function toggleActive(int $id): void
    {
        $this->authorize('manage-settings');
        $type = EmploymentType::findOrFail($id);
        $type->update(['is_active' => ! $type->is_active]);
        \Flux::toast('Status updated.', variant: 'success');
    }

    public function delete(int $id): void
    {
        $this->authorize('manage-settings');

        $type = EmploymentType::findOrFail($id);

        if ($type->employees()->exists()) {
            \Flux::toast('Cannot delete — employees are assigned to this type.', variant: 'danger');

            return;
        }

        $type->delete();
        \Flux::toast('Employment type deleted.', variant: 'warning');
    }

    public function restore(int $id): void
    {
        $this->authorize('manage-settings');
        EmploymentType::withTrashed()->findOrFail($id)->restore();
        \Flux::toast('Employment type restored.', variant: 'success');
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render()
    {
        $query = EmploymentType::orderBy('sort_order')->orderBy('name');

        if ($this->showTrashed) {
            $query->withTrashed();
        }

        return view('livewire.settings.employment-type-manager', [
            'types' => $query->get(),
        ])->layout('layouts.app', ['title' => 'Employment Types']);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'name', 'slug', 'probation_days', 'notice_period_days',
            'leave_eligible', 'payroll_eligible', 'ot_eligible', 'is_active', 'sort_order',
        ]);
        $this->leave_eligible = true;
        $this->payroll_eligible = true;
        $this->ot_eligible = true;
        $this->is_active = true;
        $this->probation_days = 90;
        $this->notice_period_days = 30;
    }
}
