<?php

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyAssignment;
use App\Models\LeavePolicyRule;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Leave\LeaveCategory;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveMovementService;
use App\Services\LeaveService;
use App\Services\Payroll\PayableDaysService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Leave V2 — payroll receives the policy's paid / LWP split. The LWP portion
 * of a paid request reaches payroll through the same unpaid-units path as an
 * unpaid request, so it is LOP and stays in the attendance-% denominator.
 */
beforeEach(function () {
    Notification::fake();
    config(['leave_provisioning.policy_engine_v2' => true]);
    $this->travelTo(Carbon::parse('2026-12-07 10:00:00'));
    LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);
});

test('an over-balance request converted to LWP is paid for the covered days and LOP for the rest', function () {
    $employee = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id, 'status' => 'active',
        'joining_date' => '2024-01-10', 'manager_id' => null, 'department_id' => Department::factory()->create()->id,
    ]);
    $policy = LeavePolicy::create([
        'name' => 'NEXCORE Standard Leave Policy', 'statutory_weeks' => 0, 'contractual_additional_weeks' => 0,
        'is_default' => false, 'is_active' => true, 'annual_paid_leave_cap' => 12,
    ]);
    LeavePolicyAssignment::create(['leave_policy_id' => $policy->id, 'scope' => 'company', 'effective_from' => '2026-07-01']);
    // One Earned Leave balance; Sick Leave is a reason that draws on it.
    $earned = LeaveType::create([
        'name' => 'Earned Leave', 'code' => 'EL'.random_int(100, 999), 'category' => 'other', 'leave_category' => LeaveCategory::EARNED,
        'counts_toward_annual_cap' => true, 'is_paid' => true, 'allow_paid_request' => true, 'color' => '#10b981',
    ]);
    $policy->update(['earned_leave_type_id' => $earned->id]);
    LeavePolicyRule::create([
        'leave_policy_id' => $policy->id, 'leave_type_id' => $earned->id, 'entitlement_method' => LeavePolicyRule::ENTITLEMENT_FIXED,
        'fixed_days' => 12, 'accrual_method' => LeavePolicyRule::ACCRUAL_MONTHLY, 'excess_handling' => 'convert_to_lwp', 'is_active' => true,
    ]);
    $sick = LeaveType::create([
        'name' => 'Sick Leave', 'code' => 'SL'.random_int(100, 999), 'category' => 'other', 'leave_category' => LeaveCategory::EARNED,
        'is_paid' => true, 'allow_paid_request' => true, 'color' => '#10b981',
    ]);
    LeavePolicyRule::create([
        'leave_policy_id' => $policy->id, 'leave_type_id' => $sick->id, 'entitlement_method' => LeavePolicyRule::ENTITLEMENT_NONE,
        'fixed_days' => null, 'accrual_method' => LeavePolicyRule::ACCRUAL_UPFRONT, 'is_active' => true,
    ]);
    $balance = app(LeaveMovementService::class)->balanceFor($employee->id, $earned->id, Carbon::parse('2026-07-01'));
    app(LeaveMovementService::class)->ledgerReady($balance);
    app(LeaveLedgerService::class)->credit($balance->fresh(), LeaveLedgerEntry::TYPE_BASE, 2, Carbon::parse('2026-07-01'), 'v2-payroll');
    app(LeaveLedgerService::class)->rebuild($balance->fresh());

    // Mon 7 – Wed 9 Dec: 3 working days, 2 covered by Earned Leave.
    $service = app(LeaveService::class);
    $request = $service->submitRequest($employee, $sick, '2026-12-07', '2026-12-09', 'Fever');
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $service->reviewRequest($request, $request->only(['leave_type_id', 'start_date', 'end_date', 'reason']), 'approved', $hr->id);

    expect((float) $request->fresh()->lwp_days)->toBe(1.0)
        ->and($request->fresh()->balance_leave_type_id)->toBe($earned->id);

    $period = app(PayableDaysService::class)->forPeriod($employee->fresh(), Carbon::parse('2026-12-01'), Carbon::parse('2026-12-31'));

    expect($period['paid_leave_days'])->toBe(2.0)
        ->and($period['unpaid_leave_days'])->toBe(1.0)
        // The LWP day stays in the attendance denominator; the paid days do not.
        ->and($period['days']['2026-12-09']['lop'])->toBe(1.0)
        ->and($period['days']['2026-12-07']['lop'])->toBe(0.0);

    // Attendance % (QA-F-079): once the days are past, the LWP day is eligible
    // but not attended; the two paid days are neither.
    $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));
    $leaveDays = app(PayableDaysService::class)->forPeriod($employee->fresh(), Carbon::parse('2026-12-07'), Carbon::parse('2026-12-09'));

    expect($leaveDays['attendance_eligible_units'])->toBe(1.0)
        ->and($leaveDays['attended_units'])->toBe(0.0);
});
