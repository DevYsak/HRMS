<?php

namespace App\Livewire\Payroll;

use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use Illuminate\Validation\Rule;
use Livewire\Component;

class SalaryStructures extends Component
{
    public bool $showModal = false;

    public bool $showTrashed = false;

    public ?int $editingId = null;

    public array $form = [
        'name' => '',
        'code' => '',
        'description' => '',
        'is_active' => true,
    ];

    /** @var array<int, bool> Component id => selected */
    public array $selectedComponents = [];

    /** @var array<int, float|string|null> Component id => amount override */
    public array $componentAmounts = [];

    public function create(): void
    {
        $this->editingId = null;
        $this->form = [
            'name' => '',
            'code' => '',
            'description' => '',
            'is_active' => true,
        ];
        $this->selectedComponents = [];
        $this->componentAmounts = [];
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $structure = SalaryStructure::with('components')->findOrFail($id);

        $this->editingId = $id;
        $this->form = $structure->only(['name', 'code', 'description', 'is_active']);

        $this->selectedComponents = [];
        $this->componentAmounts = [];
        foreach ($structure->components as $component) {
            $this->selectedComponents[$component->id] = true;
            $this->componentAmounts[$component->id] = $component->pivot->amount;
        }

        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'form.name' => ['required', 'string', 'max:255'],
            'form.code' => ['nullable', 'string', 'max:30', Rule::unique('salary_structures', 'code')->ignore($this->editingId)],
            'form.description' => ['nullable', 'string', 'max:500'],
            'form.is_active' => ['boolean'],
        ]);

        if ($this->editingId) {
            $structure = SalaryStructure::findOrFail($this->editingId);
            $structure->update($this->form);
            \Flux::toast('Salary structure updated successfully.');
        } else {
            $structure = SalaryStructure::create($this->form);
            \Flux::toast('Salary structure created successfully.');
        }

        $syncData = [];
        foreach ($this->selectedComponents as $componentId => $selected) {
            if ($selected) {
                $syncData[$componentId] = ['amount' => $this->componentAmounts[$componentId] ?? null];
            }
        }
        $structure->components()->sync($syncData);

        $this->showModal = false;
    }

    public function toggleActive(int $id): void
    {
        $structure = SalaryStructure::findOrFail($id);
        $structure->update(['is_active' => ! $structure->is_active]);
    }

    public function delete(int $id): void
    {
        SalaryStructure::findOrFail($id)->delete();
        \Flux::toast('Salary structure deleted.');
    }

    public function restore(int $id): void
    {
        SalaryStructure::withTrashed()->findOrFail($id)->restore();
        \Flux::toast('Salary structure restored.');
    }

    public function render()
    {
        $structures = SalaryStructure::with('components')
            ->when($this->showTrashed, fn ($query) => $query->withTrashed())
            ->orderBy('name')
            ->get();

        return view('livewire.payroll.salary-structures', [
            'structures' => $structures,
            'components' => SalaryComponent::active()->ordered()->get(),
        ])->layout('layouts.app', ['title' => 'Salary Structures']);
    }
}
