<?php

namespace App\Livewire\Settings;

use App\Models\Department;
use Illuminate\Validation\Rule;
use Livewire\Component;

class DepartmentManager extends Component
{
    // ── Form state ────────────────────────────────────────────────────────────
    public bool $showModal = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $code = '';

    public string $description = '';

    public string $default_ot_source = 'biometric';

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
        $dept = Department::findOrFail($id);
        $this->editingId = $id;
        $this->name = $dept->name;
        $this->code = $dept->code ?? '';
        $this->description = $dept->description ?? '';
        $this->default_ot_source = $dept->default_ot_source ?? 'biometric';
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
            'name' => ['required', 'string', 'max:100', Rule::unique('departments', 'name')->ignore($this->editingId)],
            'code' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:500'],
            'default_ot_source' => ['required', 'in:biometric,manual,nexflow,hybrid'],
        ]);

        if ($this->editingId) {
            Department::findOrFail($this->editingId)->update($data);
            \Flux::toast('Department updated.', variant: 'success');
        } else {
            Department::create($data);
            \Flux::toast('Department created.', variant: 'success');
        }

        $this->closeModal();
    }

    public function delete(int $id): void
    {
        $this->authorize('manage-settings');

        $dept = Department::findOrFail($id);

        if ($dept->employees()->exists()) {
            \Flux::toast('Cannot delete — employees are assigned to this department.', variant: 'danger');

            return;
        }

        $dept->delete();
        \Flux::toast('Department deleted.', variant: 'warning');
    }

    public function render()
    {
        return view('livewire.settings.department-manager', [
            'departments' => Department::orderBy('name')->get(),
        ])->layout('layouts.app', ['title' => 'Departments']);
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'code', 'description']);
        $this->default_ot_source = 'biometric';
    }
}
