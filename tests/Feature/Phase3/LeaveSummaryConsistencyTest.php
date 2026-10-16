<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use App\Services\Ai\AiDataTools;
use App\Services\AttendanceReportBuilder;
use App\Services\Leave\ConexusLeavePolicyService;
use App\Services\Leave\EmployeeLeaveOverviewService;
use App\Services\Leave\LeaveYearResolver;
use App\Services\People\Employee360Service;
use Illuminate\Support\Carbon;

/**
 * P3-02: one ledger-backed CSL balance in leave year 2026/27 (1 July start)
 * reads the same in My Leave, AI, Employee 360 and the Leave Summary report.
 * Base 10 + carried 3 + accrued 2 + adjustment +1 / −0.5 − expired 1 − used 4
 * = 10.5 approved. Carried forward is counted once; a range ending in
 * February 2027 still means leave year 2026/27, not calendar 2027.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00'));
    $role = Role::where('slug', 'employee')->firstOrFail();
    $this->user = User::factory()->create(['name' => 'Lia Ledger', 'role' => $role->legacyBucket(), 'role_id' => $role->id]);
    $this->employee = Employee::factory()->create(['user_id' => $this->user->id, 'department_id' => Department::factory()->create()->id, 'status' => 'active', 'manager_id' => null]);
    $this->hr = User::factory()->create(['role' => 'hr_admin', 'role_id' => Role::where('slug', 'hr_admin')->value('id')]);
    Employee::factory()->create(['user_id' => $this->hr->id, 'status' => 'active', 'manager_id' => null]);

    $type = LeaveType::where('code', ConexusLeavePolicyService::CSL_CODE)->first()
        ?? LeaveType::create(['name' => 'Casual / Sick Leave', 'code' => ConexusLeavePolicyService::CSL_CODE, 'is_paid' => true, 'allow_paid_request' => true]);
    $this->year = app(LeaveYearResolver::class)->forDate(Carbon::parse('2026-10-14'));

    // Replace whatever was provisioned when the employee was created: one known row.
    LeaveBalance::where('employee_id', $this->employee->id)->delete();
    LeaveBalance::query()->forceCreate([
        'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
        'leave_year_id' => $this->year->id, 'year' => $this->year->legacyYear(),
        'allocated_days' => 16, 'base_days' => 10, 'carried_forward_days' => 3, 'accrued_days' => 2, 'add_on_days' => 0,
        'adjustment_credit_days' => 1, 'adjustment_debit_days' => 0.5, 'opening_days' => 0, 'expired_days' => 1,
        'used_days' => 4, 'encashed_days' => 0, 'ledger_migrated_at' => now(), 'ledger_status' => 'migrated',
    ]);
});

test('My Leave, AI, Employee 360 and the Leave Summary report agree on the balance', function () {
    $overview = app(EmployeeLeaveOverviewService::class)->for($this->employee);
    expect($overview['csl']['summary']['approved_available'])->toBe(10.5);

    $ai = app(AiDataTools::class)->myLeave($this->user);
    expect($ai['casual_sick_leave_approved_balance'])->toBe(10.5);

    $leave360 = app(Employee360Service::class)->build($this->hr, $this->employee, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-14'))['leave'];
    expect($leave360['approved_balance'])->toBe(10.5);

    // The report, for a range ending in February 2027: still leave year 2026/27.
    $report = app(AttendanceReportBuilder::class)->build('leave_summary', ['from' => '2027-02-01', 'to' => '2027-02-28', 'employee_ids' => [$this->employee->id]]);
    $row = array_combine($report['columns'], $report['rows'][0]);

    expect($row['Opening / Entitlement'])->toBe('10.0')
        ->and($row['Carried Forward'])->toBe('3.0')          // once — not added to the opening again
        ->and($row['Accrued / Earned'])->toBe('2.0')
        ->and($row['Adjustments (net)'])->toBe('0.5')
        ->and($row['Expired'])->toBe('1.0')
        ->and($row['Availed'])->toBe('4.0')
        ->and($row['Approved Balance'])->toBe('10.5')
        ->and(collect($report['summary'])->firstWhere('label', 'Leave year')['value'])->toBe($this->year->label);
});

test('the Leave Summary never reads a calendar-year row for another leave year', function () {
    // A stray row keyed to calendar 2027 (leave year 2027/28) must not appear in a February 2027 report.
    LeaveBalance::query()->forceCreate([
        'employee_id' => $this->employee->id, 'leave_type_id' => LeaveType::create(['name' => 'Other', 'code' => 'OTH'])->id,
        'year' => 2027, 'allocated_days' => 99, 'used_days' => 0,
    ]);

    $report = app(AttendanceReportBuilder::class)->build('leave_summary', ['from' => '2027-02-01', 'to' => '2027-02-28', 'employee_ids' => [$this->employee->id]]);
    expect($report['rows'])->toHaveCount(1);
});
