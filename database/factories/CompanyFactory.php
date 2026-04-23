<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'website' => fake()->url(),
            'industry' => fake()->randomElement(['Technology', 'Finance', 'Healthcare', 'Education', 'Retail', 'Manufacturing']),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'country' => fake()->country(),
            'timezone' => 'Asia/Kolkata',
            'date_format' => 'd M Y',
            'currency' => 'INR',
            'currency_symbol' => '₹',
        ];
    }
}
