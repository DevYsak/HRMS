<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\ShiftSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds realistic demo data: attendance history, leave balances, and leave requests
 * for the named Conexus employees (mazhar, shivani, rustom, nick, nikia, emad, employee).
 *
 * Safe to re-run: uses updateOrCreate / firstOrCreate for idempotency.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $employees = Employee::with(['user', 'shift'])->get();

        if ($employees->isEmpty()) {
            $this->command->warn('No employees found. Run EmployeeSeeder first.');

            return;
        }

        $this->seedLeaveBalances($employees);
        $this->seedAttendanceHistory($employees);
        $this->seedLeaveRequests($employees);

        $this->command->info("Demo data seeded for {$employees->count()} employees.");
    }

    private function seedLeaveBalances($employees): void
    {
        $leaveTypes = LeaveType::all();
        $year = now()->year;

        foreach ($employees as $employee) {
            foreach ($leaveTypes as $type) {
                LeaveBalance::firstOrCreate(
                    ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year],
                    [
                        'allocated_days' => match (strtolower($type->category ?? '')) {
                            'casual' => 12,
                            'sick' => 8,
                            'comp_off' => 2,
                            default => 10,
                        },
                        'used_days' => 0,
                        'carried_forward_days' => 0,
                        'encashed_days' => 0,
                        'comp_off_credits' => 0,
                    ]
                );
            }
        }
    }

    private function seedAttendanceHistory($employees): void
    {
        $today = Carbon::today();
        $defaultShift = ShiftSetting::first();

        foreach ($employees as $employee) {
            $shift = $employee->shift ?? $defaultShift;
            if (! $shift) {
                continue;
            }

            // Seed last 30 working days
            $day = $today->copy()->subDays(1);
            $seeded = 0;

            while ($seeded < 30) {
                // Skip weekends
                if ($day->isWeekend()) {
                    $day->subDay();

                    continue;
                }

                $dateStr = $day->toDateString();

                // Skip ~15% of days (absent/leave)
                if (rand(1, 100) <= 15) {
                    $day->subDay();
                    $seeded++;

                    continue;
                }

                $existing = Attendance::where('employee_id', $employee->id)->where('date', $dateStr)->first();

                if (! $existing) {
                    $shiftStart = Carbon::parse($dateStr.' '.$shift->start_time);
                    // Random delay: 80% on time (0–5 min), 20% late (6–25 min)
                    $delayMinutes = rand(1, 100) <= 80 ? rand(0, 5) : rand(6, 25);
                    $checkIn = $shiftStart->copy()->addMinutes($delayMinutes);
                    $checkOut = $checkIn->copy()->addHours(9)->addMinutes(rand(-10, 20));
                    $isLate = $delayMinutes > ($shift->grace_minutes ?? 5);
                    $breakMinutes = rand(30, 75);

                    Attendance::create([
                        'employee_id' => $employee->id,
                        'date' => $dateStr,
                        'check_in' => $checkIn,
                        'check_out' => $checkOut,
                        'is_late' => $isLate,
                        'late_minutes' => $isLate ? $delayMinutes - ($shift->grace_minutes ?? 5) : 0,
                        'break_minutes' => $breakMinutes,
                        'status' => $isLate ? 'late' : 'on_time',
                        'work_mode' => rand(1, 100) <= 80 ? 'office' : 'wfh',
                        'check_in_ip' => '192.168.1.'.rand(10, 50),
                    ]);
                }

                $day->subDay();
                $seeded++;
            }
        }
    }

    private function seedLeaveRequests($employees): void
    {
        $leaveTypes = LeaveType::all();

        if ($leaveTypes->isEmpty()) {
            return;
        }

        $statuses = ['approved', 'approved', 'approved', 'rejected', 'pending'];

        foreach ($employees as $employee) {
            // Each employee gets 3–5 historical leave requests
            $count = rand(3, 5);

            for ($i = 0; $i < $count; $i++) {
                $type = $leaveTypes->random();
                $daysAgo = rand(5, 120);
                $start = Carbon::today()->subDays($daysAgo);
                $duration = rand(1, 3);
                $end = $start->copy()->addDays($duration - 1);
                $status = $statuses[array_rand($statuses)];

                // Don't duplicate same employee+date range
                $exists = LeaveRequest::where('employee_id', $employee->id)
                    ->where('start_date', $start->toDateString())
                    ->exists();

                if ($exists) {
                    continue;
                }

                $request = LeaveRequest::create([
                    'employee_id' => $employee->id,
                    'leave_type_id' => $type->id,
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'days' => $duration,
                    'is_half_day' => false,
                    'reason' => $this->randomReason(),
                    'status' => $status,
                    'reviewer_id' => $status !== 'pending' ? 1 : null,
                    'reviewer_comment' => $status === 'rejected' ? 'Insufficient team coverage on those dates.' : null,
                    'created_at' => $start->copy()->subDays(rand(1, 5)),
                ]);

                // Deduct from leave balance if approved
                if ($status === 'approved' && $type->is_paid) {
                    $balance = LeaveBalance::where('employee_id', $employee->id)
                        ->where('leave_type_id', $type->id)
                        ->where('year', $start->year)
                        ->first();

                    if ($balance) {
                        $balance->increment('used_days', $duration);
                    }
                }
            }
        }
    }

    private function randomReason(): string
    {
        return collect([
            'Personal work at home.',
            'Medical appointment with doctor.',
            'Family function — attending sibling wedding.',
            'Not feeling well, need rest.',
            'Travel for personal work.',
            'Festival celebration with family.',
            'Emergency home repair.',
            'Child school event.',
        ])->random();
    }
}
