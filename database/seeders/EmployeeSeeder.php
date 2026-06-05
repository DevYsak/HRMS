<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates Employee records for the core system users (admins only).
 * Real employees are imported via BiometricEmployeeMasterSeeder or
 * the `biometric:sync-employees` command.
 */
class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        $itShift = ShiftSetting::where('name', 'IT Shift')->first();
        $ukShift = ShiftSetting::where('name', 'UK Sales Shift')->first();

        // Only core admin/system accounts get employee records here.
        // No demo users. No legacy fallback emails.
        $mappings = [
            'mazhar@conexus-ns.com' => ['dept' => 'ADMIN', 'shift' => $itShift],
            'shivani@conexus-ns.com' => ['dept' => 'HR',    'shift' => $itShift],
            'rustom@conexus-ns.com' => ['dept' => 'PRD',   'shift' => $itShift],
            'nick@conexus-ns.com' => ['dept' => 'PRD',   'shift' => $ukShift],
            'nikita@conexus-ns.com' => ['dept' => 'PRD',   'shift' => $ukShift],
            'emad@conexus-ns.com' => ['dept' => 'ADMIN', 'shift' => $itShift],
        ];

        foreach ($mappings as $email => $data) {
            $user = User::where('email', $email)->first();

            if (! $user) {
                continue;
            }

            $dept = Department::where('code', $data['dept'])->first();

            $existing = Employee::where('user_id', $user->id)->first();

            if ($existing) {
                $existing->update([
                    'department_id' => $dept?->id,
                    'shift_id' => $data['shift']?->id,
                    'salary_cycle' => 'A',
                    'status' => 'active',
                ]);
            } else {
                Employee::create([
                    'user_id' => $user->id,
                    'employee_id' => 'EMP-'.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT),
                    'department_id' => $dept?->id,
                    'shift_id' => $data['shift']?->id,
                    'joining_date' => now()->subYear(),
                    'status' => 'active',
                    'salary_cycle' => 'A',
                ]);
            }
        }
    }
}
