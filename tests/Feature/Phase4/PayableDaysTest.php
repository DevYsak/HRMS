<?php

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\EmployeePayrollSettings;
use App\Models\EmployeeSalary;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\PayslipDayAdjustment;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Services\AttendanceReportBuilder;
use App\Services\Payroll\PayableDaysService;
use App\Services\PayrollService;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 4 payroll policy (approved 16 Oct 2026) — one canonical payable-days
 * answer. July 2026, cycle A (1–31 Jul), Saturday + Sunday off: 23 eligible
 * working days. Basic 23,000 → 1,000 per working day.
 */
function pdEmployee(string $name, array $attributes = []): Employee
{
    $employee = Employee::factory()->create([
        'user_id' => User::factory()->create(['name' => $name, 'role' => UserRole::Employee])->id,
        'status' => 'active', 'manager_id' => null, 'joining_date' => '2024-01-08', 'salary_cycle' => 'cycle_a', ...$attributes,
    ]);
    $basic = SalaryComponent::firstOrCreate(['code' => 'BASIC'], [
        'name' => 'Basic Salary', 'type' => 'earning', 'component_type' => 'earning',
        'calculation_type' => 'fixed', 'default_amount' => 0, 'is_active' => true, 'display_order' => 1,
    ]);
    EmployeeSalary::create(['employee_id' => $employee->id, 'salary_component_id' => $basic->id, 'amount' => 23000, 'effective_from' => '2024-01-01']);
    EmployeePayrollSettings::create(array_merge(EmployeePayrollSettings::defaults($employee->id)->toArray(), ['employee_id' => $employee->id]));

    return $employee->fresh();
}

/** Attendance on every weekday of the range except the given dates. */
function pdAttend(Employee $employee, string $from, string $to, array $except = []): void
{
    foreach (CarbonPeriod::create($from, $to) as $day) {
        if ($day->isWeekend() || in_array($day->toDateString(), $except, true)) {
            continue;
        }
        Attendance::create(['employee_id' => $employee->id, 'date' => $day->toDateString(), 'check_in' => $day->copy()->setTime(9, 0),
            'check_out' => $day->copy()->setTime(18, 0), 'status' => 'on_time', 'work_mode' => 'office']);
    }
}

function pdLeave(Employee $employee, LeaveType $type, string $from, string $to, float $days, string $status = 'approved', ?string $paid = null): LeaveRequest
{
    return LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => $from, 'end_date' => $to,
        'days' => $days, 'status' => $status, 'reason' => 'Scenario', 'requested_leave_status' => $paid]);
}

function pdPayable(Employee $employee, string $from = '2026-07-01', string $to = '2026-07-31'): array
{
    return app(PayableDaysService::class)->forPeriod($employee->fresh(), Carbon::parse($from), Carbon::parse($to));
}

beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-08-05 10:00'));
    AttendanceSetting::query()->delete();
    AttendanceSetting::create(['shift_start' => '09:00', 'shift_end' => '18:00', 'weekly_off_days' => [0, 6]]);
    AttendanceSetting::flushWeeklyOffCache();
    $this->admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $this->casual = LeaveType::create(['name' => 'Casual / Sick', 'is_paid' => true]);
    $this->unpaid = LeaveType::create(['name' => 'Leave Without Pay', 'is_paid' => false, 'category' => 'unpaid']);
});

