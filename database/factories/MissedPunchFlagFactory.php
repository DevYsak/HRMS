<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\MissedPunchFlag;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<MissedPunchFlag>
 */
class MissedPunchFlagFactory extends Factory
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
            'week_start' => Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString(),
            'missed_count' => 6,
            'allowance' => 5,
            'flagged_at' => now(),
        ];
    }

    /** HR has reviewed the flag. */
    public function reviewed(): static
    {
        return $this->state(fn () => ['reviewed_at' => now(), 'review_note' => 'Spoke to the employee.']);
    }
}
