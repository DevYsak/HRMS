<?php

namespace Database\Seeders;

use App\Models\EmploymentType;
use Illuminate\Database\Seeder;

class EmploymentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Full-time', 'slug' => 'full-time', 'probation_days' => 90, 'notice_period_days' => 30, 'leave_eligible' => true,  'payroll_eligible' => true,  'ot_eligible' => true,  'sort_order' => 1],
            ['name' => 'Part-time', 'slug' => 'part-time', 'probation_days' => 60, 'notice_period_days' => 15, 'leave_eligible' => true,  'payroll_eligible' => true,  'ot_eligible' => false, 'sort_order' => 2],
            ['name' => 'Contract',  'slug' => 'contract',  'probation_days' => 0,  'notice_period_days' => 7,  'leave_eligible' => false, 'payroll_eligible' => true,  'ot_eligible' => false, 'sort_order' => 3],
            ['name' => 'Internship', 'slug' => 'internship', 'probation_days' => 30, 'notice_period_days' => 7,  'leave_eligible' => false, 'payroll_eligible' => true,  'ot_eligible' => false, 'sort_order' => 4],
        ];

        foreach ($types as $data) {
            EmploymentType::updateOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
