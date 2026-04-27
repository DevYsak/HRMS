<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Office;
use Illuminate\Database\Seeder;

class OfficeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $company = Company::first();

        $offices = [
            [
                'name' => 'Head Office — Bangalore',
                'address' => '42, Tech Park, Whitefield',
                'city' => 'Bangalore',
                'country' => 'India',
                'timezone' => 'Asia/Kolkata',
                'is_headquarters' => true,
            ],
            [
                'name' => 'Mumbai Office',
                'address' => 'BKC, Bandra Kurla Complex',
                'city' => 'Mumbai',
                'country' => 'India',
                'timezone' => 'Asia/Kolkata',
                'is_headquarters' => false,
            ],
            [
                'name' => 'Delhi Office',
                'address' => 'Cyber City, Gurugram',
                'city' => 'Delhi NCR',
                'country' => 'India',
                'timezone' => 'Asia/Kolkata',
                'is_headquarters' => false,
            ],
        ];

        foreach ($offices as $office) {
            Office::updateOrCreate(
                ['name' => $office['name'], 'company_id' => $company?->id],
                $office
            );
        }
    }
}
