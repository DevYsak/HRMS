<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $company = Company::first();

        $departments = [
            ['code' => 'ADMIN', 'name' => 'Admin'],
            ['code' => 'HR',    'name' => 'HR'],
            ['code' => 'PRD',   'name' => 'Production'],
            ['code' => 'LOG',   'name' => 'Logistics'],
            ['code' => 'MKT',   'name' => 'Marketing'],
            ['code' => 'IT',    'name' => 'IT'],
            ['code' => 'FIN',   'name' => 'Finance'],
        ];

        foreach ($departments as $dept) {
            Department::updateOrCreate(
                ['code' => $dept['code']],
                [
                    'company_id' => $company?->id,
                    'name' => $dept['name'],
                ]
            );
        }
    }
}
