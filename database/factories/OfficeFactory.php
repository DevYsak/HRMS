<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Office>
 */
class OfficeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->randomElement(['Head Office', 'Mumbai Office', 'Delhi Office', 'Bangalore Office', 'Hyderabad Office', 'Chennai Office']),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'country' => 'India',
            'timezone' => 'Asia/Kolkata',
            'is_headquarters' => false,
        ];
    }

    public function headquarters(): static
    {
        return $this->state(['is_headquarters' => true, 'name' => 'Head Office']);
    }
}
