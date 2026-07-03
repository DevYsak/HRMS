<?php

namespace Database\Factories;

use App\Models\AttendancePunch;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendancePunch>
 */
class AttendancePunchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $at = now()->setTime(9, 0);

        return [
            'employee_id' => Employee::factory(),
            'employee_code' => fake()->numberBetween(1, 999),
            'punched_at' => $at,
            'punch_date' => $at->toDateString(),
            'method' => 'face',
            'verify_raw' => '15',
            'source' => 'biometric',
            'device_serial' => 'AIFACE-MAGNUM',
        ];
    }
}
