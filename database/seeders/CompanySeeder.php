<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Company::updateOrCreate(
            ['email' => 'hr@conexus-ns.com'],
            [
                'name' => 'Conexus Technologies',
                'website' => 'https://conexus.in',
                'industry' => 'Technology',
                'phone' => '+91 98765 43210',
                'address' => '42, Tech Park, Whitefield',
                'city' => 'Bangalore',
                'country' => 'India',
                // Professional-tax fallback state. Only a Maharashtra PT rule is
                // seeded; add a jurisdiction-scoped rule (e.g. KA) before running
                // payroll for employees whose office sits in another state.
                'default_state_code' => 'MH',
                'timezone' => 'Asia/Kolkata',
                'date_format' => 'd M Y',
                'currency' => 'INR',
                'currency_symbol' => '₹',
            ]
        );
    }
}
