<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\WfhReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WfhReport>
 */
class WfhReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'date' => now()->toDateString(),
            'work_summary' => fake()->paragraph(),
            'achievements' => fake()->sentence(),
            'blockers' => null,
            'tomorrow_plan' => fake()->sentence(),
        ];
    }
}
