<?php

namespace Database\Seeders;

use App\Models\SalaryStructure;
use Illuminate\Database\Seeder;

class SalaryStructureSeeder extends Seeder
{
    public function run(): void
    {
        $structures = [
            ['name' => 'Standard Employee', 'code' => 'STD_EMP', 'description' => 'Default structure for general staff.'],
            ['name' => 'Manager', 'code' => 'MGR', 'description' => 'Structure for managerial roles.'],
            ['name' => 'Senior Manager', 'code' => 'SR_MGR', 'description' => 'Structure for senior management roles.'],
            ['name' => 'Contract Employee', 'code' => 'CONTRACT', 'description' => 'Structure for fixed-term contract staff.'],
            ['name' => 'Intern', 'code' => 'INTERN', 'description' => 'Structure for interns and trainees.'],
        ];

        foreach ($structures as $data) {
            SalaryStructure::withTrashed()->updateOrCreate(
                ['code' => $data['code']],
                [...$data, 'is_active' => true, 'deleted_at' => null]
            );
        }
    }
}
