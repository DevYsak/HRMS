<?php

namespace Database\Factories;

use App\Enums\QaFindingStatus;
use App\Enums\QaSeverity;
use App\Models\QaFinding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QaFinding>
 */
class QaFindingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'QA-F-'.fake()->unique()->numerify('####'),
            'kind' => 'functional',
            'module' => fake()->randomElement(['Attendance', 'Leave', 'Overtime', 'Payroll']),
            'title' => fake()->sentence(5),
            'description' => fake()->sentence(),
            'severity' => QaSeverity::Medium,
            'status' => QaFindingStatus::Open,
            'found_at' => now(),
        ];
    }

    public function critical(): static
    {
        return $this->state(['severity' => QaSeverity::Critical]);
    }

    public function security(QaSeverity $severity = QaSeverity::High): static
    {
        return $this->state(['kind' => 'security', 'severity' => $severity]);
    }

    public function verified(): static
    {
        return $this->state(['status' => QaFindingStatus::Verified, 'closed_at' => now()]);
    }
}
