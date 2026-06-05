<?php

namespace App\Livewire\Settings;

use App\Models\SalaryCycle;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

class SalaryCycleManager extends Component
{
    public bool $showModal = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $slug = '';

    public int $start_day = 1;

    public int $end_day = 0;

    public int $pay_day = 5;

    public bool $is_default = false;

    public bool $is_active = true;

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
        $cycle = SalaryCycle::withTrashed()->findOrFail($id);
        $this->editingId = $id;
        $this->name = $cycle->name;
        $this->slug = $cycle->slug;
        $this->start_day = $cycle->start_day;
        $this->end_day = $cycle->end_day;
        $this->pay_day = $cycle->pay_day;
        $this->is_default = $cycle->is_default;
        $this->is_active = $cycle->is_active;
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
            'slug' => ['required', 'string', 'max:60', Rule::unique('salary_cycles', 'slug')->ignore($this->editingId)->whereNull('deleted_at')],
            'start_day' => ['required', 'integer', 'min:1', 'max:31'],
            'end_day' => ['required', 'integer', 'min:0', 'max:31'],
            'pay_day' => ['required', 'integer', 'min:1', 'max:31'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        if ($this->editingId) {
            SalaryCycle::withTrashed()->findOrFail($this->editingId)->update($data);
            \Flux::toast('Salary cycle updated.', variant: 'success');
        } else {
            SalaryCycle::create($data);
            \Flux::toast('Salary cycle created.', variant: 'success');
        }

        // Ensure only one default at a time
        if ($data['is_default']) {
            SalaryCycle::where('id', '!=', $this->editingId ?? SalaryCycle::latest()->first()?->id)
                ->update(['is_default' => false]);
        }

        $this->closeModal();
    }

    public function delete(int $id): void
    {
        $this->authorize('manage-settings');

        $cycle = SalaryCycle::findOrFail($id);

        if ($cycle->employees()->exists()) {
            \Flux::toast('Cannot delete — employees are assigned to this cycle.', variant: 'danger');

            return;
        }

        $cycle->delete();
        \Flux::toast('Salary cycle deleted.', variant: 'warning');
    }

    public function restore(int $id): void
    {
        $this->authorize('manage-settings');
        SalaryCycle::withTrashed()->findOrFail($id)->restore();
        \Flux::toast('Salary cycle restored.', variant: 'success');
    }

    public function render()
    {
        return view('livewire.settings.salary-cycle-manager', [
            'cycles' => SalaryCycle::withTrashed()->orderBy('name')->get(),
        ])->layout('layouts.app', ['title' => 'Salary Cycles']);
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'slug', 'is_default']);
        $this->start_day = 1;
        $this->end_day = 0;
        $this->pay_day = 5;
        $this->is_active = true;
    }
}
