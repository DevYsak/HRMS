<?php

namespace App\Livewire\Settings;

use App\Models\OnboardingTemplate;
use App\Models\OnboardingTemplateTask;
use Livewire\Component;

class OnboardingTemplateTaskManager extends Component
{
    public OnboardingTemplate $template;

    // ── Form state ────────────────────────────────────────────────────────────
    public bool $showModal = false;

    public ?int $editingId = null;

    public string $title = '';

    public string $description = '';

    public string $phase = 'onboarding';

    public string $category = 'general';

    public string $owner_role = 'hr';

    public int $due_days = 7;

    public int $sort_order = 0;

    public string $auto_trigger = '';

    public function mount(OnboardingTemplate $template): void
    {
        $this->authorize('manage-settings');
        $this->template = $template;
        $this->sort_order = $template->tasks()->max('sort_order') + 1;
    }

    // ── Modal helpers ─────────────────────────────────────────────────────────

    public function openCreate(): void
    {
        $this->resetForm();
        $this->sort_order = $this->template->tasks()->max('sort_order') + 1;
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $task = OnboardingTemplateTask::findOrFail($id);
        $this->editingId = $id;
        $this->title = $task->title;
        $this->description = $task->description ?? '';
        $this->phase = $task->phase;
        $this->category = $task->category;
        $this->owner_role = $task->owner_role ?? 'hr';
        $this->due_days = $task->due_days;
        $this->sort_order = $task->sort_order;
        $this->auto_trigger = $task->auto_trigger ?? '';
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
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:1000'],
            'phase' => ['required', 'in:onboarding,offboarding'],
            'category' => ['required', 'string', 'max:50'],
            'owner_role' => ['nullable', 'string', 'max:50'],
            'due_days' => ['required', 'integer', 'min:0', 'max:365'],
            'sort_order' => ['integer', 'min:0'],
            'auto_trigger' => ['nullable', 'string', 'in:account_create,kyc_upload,biometric_sync,asset_assign,'],
        ]);

        $data['auto_trigger'] = $data['auto_trigger'] ?: null;
        $data['template_id'] = $this->template->id;

        if ($this->editingId) {
            OnboardingTemplateTask::findOrFail($this->editingId)->update($data);
            \Flux::toast('Task updated.', variant: 'success');
        } else {
            OnboardingTemplateTask::create($data);
            \Flux::toast('Task added.', variant: 'success');
        }

        $this->closeModal();
    }

    public function delete(int $id): void
    {
        $this->authorize('manage-settings');
        OnboardingTemplateTask::findOrFail($id)->delete();
        \Flux::toast('Task removed.', variant: 'warning');
    }

    public function moveUp(int $id): void
    {
        $task = OnboardingTemplateTask::findOrFail($id);
        $above = OnboardingTemplateTask::where('template_id', $this->template->id)
            ->where('sort_order', '<', $task->sort_order)
            ->orderByDesc('sort_order')
            ->first();

        if ($above) {
            [$task->sort_order, $above->sort_order] = [$above->sort_order, $task->sort_order];
            $task->save();
            $above->save();
        }
    }

    public function moveDown(int $id): void
    {
        $task = OnboardingTemplateTask::findOrFail($id);
        $below = OnboardingTemplateTask::where('template_id', $this->template->id)
            ->where('sort_order', '>', $task->sort_order)
            ->orderBy('sort_order')
            ->first();

        if ($below) {
            [$task->sort_order, $below->sort_order] = [$below->sort_order, $task->sort_order];
            $task->save();
            $below->save();
        }
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render()
    {
        return view('livewire.settings.onboarding-template-task-manager', [
            'tasks' => OnboardingTemplateTask::where('template_id', $this->template->id)
                ->orderBy('phase')
                ->orderBy('sort_order')
                ->get(),
        ])->layout('layouts.app', ['title' => 'Template Tasks — '.$this->template->name]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resetForm(): void
    {
        $this->reset(['editingId', 'title', 'description', 'auto_trigger']);
        $this->phase = 'onboarding';
        $this->category = 'general';
        $this->owner_role = 'hr';
        $this->due_days = 7;
        $this->sort_order = 0;
    }
}