test('absence, unpaid leave, paid leave and a pending request are classified once', function () {
    $ava = pdEmployee('Ava');
    pdAttend($ava, '2026-07-01', '2026-07-31', ['2026-07-07', '2026-07-08', '2026-07-10', '2026-07-14', '2026-07-15']);
    pdLeave($ava, $this->unpaid, '2026-07-10', '2026-07-10', 1, paid: 'unpaid');
    pdLeave($ava, $this->casual, '2026-07-15', '2026-07-15', 1);
    pdLeave($ava, $this->casual, '2026-07-14', '2026-07-14', 1, status: 'pending');   // still explains 14 Jul

    $p = pdPayable($ava);

    expect([$p['eligible_working_days'], $p['present_days'], $p['absent_days'], $p['unpaid_leave_days'], $p['paid_leave_days'], $p['pending_days']])
        ->toBe([23, 18.0, 2.0, 1.0, 1.0, 1.0])
        ->and([$p['lop_days'], $p['paid_days']])->toBe([3.0, 20.0])
        ->and($p['days']['2026-07-07']['code'])->toBe(PayableDaysService::CODE_ABSENT)
        ->and($p['days']['2026-07-14']['code'])->toBe(PayableDaysService::CODE_PENDING)
        ->and($p['days']['2026-07-11']['code'])->toBe(PayableDaysService::CODE_WEEKLY_OFF);
});

test('a joiner is paid only the eligible working days from the joining date', function () {
    $jo = pdEmployee('Jo Joiner', ['joining_date' => '2026-07-20']);
    pdAttend($jo, '2026-07-20', '2026-07-31');

    $p = pdPayable($jo);
    expect([$p['eligible_working_days'], $p['not_employed_working_days'], $p['working_days'], $p['lop_days'], $p['paid_days']])->toBe([23, 13, 10, 0.0, 10.0]);

    $payroll = app(PayrollService::class)->generateDraft('July', 2026, 'cycle_a', $this->admin->id);
    $slip = Payslip::where('payroll_id', $payroll->id)->where('employee_id', $jo->id)->firstOrFail();
    expect($slip->items()->where('name', 'Not employed — 13 of 23 working days')->value('amount'))->toEqual(13000)
        ->and((float) $slip->net_salary)->toBe(10000.0);
});

test('only the excess over the paid-leave balance is LWP, in the July → June leave year', function () {
    $ben = pdEmployee('Ben');
    pdAttend($ben, '2026-07-01', '2026-07-31', ['2026-07-20', '2026-07-21', '2026-07-22', '2026-07-23', '2026-07-24']);
    pdLeave($ben, $this->casual, '2026-07-20', '2026-07-24', 5);
    // Leave year 2026/27 (balances keyed 2026): 2 days available, the 5-day request overdrew it by 3.
    LeaveBalance::create(['employee_id' => $ben->id, 'leave_type_id' => $this->casual->id, 'year' => 2026, 'allocated_days' => 2, 'used_days' => 5]);

    $p = pdPayable($ben);
    expect([$p['paid_leave_days'], $p['unpaid_leave_days'], $p['lop_days']])->toBe([2.0, 3.0, 3.0])
        ->and($p['days']['2026-07-20']['code'])->toBe(PayableDaysService::CODE_PAID_LEAVE)
        ->and($p['days']['2026-07-24']['code'])->toBe(PayableDaysService::CODE_UNPAID_LEAVE);

    // February 2027 is still leave year 2026/27 — never the calendar year 2027.
    $this->travelTo(Carbon::parse('2027-03-05 10:00'));
    $cy = pdEmployee('Cy');
    pdAttend($cy, '2027-02-01', '2027-02-28', ['2027-02-10']);
    pdLeave($cy, $this->casual, '2027-02-10', '2027-02-10', 1);
    LeaveBalance::create(['employee_id' => $cy->id, 'leave_type_id' => $this->casual->id, 'year' => 2026, 'allocated_days' => 0, 'used_days' => 1]);
    expect(pdPayable($cy, '2027-02-01', '2027-02-28')['lop_days'])->toBe(1.0);
});

test('with no attendance recorded at all, absences are reported but not deducted', function () {
    $nia = pdEmployee('Nia Untracked');

    $p = pdPayable($nia);
    expect($p['attendance_tracked'])->toBeFalse()
        ->and([$p['absent_days'], $p['lop_days'], $p['pending_days'], $p['paid_days']])->toBe([0.0, 0.0, 23.0, 23.0]);
});

