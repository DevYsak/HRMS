<?php

namespace App\Livewire\Payroll;

use App\Models\SalaryComponent;
use App\Services\FormulaEvaluator;
use Livewire\Component;

class Components extends Component
{
    public $showModal = false;

    public $editingId = null;

    public array $form = [
        'name' => '',
        'code' => '',
        'type' => 'earning',
        'component_type' => 'earning',
        'calculation_type' => 'fixed',
        'default_amount' => 0,
        'percentage_value' => 0,
        'percentage_basis' => 'basic',
        'percentage_of_component_id' => '',
        'formula_expression' => '',
        'is_taxable' => true,
        'is_pf_applicable' => false,
        'is_esi_applicable' => false,
        'display_order' => 0,
        'is_active' => true,
    ];

    /** Live formula preview, keyed by uppercase component code with a sample value. */
    public ?float $formulaPreview = null;

    public ?string $formulaError = null;

    public function create()
    {
        $this->editingId = null;
        $this->resetForm();
        $this->showModal = true;
    }

    public function edit($id)
    {
        $component = SalaryComponent::findOrFail($id);
        $this->editingId = $id;
        $this->form = [
            'name' => $component->name,
            'code' => $component->code ?? '',
            'type' => $component->type,
            'component_type' => $component->component_type ?? $component->type,
            'calculation_type' => $component->calculation_type ?? ($component->is_fixed ? 'fixed' : 'percentage'),
            'default_amount' => (float) $component->default_amount,
            'percentage_value' => (float) ($component->percentage_value ?? 0),
            'percentage_basis' => $component->percentage_basis ?? 'basic',
            'percentage_of_component_id' => $component->percentage_of_component_id ?? '',
            'formula_expression' => $component->formula_expression ?? '',
            'is_taxable' => (bool) $component->is_taxable,
            'is_pf_applicable' => (bool) $component->is_pf_applicable,
            'is_esi_applicable' => (bool) $component->is_esi_applicable,
            'display_order' => (int) $component->display_order,
            'is_active' => (bool) $component->is_active,
        ];
        $this->formulaPreview = null;
        $this->formulaError = null;
        $this->showModal = true;
    }

    /** Sanity-check the formula against a sample context so a typo is caught before saving. */
    public function updatedFormFormulaExpression(): void
    {
        $this->formulaError = null;
        $this->formulaPreview = null;

        if (trim($this->form['formula_expression']) === '') {
            return;
        }

        // Every existing component code gets a representative sample value so
        // the preview reflects realistic magnitudes, not just zeros.
        $sample = SalaryComponent::whereNotNull('code')->pluck('default_amount', 'code')
            ->mapWithKeys(fn ($amount, $code) => [strtoupper($code) => (float) $amount])
            ->all();

        try {
            $this->formulaPreview = FormulaEvaluator::evaluate($this->form['formula_expression'], $sample);
        } catch (\RuntimeException $e) {
            $this->formulaError = $e->getMessage();
        }
    }

    public function save()
    {
        $this->validate([
            'form.name' => 'required|string|max:255',
            'form.code' => 'nullable|string|max:50|regex:/^[A-Za-z0-9_]+$/',
            'form.type' => 'required|in:earning,deduction',
            'form.component_type' => 'required|in:earning,deduction,employer_contribution',
            'form.calculation_type' => 'required|in:fixed,percentage,formula',
            'form.default_amount' => 'required|numeric|min:0',
            'form.percentage_value' => 'nullable|numeric|min:0|max:999.99',
            'form.percentage_basis' => 'nullable|in:basic,gross,ctc,component',
            'form.percentage_of_component_id' => 'nullable|exists:salary_components,id',
            'form.formula_expression' => 'nullable|string|max:2000',
            'form.display_order' => 'nullable|integer|min:0',
        ]);

        if ($this->form['calculation_type'] === 'formula' && trim($this->form['formula_expression']) === '') {
            $this->addError('form.formula_expression', 'A formula is required when calculation type is Formula.');

            return;
        }

        if ($this->form['calculation_type'] === 'formula' && $this->formulaError !== null) {
            $this->addError('form.formula_expression', 'Fix the formula error before saving: '.$this->formulaError);

            return;
        }

        $payload = $this->form;
        $payload['code'] = $payload['code'] !== '' ? strtoupper($payload['code']) : null;
        $payload['percentage_of_component_id'] = $payload['percentage_of_component_id'] !== '' ? $payload['percentage_of_component_id'] : null;
        // is_fixed kept in sync for any legacy read path still checking it directly.
        $payload['is_fixed'] = $payload['calculation_type'] === 'fixed';

        if ($this->editingId) {
            SalaryComponent::findOrFail($this->editingId)->update($payload);
            \Flux::toast('Component updated successfully.');
        } else {
            SalaryComponent::create($payload);
            \Flux::toast('Component created successfully.');
        }

        $this->showModal = false;
    }

    public function toggleActive($id)
    {
        $component = SalaryComponent::findOrFail($id);
        $component->update(['is_active' => ! $component->is_active]);
    }

    private function resetForm(): void
    {
        $this->form = [
            'name' => '', 'code' => '', 'type' => 'earning', 'component_type' => 'earning',
            'calculation_type' => 'fixed', 'default_amount' => 0, 'percentage_value' => 0,
            'percentage_basis' => 'basic', 'percentage_of_component_id' => '', 'formula_expression' => '',
            'is_taxable' => true, 'is_pf_applicable' => false, 'is_esi_applicable' => false,
            'display_order' => 0, 'is_active' => true,
        ];
        $this->formulaPreview = null;
        $this->formulaError = null;
    }

    public function render()
    {
        return view('livewire.payroll.components', [
            'earnings' => SalaryComponent::where('type', 'earning')->ordered()->get(),
            'deductions' => SalaryComponent::where('type', 'deduction')->ordered()->get(),
            'availableComponents' => SalaryComponent::whereNotNull('code')
                ->when($this->editingId, fn ($q) => $q->where('id', '!=', $this->editingId))
                ->orderBy('name')->get(['id', 'name', 'code']),
        ])->layout('layouts.app', ['title' => 'Salary Components']);
    }
}
