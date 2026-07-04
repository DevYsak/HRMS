<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AttendanceReports;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\AttendanceReportBuilder;
use Livewire\Livewire;

function reportsHr(): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin]);
}

test('a regular employee cannot open the reports page', function () {
    $employee = Employee::factory()->create();

    Livewire::actingAs($employee->user)->test(AttendanceReports::class)
        ->assertForbidden();
});

test('the reports page renders with the report type picker for HR', function () {
    Livewire::actingAs(reportsHr())->test(AttendanceReports::class)
        ->assertOk()
        ->assertSee('Attendance Reports')
        ->assertSee('Muster Roll')
        ->assertSee('Overtime Report')
        ->assertSee('Biometric Report');
});

test('the daily report builds rows and a present-count summary', function () {
    $emp = Employee::factory()->create(['status' => 'active']);
    Attendance::create([
        'employee_id' => $emp->id, 'date' => today(),
        'check_in' => today()->setTime(9, 5), 'check_out' => today()->setTime(18, 0),
        'status' => 'on_time', 'work_mode' => 'office', 'total_hours' => 8.5,
    ]);

    $report = app(AttendanceReportBuilder::class)->build('daily', [
        'from' => today()->toDateString(), 'to' => today()->toDateString(),
    ]);

    expect($report['title'])->toBe('Daily Report');
    expect($report['rows'])->toHaveCount(1);
    expect(collect($report['summary'])->firstWhere('label', 'Present')['value'])->toBe(1);
});

test('the late report only includes late arrivals and totals them', function () {
    $emp = Employee::factory()->create(['status' => 'active']);
    Attendance::create([
        'employee_id' => $emp->id, 'date' => today(),
        'check_in' => today()->setTime(10, 0), 'is_late' => true, 'late_minutes' => 25,
        'status' => 'late', 'work_mode' => 'office',
    ]);
    Attendance::create([
        'employee_id' => $emp->id, 'date' => today()->subDay(),
        'check_in' => today()->subDay()->setTime(9, 0), 'is_late' => false,
        'status' => 'on_time', 'work_mode' => 'office',
    ]);

    $report = app(AttendanceReportBuilder::class)->build('late', [
        'from' => today()->subDay()->toDateString(), 'to' => today()->toDateString(),
    ]);

    expect($report['rows'])->toHaveCount(1);
    expect(collect($report['summary'])->firstWhere('label', 'Total Late')['value'])->toBe('0h 25m');
});

test('the department filter narrows report rows', function () {
    $deptA = App\Models\Department::factory()->create();
    $deptB = App\Models\Department::factory()->create();
    $empA = Employee::factory()->create(['status' => 'active', 'department_id' => $deptA->id]);
    $empB = Employee::factory()->create(['status' => 'active', 'department_id' => $deptB->id]);
    foreach ([$empA, $empB] as $e) {
        Attendance::create([
            'employee_id' => $e->id, 'date' => today(),
            'check_in' => today()->setTime(9, 0), 'status' => 'on_time', 'work_mode' => 'office',
        ]);
    }

    $all = app(AttendanceReportBuilder::class)->build('daily', ['from' => today()->toDateString(), 'to' => today()->toDateString()]);
    $filtered = app(AttendanceReportBuilder::class)->build('daily', [
        'from' => today()->toDateString(), 'to' => today()->toDateString(), 'department_id' => $empA->department_id,
    ]);

    expect(count($all['rows']))->toBe(2);
    expect(count($filtered['rows']))->toBe(1);
});

test('the holiday report lists public holidays in range', function () {
    PublicHoliday::create(['date' => today(), 'name' => 'Test Founders Day', 'country' => 'IN']);

    $report = app(AttendanceReportBuilder::class)->build('holiday', [
        'from' => today()->startOfMonth()->toDateString(), 'to' => today()->endOfMonth()->toDateString(),
    ]);

    expect($report['rows'])->toHaveCount(1);
    expect($report['rows'][0])->toContain('Test Founders Day');
});

test('HR can download the attendance report as CSV', function () {
    $emp = Employee::factory()->create(['status' => 'active']);
    Attendance::create([
        'employee_id' => $emp->id, 'date' => today(),
        'check_in' => today()->setTime(9, 0), 'status' => 'on_time', 'work_mode' => 'office',
    ]);

    $this->actingAs(reportsHr())
        ->get(route('reports.attendance-report-csv', ['type' => 'daily', 'from' => today()->toDateString(), 'to' => today()->toDateString()]))
        ->assertOk()
        ->assertDownload();
});

test('the overtime report totals hours and amount', function () {
    $emp = Employee::factory()->create(['status' => 'active']);
    OvertimeRecord::create([
        'employee_id' => $emp->id, 'work_date' => today(), 'total_hours_worked' => 11,
        'standard_hours' => 9, 'ot_hours' => 2, 'rate_per_hour' => 100, 'ot_amount' => 200, 'is_paid' => false,
    ]);

    $report = app(AttendanceReportBuilder::class)->build('overtime', [
        'from' => today()->startOfMonth()->toDateString(), 'to' => today()->toDateString(),
    ]);

    expect($report['rows'])->toHaveCount(1);
    expect(collect($report['summary'])->firstWhere('label', 'Total OT Hours')['value'])->toBe('2.0h');
});
