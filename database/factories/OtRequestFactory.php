<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\OtRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OtRequest>
 */
class OtRequestFactory extends Factory
{
    protected $model = OtRequest::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'work_date' => $this->faker->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'start_time' => '18:00',
            'end_time' => '20:00',
            'requested_hours' => $this->faker->randomFloat(2, 1, 4),
            'reason' => 'Test OT request',
            'status' => 'pending',
            'source' => 'manual',
            'nexflow_ref' => null,
        ];
    }

    public function nexflow(): static
    {
        return $this->state(fn (array $attributes): array => [
            'source' => 'nexflow',
            'nexflow_ref' => 'NF-'.$this->faker->numerify('######'),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'approved',
        ]);
    }
}
