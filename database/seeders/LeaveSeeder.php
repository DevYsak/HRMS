<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class LeaveSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Create standard Leave Types
        $types = [
            ['name' => 'Annual Leave', 'is_paid' => true, 'color' => '#1DB77A'],
            ['name' => 'Sick Leave', 'is_paid' => true, 'color' => '#EF4444'],
            ['name' => 'Casual Leave', 'is_paid' => true, 'color' => '#F59E0B'],
            ['name' => 'Maternity Leave', 'is_paid' => true, 'color' => '#D946EF'],
            ['name' => 'Unpaid Leave', 'is_paid' => false, 'color' => '#6B7280'],
        ];

        $leaveTypes = collect();
        foreach ($types as $type) {
            $leaveTypes->push(LeaveType::firstOrCreate(['name' => $type['name']], $type));
        }

        // 2. Initialize Balances for all employees
        $employees = Employee::all();
        $annualLeave = $leaveTypes->where('name', 'Annual Leave')->first();
        $sickLeave = $leaveTypes->where('name', 'Sick Leave')->first();

        foreach ($employees as $employee) {
            // Give everyone 14 days annual leave and 7 days sick leave
            LeaveBalance::firstOrCreate(
                ['employee_id' => $employee->id, 'leave_type_id' => $annualLeave->id],
                ['allocated_days' => 14, 'used_days' => rand(0, 5)]
            );

            LeaveBalance::firstOrCreate(
                ['employee_id' => $employee->id, 'leave_type_id' => $sickLeave->id],
                ['allocated_days' => 7, 'used_days' => rand(0, 2)]
            );
        }

        // 3. Create some dummy Leave Requests
        $managers = User::whereIn('role', ['admin', 'hr', 'manager'])->get();
        
        foreach ($employees->take(10) as $employee) {
            $startDate = Carbon::now()->addDays(rand(1, 30));
            $days = rand(1, 5);
            $endDate = (clone $startDate)->addDays($days - 1);

            LeaveRequest::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $annualLeave->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days' => $days,
                'reason' => 'Family vacation and resting.',
                'status' => 'pending',
            ]);
        }

        // Create some approved ones
        foreach ($employees->skip(10)->take(10) as $employee) {
            $startDate = Carbon::now()->subDays(rand(1, 30));
            $days = rand(1, 3);
            $endDate = (clone $startDate)->addDays($days - 1);

            LeaveRequest::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $sickLeave->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days' => $days,
                'reason' => 'Feeling unwell.',
                'status' => 'approved',
                'reviewer_id' => $managers->random()->id,
                'reviewer_comment' => 'Get well soon.',
            ]);
        }
    }
}
