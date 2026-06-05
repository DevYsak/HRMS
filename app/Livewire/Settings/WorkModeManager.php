<?php

namespace App\Livewire\Settings;

use App\Models\WorkMode;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

class WorkModeManager extends Component
{
    public bool $showModal = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $slug = '';

    public string $color = '#1DB77A';

    public bool $requires_attendance_tracking = true;

    public bool $is_active = true;

    public int $sort_order = 0;

    public function mount(): void
    {
        $this->authorize('manage-settings');
    }

    public function updatedName(string $value): void
    {
        if (! $this->editingId) {
            $this->slug = Str::slug($value);
        }
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $mode = WorkMode::withTrashed()->findOrFail($id);
        $this->editingId = $id;
        $this->name = $mode->name;
        $this->slug = $mode->slug;
        $this->color = $mode->color;
        $this->requires_attendance_tracking = $mode->requires_attendance_tracking;
        $this->is_active = $mode->is_active;
        $this->sort_order = $mode->sort_order;
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
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:60', Rule::unique('work_modes', 'slug')->ignore($this->editingId)->whereNull('deleted_at')],
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'requires_attendance_tracking' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        if ($this->editingId) {
            WorkMode::withTrashed()->findOrFail($this->editingId)->update($data);
            \Flux::toast('Work mode updated.', variant: 'success');
        } else {
            WorkMode::create($data);
            \Flux::toast('Work mode created.', variant: 'success');
        }

        $this->closeModal();
    }

    public function delete(int $id): void
    {
        $this->authorize('manage-settings');

        $mode = WorkMode::findOrFail($id);

        if ($mode->employees()->exists()) {
            \Flux::toast('Cannot delete — employees are assigned to this work mode.', variant: 'danger');

            return;
        }

        $mode->delete();
        \Flux::toast('Work mode deleted.', variant: 'warning');
    }

    public function restore(int $id): void
    {
        $this->authorize('manage-settings');
        WorkMode::withTrashed()->findOrFail($id)->restore();
        \Flux::toast('Work mode restored.', variant: 'success');
    }

    public function render()
    {
        return view('livewire.settings.work-mode-manager', [
            'modes' => WorkMode::withTrashed()->orderBy('sort_order')->orderBy('name')->get(),
        ])->layout('layouts.app', ['title' => 'Work Modes']);
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'slug', 'sort_order']);
        $this->color = '#1DB77A';
        $this->requires_attendance_tracking = true;
        $this->is_active = true;
    }
}
