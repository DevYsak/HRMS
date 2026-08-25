<?php

namespace App\Livewire\Payroll;

use App\Models\Employee;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use App\Services\PayrollService;
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

    public bool $showAssignModal = false;

    public ?int $assigningStructureId = null;

    public array $assignForm = [
        'employee_id' => '',
        'effective_date' => '',
        'reason' => '',
    ];

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

    public function openAssign(int $id): void
    {
        $this->assigningStructureId = $id;
        $this->assignForm = [
            'employee_id' => '',
            'effective_date' => now()->toDateString(),
            'reason' => '',
        ];
        $this->resetErrorBag('assignForm');
        $this->showAssignModal = true;
    }

    public function assign(PayrollService $payrollService): void
    {
        $this->validate([
            'assignForm.employee_id' => ['required', 'exists:employees,id'],
            'assignForm.effective_date' => ['required', 'date'],
            'assignForm.reason' => ['nullable', 'string', 'max:500'],
        ]);

        $structure = SalaryStructure::with('components')->findOrFail($this->assigningStructureId);
        $employee = Employee::with('user')->findOrFail($this->assignForm['employee_id']);

        try {
            $payrollService->assignSalaryStructure(
                $structure,
                $employee,
                auth()->user(),
                $this->assignForm['effective_date'],
                $this->assignForm['reason'] !== '' ? $this->assignForm['reason'] : null,
            );
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        \Flux::toast("\"{$structure->name}\" assigned to {$employee->user?->name}.");
        $this->showAssignModal = false;
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
            'employees' => Employee::with('user')
                ->where('status', 'active')
                ->get()
                ->sortBy(fn (Employee $employee) => $employee->user?->name ?? '')
                ->values(),
        ])->layout('layouts.app', ['title' => 'Salary Structures']);
    }
}
