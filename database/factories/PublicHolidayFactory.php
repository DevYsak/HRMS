<?php

namespace Database\Factories;

use App\Enums\HolidayType;
use App\Models\PublicHoliday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublicHoliday>
 */
class PublicHolidayFactory extends Factory
{
    protected $model = PublicHoliday::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement(['Diwali', 'Holi', 'Independence Day', 'Christmas', 'New Year']),
            'date' => $this->faker->dateTimeBetween('-1 month', '+6 months')->format('Y-m-d'),
            'country' => 'IN',
            'holiday_type' => $this->faker->randomElement(HolidayType::cases())->value,
            'is_paid' => true,
            'is_optional' => false,
            'is_recurring' => false,
            'is_active' => true,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function optional(): static
    {
        return $this->state(fn () => ['holiday_type' => 'optional', 'is_optional' => true]);
    }
}
