<?php

namespace App\Livewire\Settings;

use App\Models\Department;
use App\Models\User;
use App\Services\Audit\AuditService;
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

    /** users.id of the department head ('' = none). Heads reach the department through department-scoped permissions. */
    public string $head_id = '';

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
        $this->head_id = $dept->head_id ? (string) $dept->head_id : '';
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
            'head_id' => ['nullable', Rule::exists('users', 'id')->whereNull('deleted_at')],
        ]);
        $data['head_id'] = $data['head_id'] ? (int) $data['head_id'] : null;

        $dept = $this->editingId ? Department::findOrFail($this->editingId) : null;
        $previousHead = $dept?->head_id;

        if ($dept) {
            $dept->update($data);
            \Flux::toast('Department updated.', variant: 'success');
        } else {
            $dept = Department::create($data);
            \Flux::toast('Department created.', variant: 'success');
        }

        // The head reaches the whole department, so a change is a permission change.
        if ($previousHead !== $dept->head_id) {
            app(AuditService::class)->event('DEPARTMENT_HEAD_CHANGED', AuditService::PERMISSIONS, $dept,
                old: ['head_id' => $previousHead], new: ['head_id' => $dept->head_id], module: AuditService::SETTINGS);
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
            'departments' => Department::with('head:id,name')->withCount('employees')->orderBy('name')->get(),
            'headCandidates' => User::query()
                ->whereHas('employee', fn ($q) => $q->where('status', 'active'))
                ->orderBy('name')
                ->get(['id', 'name']),
        ])->layout('layouts.app', ['title' => 'Departments']);
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'code', 'description', 'head_id']);
        $this->default_ot_source = 'biometric';
    }
}
