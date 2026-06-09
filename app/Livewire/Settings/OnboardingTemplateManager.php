<?php

namespace App\Livewire\Settings;

use App\Models\Department;
use App\Models\EmploymentType;
use App\Models\JobTitle;
use App\Models\OnboardingTemplate;
use Illuminate\Validation\Rule;
use Livewire\Component;

class OnboardingTemplateManager extends Component
{
    // ── Form state ────────────────────────────────────────────────────────────
    public bool $showModal = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    public string $department_id = '';

    public string $job_title_id = '';

    public string $employment_type_id = '';

    public bool $is_default = false;

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorize('manage-settings');
    }

    // ── Modal helpers ─────────────────────────────────────────────────────────

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $template = OnboardingTemplate::findOrFail($id);
        $this->editingId = $id;
        $this->name = $template->name;
        $this->description = $template->description ?? '';
        $this->department_id = (string) ($template->department_id ?? '');
        $this->job_title_id = (string) ($template->job_title_id ?? '');
        $this->employment_type_id = (string) ($template->employment_type_id ?? '');
        $this->is_default = $template->is_default;
        $this->is_active = $template->is_active;
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
            'name' => ['required', 'string', 'max:150', Rule::unique('onboarding_templates', 'name')->ignore($this->editingId)],
            'description' => ['nullable', 'string', 'max:500'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'job_title_id' => ['nullable', 'exists:job_titles,id'],
            'employment_type_id' => ['nullable', 'exists:employment_types,id'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $data['department_id'] = $data['department_id'] ?: null;
        $data['job_title_id'] = $data['job_title_id'] ?: null;
        $data['employment_type_id'] = $data['employment_type_id'] ?: null;
        $data['created_by'] = auth()->id();

        if ($data['is_default']) {
            OnboardingTemplate::where('is_default', true)
                ->when($this->editingId, fn ($q) => $q->where('id', '!=', $this->editingId))
                ->update(['is_default' => false]);
        }

        if ($this->editingId) {
            OnboardingTemplate::findOrFail($this->editingId)->update($data);
            \Flux::toast('Template updated.', variant: 'success');
        } else {
            OnboardingTemplate::create($data);
            \Flux::toast('Template created.', variant: 'success');
        }

        $this->closeModal();
    }

    public function setDefault(int $id): void
    {
        $this->authorize('manage-settings');
        OnboardingTemplate::where('is_default', true)->update(['is_default' => false]);
        OnboardingTemplate::findOrFail($id)->update(['is_default' => true]);
        \Flux::toast('Default template updated.', variant: 'success');
    }

    public function toggleActive(int $id): void
    {
        $this->authorize('manage-settings');
        $template = OnboardingTemplate::findOrFail($id);
        $template->update(['is_active' => ! $template->is_active]);
        \Flux::toast('Status updated.', variant: 'success');
    }

    public function delete(int $id): void
    {
        $this->authorize('manage-settings');
        $template = OnboardingTemplate::findOrFail($id);

        if ($template->is_default) {
            \Flux::toast('Cannot delete the default template.', variant: 'danger');

            return;
        }

        $template->delete();
        \Flux::toast('Template deleted.', variant: 'warning');
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render()
    {
        return view('livewire.settings.onboarding-template-manager', [
            'templates' => OnboardingTemplate::with(['department', 'jobTitle', 'employmentType'])
                ->withCount('tasks')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(),
            'departments' => Department::orderBy('name')->get(),
            'jobTitles' => JobTitle::orderBy('name')->get(),
            'employmentTypes' => EmploymentType::active()->get(),
        ])->layout('layouts.app', ['title' => 'Onboarding Templates']);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'description', 'department_id', 'job_title_id', 'employment_type_id', 'is_default']);
        $this->is_active = true;
    }
}
