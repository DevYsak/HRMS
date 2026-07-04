<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\HolidayWorkRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HolidayWorkRequest>
 */
class HolidayWorkRequestFactory extends Factory
{
    protected $model = HolidayWorkRequest::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'work_date' => now()->toDateString(),
            'reason' => $this->faker->sentence(),
            'work_location' => 'office',
            'expected_hours' => 8,
            'pay_type' => 'overtime',
            'status' => 'pending',
        ];
    }
}
