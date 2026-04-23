<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'employee_id' => 'EMP-' . $this->faker->unique()->numberBetween(1000, 9999),
            'office_id' => \App\Models\Office::inRandomOrder()->first()?->id ?? \App\Models\Office::factory(),
            'department_id' => \App\Models\Department::inRandomOrder()->first()?->id ?? \App\Models\Department::factory(),
            'job_title_id' => \App\Models\JobTitle::inRandomOrder()->first()?->id ?? \App\Models\JobTitle::factory(),
            'manager_id' => User::inRandomOrder()->first()?->id,
            'joining_date' => $this->faker->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            'status' => $this->faker->randomElement(['active', 'active', 'active', 'onboarding', 'probation', 'on-leave', 'resigned', 'terminated']),
            'employment_type' => $this->faker->randomElement(['full-time', 'full-time', 'part-time', 'contract']),
        ];
    }
}
