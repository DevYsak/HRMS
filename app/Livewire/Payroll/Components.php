<?php

namespace App\Livewire\Payroll;

use App\Models\SalaryComponent;
use Livewire\Component;

class Components extends Component
{
    public $showModal = false;

    public $editingId = null;

    public $form = [
        'name' => '',
        'type' => 'earning',
        'is_fixed' => true,
        'default_amount' => 0,
        'is_active' => true,
    ];

    public function create()
    {
        $this->editingId = null;
        $this->form = [
            'name' => '',
            'type' => 'earning',
            'is_fixed' => true,
            'default_amount' => 0,
            'is_active' => true,
        ];
        $this->showModal = true;
    }

    public function edit($id)
    {
        $component = SalaryComponent::findOrFail($id);
        $this->editingId = $id;
        $this->form = $component->only(['name', 'type', 'is_fixed', 'default_amount', 'is_active']);
        $this->showModal = true;
    }

    public function save()
    {
        $this->validate([
            'form.name' => 'required|string|max:255',
            'form.type' => 'required|in:earning,deduction',
            'form.default_amount' => 'required|numeric|min:0',
        ]);

        if ($this->editingId) {
            SalaryComponent::findOrFail($this->editingId)->update($this->form);
            \Flux::toast('Component updated successfully.');
        } else {
            SalaryComponent::create($this->form);
            \Flux::toast('Component created successfully.');
        }

        $this->showModal = false;
    }

    public function toggleActive($id)
    {
        $component = SalaryComponent::findOrFail($id);
        $component->update(['is_active' => ! $component->is_active]);
    }

    public function render()
    {
        return view('livewire.payroll.components', [
            'earnings' => SalaryComponent::where('type', 'earning')->get(),
            'deductions' => SalaryComponent::where('type', 'deduction')->get(),
        ])->layout('layouts.app', ['title' => 'Salary Components']);
    }
}
