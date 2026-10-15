<?php

namespace Database\Factories;

use App\Enums\QaSeverity;
use App\Enums\QaTestStatus;
use App\Models\QaTestCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QaTestCase>
 */
class QaTestCaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'QA-T-'.fake()->unique()->numerify('####'),
            'module' => fake()->randomElement(['Attendance', 'Leave', 'Overtime', 'Payroll']),
            'feature' => fake()->words(2, true),
            'role' => fake()->randomElement(['HR Admin', 'Manager', 'Employee']),
            'scope' => fake()->randomElement(['Self', 'Team', 'Department', 'Company']),
            'title' => fake()->sentence(5),
            'steps' => fake()->sentence(),
            'expected_result' => fake()->sentence(),
            'severity' => QaSeverity::Medium,
            'status' => QaTestStatus::NotTested,
        ];
    }
}
