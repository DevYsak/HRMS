<?php

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\AttendanceReportBuilder;

/**
 * The four reports HR asked for that had no equivalent in the system:
 * Monthly Attendance Register, Payroll Attendance, Leave Summary and
 * Comp-Off Summary. These are what HR will diff against their own
 * spreadsheet, so the day codes and day counts have to be exact.
 */
function reportEmployee(string $name = 'Report Person'): Employee
{
    $user = User::factory()->create(['name' => $name]);

    return Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);
}

function markAttendance(Employee $employee, string $date, array $attributes = []): Attendance
{
    return Attendance::create(array_merge([
        'employee_id' => $employee->id,
        'date' => $date,
        'check_in' => $date.' 10:30:00',
        'check_out' => $date.' 19:30:00',
        'status' => 'on_time',
        'work_mode' => 'office',
        'total_hours' => 9,
    ], $attributes));
}

/** 2026-06-01 is a Monday, so the week runs Mon 1 → Sun 7. */
function junWeek(): array
{
    return ['from' => '2026-06-01', 'to' => '2026-06-07'];
}

test('the register produces one column per day plus the trailing totals', function () {
    reportEmployee();

    $report = app(AttendanceReportBuilder::class)->build('register', junWeek());

    // 3 identity columns + 7 days + 8 total columns.
    expect($report['columns'])->toHaveCount(3 + 7 + 8)
        ->and($report['title'])->toBe('Monthly Attendance Register')
        ->and(array_slice($report['columns'], 0, 3))->toBe(['Emp ID', 'Employee', 'Department'])
        ->and(array_slice($report['columns'], -8))->toBe(['P', 'A', 'L', 'HD', 'LV', 'WO', 'H', 'Payable Days']);

    expect($report['rows'][0])->toHaveCount(count($report['columns']));
});

test('the register classifies each day with the code HR uses', function () {
    $employee = reportEmployee();
    $leaveType = LeaveType::firstOrCreate(['code' => 'CL'], ['name' => 'Casual Leave', 'category' => 'annual']);

    markAttendance($employee, '2026-06-01');                                        // Mon — present
    markAttendance($employee, '2026-06-02', ['is_late' => true, 'late_minutes' => 20]); // Tue — late
    markAttendance($employee, '2026-06-03', ['status' => 'half_day']);               // Wed — half day
    // Thu 4 — no record at all → absent
    LeaveRequest::create([                                                           // Fri 5 — approved leave
        'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-05', 'end_date' => '2026-06-05',
        'days' => 1, 'total_days' => 1, 'status' => 'approved', 'reason' => 'Personal',
    ]);
    // Sat 6 — counted absent (weekly off is Sunday-only today)
    // Sun 7 — weekly off

    $report = app(AttendanceReportBuilder::class)->build('register', junWeek());
    $row = $report['rows'][0];

    // Day cells sit between the 3 identity columns and the 8 totals.
    expect(array_slice($row, 3, 7))->toBe(['P', 'L', 'HD', 'A', 'LV', 'A', 'WO']);

    // Totals: P counts late as present, so 1 + 1 = 2.
    expect(array_slice($row, -8))->toBe([2, 2, 1, 1, 1, 1, 0, 4.5]);
});

test('the payroll attendance report separates payable days from loss of pay', function () {
    $employee = reportEmployee();
    $leaveType = LeaveType::firstOrCreate(['code' => 'CL'], ['name' => 'Casual Leave', 'category' => 'annual']);

    markAttendance($employee, '2026-06-01');
    markAttendance($employee, '2026-06-02', ['is_late' => true, 'late_minutes' => 20]);
    markAttendance($employee, '2026-06-03', ['status' => 'half_day']);
    LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-05', 'end_date' => '2026-06-05',
        'days' => 1, 'total_days' => 1, 'status' => 'approved', 'reason' => 'Personal',
    ]);

    $report = app(AttendanceReportBuilder::class)->build('payroll_attendance', junWeek());
    $row = $report['rows'][0];

    // Calendar, Present, Half, Leave, WeeklyOff, Holiday, Absent, LOP, Payable
    expect(array_slice($row, 3))->toBe([7, 2, 1, 1, 1, 0, 2, 2.5, 4.5]);

    // Payable + LOP must account for every calendar day.
    expect($row[11] + $row[10])->toBe(7.0);
});

