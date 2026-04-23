<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Department>
 */
class DepartmentFactory extends Factory
{
    public function definition(): array
    {
        $departments = [
            'Engineering' => 'ENG',
            'Product' => 'PRD',
            'Design' => 'DES',
            'Marketing' => 'MKT',
            'Sales' => 'SLS',
            'Finance' => 'FIN',
            'Human Resources' => 'HR',
            'Operations' => 'OPS',
            'Customer Success' => 'CS',
            'Legal' => 'LGL',
        ];
        $name = fake()->randomElement(array_keys($departments));

        return [
            'company_id' => Company::factory(),
            'name' => $name,
            'code' => $departments[$name],
            'description' => fake()->sentence(),
        ];
    }
}
