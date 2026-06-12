<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalaryStructure extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'code', 'description', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function components(): BelongsToMany
    {
        return $this->belongsToMany(SalaryComponent::class, 'salary_structure_components')
            ->withPivot('amount')
            ->withTimestamps();
    }

    /**
     * Assign this structure's components to an employee as employee_salaries rows,
     * using each component's pivot amount override (falling back to its default_amount).
     */
    public function applyToEmployee(Employee $employee, ?string $effectiveFrom = null): void
    {
        foreach ($this->components as $component) {
            EmployeeSalary::create([
                'employee_id' => $employee->id,
                'salary_component_id' => $component->id,
                'amount' => $component->pivot->amount ?? $component->default_amount,
                'effective_from' => $effectiveFrom,
            ]);
        }
    }
}
