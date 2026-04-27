<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\JobTitle;
use Illuminate\Database\Seeder;

class JobTitleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $company = Company::first();

        $titles = [
            'Software Engineer', 'Senior Software Engineer', 'Lead Engineer', 'Engineering Manager',
            'UI UX Designer', 'Graphic Designer', 'Creative Director',
            'Product Manager', 'Senior Product Manager',
            'HR Manager', 'HR Executive', 'Talent Acquisition Specialist',
            'Finance Analyst', 'Finance Manager',
            'Marketing Manager', 'Content Writer', 'Growth Manager',
            'Project Manager', 'Operations Manager',
            'Sales Executive', 'Sales Manager',
        ];

        foreach ($titles as $title) {
            JobTitle::updateOrCreate(
                ['name' => $title, 'company_id' => $company?->id],
                ['name' => $title]
            );
        }
    }
}
