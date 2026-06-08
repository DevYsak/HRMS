<?php

namespace App\Livewire\Settings;

use App\Models\JobTitle;
use Illuminate\Validation\Rule;
use Livewire\Component;

class JobTitleManager extends Component
{
    public bool $showModal = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $level = '';

    public function mount(): void
    {
        $this->authorize('manage-settings');
    }

    public function updatedName(string $value): void
    {
        // no-op — no slug needed for job titles
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $title = JobTitle::findOrFail($id);
        $this->editingId = $id;
        $this->name = $title->name;
        $this->level = $title->level ?? '';
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
            'name' => ['required', 'string', 'max:100', Rule::unique('job_titles', 'name')->ignore($this->editingId)],
            'level' => ['nullable', 'string', 'max:100'],
        ]);

        $data['level'] = $data['level'] ?: null;

        if ($this->editingId) {
            JobTitle::findOrFail($this->editingId)->update($data);
            \Flux::toast('Job title updated.', variant: 'success');
        } else {
            JobTitle::create($data);
            \Flux::toast('Job title created.', variant: 'success');
        }

        $this->closeModal();
    }

    public function delete(int $id): void
    {
        $this->authorize('manage-settings');

        $title = JobTitle::findOrFail($id);

        if ($title->employees()->exists()) {
            \Flux::toast('Cannot delete — employees are assigned to this title.', variant: 'danger');

            return;
        }

        $title->delete();
        \Flux::toast('Job title deleted.', variant: 'warning');
    }

    public function render()
    {
        return view('livewire.settings.job-title-manager', [
            'titles' => JobTitle::orderBy('name')->get(),
        ])->layout('layouts.app', ['title' => 'Job Titles']);
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'level']);
    }
}
