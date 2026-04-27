<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;

class EmployeeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $mappings = [
            'admin@conexus.in' => ['code' => 'ADMIN', 'emp_id' => 'EMP-0001'],
            'pristia@conexus.in' => ['code' => 'HR',    'emp_id' => 'EMP-0002'],
            'rayna@conexus.in' => ['code' => 'PRD',   'emp_id' => 'EMP-0003'],
            'test@example.com' => ['code' => 'PRD',   'emp_id' => 'EMP-0004'],
        ];

        foreach ($mappings as $email => $data) {
            $user = User::where('email', $email)->first();
            $dept = Department::where('code', $data['code'])->first();

            if ($user && $dept) {
                Employee::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'employee_id' => $data['emp_id'],
                        'department_id' => $dept->id,
                        'joining_date' => now()->subYears(1),
                        'status' => 'active',
                    ]
                );
            }
        }
    }
}