test('the payroll engine, the payslip snapshot and the Payroll Attendance report agree', function () {
    $ava = pdEmployee('Ava');
    pdAttend($ava, '2026-07-01', '2026-07-31', ['2026-07-07', '2026-07-08', '2026-07-10']);
    pdLeave($ava, $this->unpaid, '2026-07-10', '2026-07-10', 1, paid: 'unpaid');

    $payroll = app(PayrollService::class)->generateDraft('July', 2026, 'cycle_a', $this->admin->id);
    $slip = Payslip::where('payroll_id', $payroll->id)->where('employee_id', $ava->id)->firstOrFail();
    $snap = $slip->payable_snapshot;

    // 3 LOP days at 23,000 / 23.
    expect([$snap['eligible_working_days'], $snap['lop_days'], $snap['paid_days']])->toBe([23, 3, 20])
        ->and($slip->items()->where('name', 'LWP (3 days)')->value('amount'))->toEqual(3000)
        ->and((float) $slip->net_salary)->toBe(20000.0);

    $report = app(AttendanceReportBuilder::class)->build('payroll_attendance', ['from' => '2026-07-01', 'to' => '2026-07-31', 'employee_ids' => [$ava->id]]);
    $row = array_combine($report['columns'], $report['rows'][0]);
    expect([$row['Eligible Working Days'], (float) $row['LOP Days'], (float) $row['Paid Days']])->toBe([23, 3.0, 20.0]);

    // The PDF reads the snapshot: an attendance edit after generation changes nothing on it.
    Attendance::where('employee_id', $ava->id)->whereDate('date', '2026-07-21')->delete();
    $html = view('pdf.payslip', ['payslip' => $slip->fresh(['items', 'employee.user', 'payroll'])])->render();
    expect($html)->toContain('LOP Days')->toMatch('/LOP Days<\/span><span class="val">3</');
});

test('a correction after the run closed becomes one arrear in the next open run, never twice', function () {
    $ava = pdEmployee('Ava');
    pdAttend($ava, '2026-07-01', '2026-08-31', ['2026-07-14']);
    $pending = pdLeave($ava, $this->unpaid, '2026-07-14', '2026-07-14', 1, status: 'pending', paid: 'unpaid');

    $july = app(PayrollService::class)->generateDraft('July', 2026, 'cycle_a', $this->admin->id);
    expect(Payslip::where('payroll_id', $july->id)->where('employee_id', $ava->id)->first()->payable_snapshot['lop_days'])->toEqual(0);
    $july->update(['status' => 'finalized']);

    // Decided after July closed: the day becomes unpaid leave.
    $pending->update(['status' => 'approved']);

    $this->travelTo(Carbon::parse('2026-09-03 10:00'));
    $august = app(PayrollService::class)->generateDraft('August', 2026, 'cycle_a', $this->admin->id);
    app(PayrollService::class)->generateDraft('August', 2026, 'cycle_a', $this->admin->id);   // a draft re-run

    $slip = Payslip::where('payroll_id', $august->id)->where('employee_id', $ava->id)->firstOrFail();
    expect($slip->items()->where('name', 'LWP adjustment — 01 Jul – 31 Jul 2026 (1 day)')->value('amount'))->toEqual(1000)
        ->and(PayslipDayAdjustment::where('employee_id', $ava->id)->count())->toBe(1);

    // Once August is closed too, September adds nothing more.
    $august->update(['status' => 'finalized']);
    $this->travelTo(Carbon::parse('2026-10-05 10:00'));
    pdAttend($ava, '2026-09-01', '2026-09-30');
    $september = app(PayrollService::class)->generateDraft('September', 2026, 'cycle_a', $this->admin->id);
    expect(Payslip::where('payroll_id', $september->id)->where('employee_id', $ava->id)->first()->items()->where('name', 'like', 'LWP adjustment%')->exists())->toBeFalse()
        ->and(PayslipDayAdjustment::where('employee_id', $ava->id)->count())->toBe(1);
});
