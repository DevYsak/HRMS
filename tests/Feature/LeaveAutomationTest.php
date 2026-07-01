<?php

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Services\LeaveBalanceService;

test('creating an employee auto-assigns balances from types with a default allocation', function () {
    $annual = LeaveType::create(['name' => 'Annual Leave', 'annual_allocation_days' => 12]);
    $sick = LeaveType::create(['name' => 'Sick Leave', 'annual_allocation_days' => 7]);
    $lop = LeaveType::create(['name' => 'Loss of Pay']); // no default allocation

    $employee = Employee::factory()->create();
    $year = now()->year;

    $annualBalance = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $annual->id)->where('year', $year)->first();
    $sickBalance = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $sick->id)->where('year', $year)->first();

    expect($annualBalance)->not->toBeNull();
    expect((float) $annualBalance->allocated_days)->toBe(12.0);
    expect((float) $sickBalance->allocated_days)->toBe(7.0);

    // Types without a default allocation are not seeded.
    expect(LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $lop->id)->exists())->toBeFalse();
});

test('re-initializing is idempotent and never resets used balances', function () {
    $annual = LeaveType::create(['name' => 'Annual Leave', 'annual_allocation_days' => 12]);
    $employee = Employee::factory()->create();

    $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $annual->id)->first();
    $balance->update(['used_days' => 3]);

    app(LeaveBalanceService::class)->initializeForEmployee($employee, now()->year);

    expect(LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $annual->id)->count())->toBe(1);
    expect((float) $balance->fresh()->used_days)->toBe(3.0);
    expect((float) $balance->fresh()->allocated_days)->toBe(12.0);
});
