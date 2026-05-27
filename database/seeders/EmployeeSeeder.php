<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use Illuminate\Database\Seeder;

class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        $itShift = ShiftSetting::where('name', 'IT Shift')->first();
        $ukShift = ShiftSetting::where('name', 'UK Sales Shift')->first();

        // email → [dept code, shift]
        // Includes both new spec emails and legacy fallback emails.
        $mappings = [
            // New spec users (UserSeeder v2)
            'mazhar@conexus-ns.com' => ['code' => 'ADMIN', 'shift' => $itShift],
            'shivani@conexus-ns.com' => ['code' => 'HR',    'shift' => $itShift],
            'rustom@conexus-ns.com' => ['code' => 'PRD',   'shift' => $itShift],
            'nick@conexus-ns.com' => ['code' => 'PRD',   'shift' => $ukShift],
            'nikia@conexus-ns.com' => ['code' => 'PRD',   'shift' => $ukShift],
            'emad@conexus-ns.com' => ['code' => 'ADMIN', 'shift' => $itShift],
            'employee@conexus-ns.com' => ['code' => 'PRD',   'shift' => $itShift],

            // Legacy emails (UserSeeder v1 — backward compat with existing DBs)
            'admin@conexus-ns.com' => ['code' => 'ADMIN', 'shift' => $itShift],
            'pristia@conexus-ns.com' => ['code' => 'HR',    'shift' => $itShift],
            'rayna@conexus-ns.com' => ['code' => 'PRD',   'shift' => $itShift],
            'test@example.com' => ['code' => 'PRD',   'shift' => $itShift],
        ];

        foreach ($mappings as $email => $data) {
            $user = User::where('email', $email)->first();
            if (! $user) {
                continue;
            }

            $dept = Department::where('code', $data['code'])->first();

            $existing = Employee::where('user_id', $user->id)->first();

            if ($existing) {
                // Only update non-conflicting fields — never touch employee_id on existing records
                $existing->update([
                    'department_id' => $dept?->id,
                    'shift_id' => $data['shift']?->id,
                    'salary_cycle' => 'A',
                    'status' => 'active',
                ]);
            } else {
                // New employee — generate unique ID from user ID
                Employee::create([
                    'user_id' => $user->id,
                    'employee_id' => 'EMP-'.str_pad($user->id, 4, '0', STR_PAD_LEFT),
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
