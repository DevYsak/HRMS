<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\JobTitle>
 */
class JobTitleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->randomElement([
                'Software Engineer', 'Senior Software Engineer', 'Lead Engineer',
                'Product Manager', 'UI/UX Designer', 'Graphic Designer',
                'Marketing Manager', 'Sales Executive', 'HR Manager',
                'Finance Analyst', 'Project Manager', 'Operations Manager',
                'Customer Success Manager', 'Data Analyst', 'DevOps Engineer',
            ]),
            'level' => fake()->randomElement(['junior', 'mid', 'senior', 'lead', 'manager', 'director']),
        ];
    }
}