test('a public holiday is paid and never counted as an absence', function () {
    reportEmployee();
    PublicHoliday::create(['date' => '2026-06-04', 'name' => 'Test Holiday', 'country' => 'IN']);

    $report = app(AttendanceReportBuilder::class)->build('register', junWeek());
    $row = $report['rows'][0];

    expect(array_slice($row, 3, 7)[3])->toBe('H');       // Thursday is the holiday
    expect(array_slice($row, -8)[6])->toBe(1);           // H total
});

test('the leave summary rolls balances up per employee and leave type', function () {
    $employee = reportEmployee();
    $annual = LeaveType::firstOrCreate(['code' => 'EL'], ['name' => 'Earned Leave', 'category' => 'annual']);

    LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $annual->id, 'year' => 2026,
        'allocated_days' => 12, 'used_days' => 4, 'carried_forward_days' => 3, 'encashed_days' => 1,
    ]);

    $report = app(AttendanceReportBuilder::class)->build('leave_summary', junWeek());
    $row = collect($report['rows'])->first(fn ($r) => $r[3] === 'Earned Leave');

    expect($report['columns'])->toBe([
        'Emp ID', 'Employee', 'Department', 'Leave Type',
        'Opening', 'Carried Forward', 'Availed', 'Pending', 'Encashed', 'Closing Balance',
    ]);

    expect($row[4])->toBe('15.0')   // opening = allocated + carried forward
        ->and($row[5])->toBe('3.0')
        ->and($row[6])->toBe('4.0')
        ->and($row[8])->toBe('1.0')
        ->and($row[9])->toBe('7.0'); // closing = 12 - 4 - 1
});

test('the comp-off summary only includes comp-off balances', function () {
    $employee = reportEmployee();
    $compOff = LeaveType::firstOrCreate(['code' => 'CO'], ['name' => 'Comp Off', 'category' => 'comp_off']);
    $annual = LeaveType::firstOrCreate(['code' => 'EL'], ['name' => 'Earned Leave', 'category' => 'annual']);

    LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $compOff->id, 'year' => 2026,
        'allocated_days' => 3, 'used_days' => 1, 'comp_off_credits' => 3,
    ]);
    LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $annual->id, 'year' => 2026,
        'allocated_days' => 12, 'used_days' => 2,
    ]);

    $report = app(AttendanceReportBuilder::class)->build('comp_off', junWeek());

    expect($report['rows'])->toHaveCount(1)
        ->and($report['columns'])->toBe(['Emp ID', 'Employee', 'Department', 'Earned', 'Availed', 'Pending', 'Balance']);

    expect($report['rows'][0][3])->toBe('3.0')  // earned
        ->and($report['rows'][0][4])->toBe('1.0')  // availed
        ->and($report['rows'][0][6])->toBe('2.0'); // balance
});

test('the register is not capped, unlike the muster roll', function () {
    // Muster hard-limits to 60 employees; the register must show everyone,
    // since HR reconciles it against their full sheet.
    foreach (range(1, 8) as $i) {
        reportEmployee("Bulk Person {$i}");
    }

    $report = app(AttendanceReportBuilder::class)->build('register', junWeek());

    expect($report['rows'])->toHaveCount(8);
});

test('every registered report type builds without error', function () {
    reportEmployee();

    $builder = app(AttendanceReportBuilder::class);

    foreach (array_keys(AttendanceReportBuilder::TYPES) as $type) {
        $report = $builder->build($type, junWeek());

        expect($report['columns'])->not->toBeEmpty("report '{$type}' returned no columns");

        foreach ($report['rows'] as $row) {
            expect($row)->toHaveCount(count($report['columns']), "report '{$type}' has a row/column width mismatch");
        }
    }
});
