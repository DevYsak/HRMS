<?php

use App\Enums\UserRole;
use App\Livewire\Overtime\ManageOtRequests;
use App\Livewire\Payroll\Overview;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\EmployeePayrollSettings;
use App\Models\EmployeeSalary;
use App\Models\OtRequest;
use App\Models\OvertimeRecord;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Services\AttendanceReportBuilder;
use App\Services\OvertimeService;
use App\Services\PayrollService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Phase 4 OT policy: payable OT = an approved request + genuine attendance.
 * A record still at 0 hours (missing checkout) is never paid at 0 and lost —
 * it is carried until real hours exist. Unapproved OT is never "payable".
 */
function ocEmployee(): Employee
{
    $employee = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active', 'manager_id' => null, 'joining_date' => '2024-01-08', 'salary_cycle' => 'cycle_a',
    ]);
    $basic = SalaryComponent::firstOrCreate(['code' => 'BASIC'], ['name' => 'Basic Salary', 'type' => 'earning', 'component_type' => 'earning',
        'calculation_type' => 'fixed', 'default_amount' => 0, 'is_active' => true, 'display_order' => 1]);
    EmployeeSalary::create(['employee_id' => $employee->id, 'salary_component_id' => $basic->id, 'amount' => 23000, 'effective_from' => '2024-01-01']);
    EmployeePayrollSettings::create(array_merge(EmployeePayrollSettings::defaults($employee->id)->toArray(), ['employee_id' => $employee->id]));

    return $employee->fresh();
}

function ocOt(Employee $employee, string $date, float $hours, string $status = 'approved'): OvertimeRecord
{
    $attendance = Attendance::create(['employee_id' => $employee->id, 'date' => $date, 'check_in' => Carbon::parse("{$date} 09:00"),
        'check_out' => $hours > 0 ? Carbon::parse("{$date} 18:00")->addMinutes((int) ($hours * 60)) : null, 'status' => 'on_time', 'work_mode' => 'office']);
    $request = OtRequest::create(['employee_id' => $employee->id, 'attendance_id' => $attendance->id, 'work_date' => $date, 'start_time' => '18:00', 'end_time' => '20:00',
        'requested_hours' => 2, 'reason' => 'Release', 'status' => $status]);

    return OvertimeRecord::query()->forceCreate(['employee_id' => $employee->id, 'ot_request_id' => $request->id, 'attendance_id' => $attendance->id, 'work_date' => $date,
        'total_hours_worked' => 9 + $hours, 'standard_hours' => 9, 'ot_hours' => $hours, 'ot_amount' => $hours * 100, 'rate_per_hour' => 100, 'is_paid' => false]);
}

beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-08-05 10:00'));
    AttendanceSetting::query()->delete();
    AttendanceSetting::create(['shift_start' => '09:00', 'shift_end' => '18:00', 'weekly_off_days' => [0, 6], 'ot_rate_per_hour' => 100]);
    AttendanceSetting::flushWeeklyOffCache();
    $this->admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
});

test('a zero-hour approved OT is not paid at 0: it is carried to the run after its hours exist', function () {
    $employee = ocEmployee();
    $record = ocOt($employee, '2026-07-15', 0);   // checkout still missing at July payroll

    $july = app(PayrollService::class)->generateDraft('July', 2026, 'cycle_a', $this->admin->id);
    expect($record->fresh()->payslip_id)->toBeNull();
    $july->update(['status' => 'finalized']);

    // The genuine checkout arrives later: 2 hours.
    $record->update(['ot_hours' => 2, 'ot_amount' => 200]);
    $this->travelTo(Carbon::parse('2026-09-03 10:00'));
    $august = app(PayrollService::class)->generateDraft('August', 2026, 'cycle_a', $this->admin->id);

    $slip = Payslip::where('payroll_id', $august->id)->where('employee_id', $employee->id)->firstOrFail();
    expect($slip->items()->where('name', 'like', 'OT — arrears (2h%')->value('amount'))->toEqual(200)
        ->and($record->fresh()->payslip_id)->toBe($slip->id);
});

test('settling a record on a draft payslip re-measures its hours instead of keeping stale ones', function () {
    $employee = ocEmployee();
    $record = ocOt($employee, '2026-07-15', 1);
    $draft = app(PayrollService::class)->generateDraft('July', 2026, 'cycle_a', $this->admin->id);
    expect($record->fresh()->payslip_id)->not->toBeNull();

    $record->attendance->update(['check_out' => Carbon::parse('2026-07-15 20:00')]);   // corrected: 2h beyond the shift
    app(OvertimeService::class)->settleApprovedForDay($employee, Carbon::parse('2026-07-15'));

    expect((float) $record->fresh()->ot_hours)->toBeGreaterThan(1.0)
        ->and($draft->fresh()->status)->toBe('draft');
});

test('the OT report lists only approved OT as payable, with its payroll status', function () {
    $employee = ocEmployee();
    ocOt($employee, '2026-07-15', 2);
    ocOt($employee, '2026-07-16', 3, status: 'rejected');
    ocOt($employee, '2026-07-17', 0);

    $report = app(AttendanceReportBuilder::class)->build('overtime', ['from' => '2026-07-01', 'to' => '2026-07-31', 'employee_ids' => [$employee->id]]);
    $statuses = collect($report['rows'])->pluck(6)->all();

    expect($report['rows'])->toHaveCount(2)
        ->and($report['columns'][6])->toBe('Payroll Status')
        ->and($statuses)->toBe(['Payable — next payroll', 'Waiting for attendance']);
});

test('Manage OT exports the filtered requests on screen, and Payroll Overview exports the selected year', function () {
    $hrRole = Role::where('slug', 'hr_admin')->firstOrFail();
    $hr = User::factory()->create(['role' => $hrRole->legacyBucket(), 'role_id' => $hrRole->id]);
    $employee = ocEmployee();
    ocOt($employee, '2026-07-15', 2);
    ocOt($employee, '2026-07-16', 3, status: 'rejected');

    $csv = Livewire::actingAs($hr)->test(ManageOtRequests::class)->set('filterStatus', 'rejected')->call('exportCsv')->effects['download']['content'] ?? '';
    $csv = base64_decode($csv);
    expect(substr_count($csv, 'Rejected'))->toBe(1)->and($csv)->not->toContain('Approved');

    $html = Livewire::actingAs($this->admin)->test(Overview::class)->set('filterYear', '2025')->html();
    expect($html)->toContain('month=12')->toContain('year=2025');
});
