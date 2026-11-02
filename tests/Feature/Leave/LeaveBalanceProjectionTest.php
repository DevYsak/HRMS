<?php

use App\Enums\UserRole;
use App\Livewire\TimeOff\LeaveRequestDetail;
use App\Livewire\TimeOff\MyLeaveBalances;
use App\Livewire\TimeOff\RequestLeave;
use App\Models\AttendanceSetting;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ExitRecord;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyAssignment;
use App\Models\LeavePolicyRule;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\Role;
use App\Models\User;
use App\Services\Leave\EarnedLeaveAccrualService as Accrual;
use App\Services\Leave\EarnedLeaveBreakdown;
use App\Services\Leave\LeaveBalanceProjection;
use App\Services\Leave\LeaveCategory;
use App\Services\Leave\LeaveIntegrityService;
use App\Services\Leave\LeaveRequestEvaluator;
use App\Services\LeaveBalanceService;
use App\Services\LeaveService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Booking paid leave against what will have been earned by the leave's own
 * dates (LeaveBalanceProjection): today's balance, plus Earned Leave months
 * completed before the leave starts, minus every other approved or pending
 * booking — checked date by date. Submit and approval read the same figure.
 *
 * Clock: Mon 2 Nov 2026 (leave year 2026/27); July–October are credited
 * (4 days). Saturday and Sunday are off.
 */
beforeEach(function () {
    Notification::fake();
    config(['leave_provisioning.policy_engine_v2' => true]);
    $this->year = LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);
    AttendanceSetting::query()->delete();
    AttendanceSetting::create(['weekly_off_days' => [0, 6]]);
    AttendanceSetting::flushWeeklyOffCache();

    $this->policy = LeavePolicy::create(['name' => 'Conexus Standard Leave Policy', 'statutory_weeks' => 0, 'contractual_additional_weeks' => 0,
        'is_default' => true, 'is_active' => true, 'annual_paid_leave_cap' => 12]);
    LeavePolicyAssignment::create(['leave_policy_id' => $this->policy->id, 'scope' => 'company', 'effective_from' => '2026-07-01']);
    $this->earned = LeaveType::create(['name' => 'Earned Leave', 'code' => 'ELP', 'category' => 'other', 'leave_category' => LeaveCategory::EARNED,
        'is_paid' => true, 'allow_paid_request' => true, 'counts_toward_annual_cap' => true, 'color' => '#10b981']);
    $this->policy->update(['earned_leave_type_id' => $this->earned->id]);
    LeavePolicyRule::create(['leave_policy_id' => $this->policy->id, 'leave_type_id' => $this->earned->id,
        'entitlement_method' => LeavePolicyRule::ENTITLEMENT_FIXED, 'fixed_days' => 12,
        'accrual_method' => LeavePolicyRule::ACCRUAL_MONTHLY, 'accrual_amount' => 1, 'is_active' => true]);
    $this->casual = LeaveType::create(['name' => 'Casual Leave', 'code' => 'CLP', 'category' => 'other', 'leave_category' => LeaveCategory::EARNED,
        'is_paid' => true, 'allow_paid_request' => true, 'color' => '#f59e0b']);
    LeavePolicyRule::create(['leave_policy_id' => $this->policy->id, 'leave_type_id' => $this->casual->id, 'entitlement_method' => LeavePolicyRule::ENTITLEMENT_NONE,
        'accrual_method' => LeavePolicyRule::ACCRUAL_UPFRONT, 'is_active' => true]);

    $hrRole = Role::where('slug', 'hr_admin')->firstOrFail();
    $this->hr = User::factory()->create(['role' => $hrRole->legacyBucket(), 'role_id' => $hrRole->id, 'name' => 'Hema HR']);

    $this->employee = lpEmployee('LP-001');
    lpAccrue($this->employee, '2026-11-01');
    $this->travelTo(Carbon::parse('2026-11-02 10:00:00'));
});

function lpEmployee(string $code, array $attributes = []): Employee
{
    return Employee::factory()->create(['user_id' => User::factory()->create(['role' => UserRole::Employee])->id, 'status' => 'active',
        'joining_date' => '2020-12-16', 'employee_id' => $code, 'manager_id' => null, 'department_id' => Department::factory()->create()->id] + $attributes);
}

/** The scheduled monthly run on $date. */
function lpAccrue(Employee $employee, string $date): void
{
    test()->travelTo(Carbon::parse($date.' 00:20:00'));
    $accrual = app(Accrual::class);
    $row = $accrual->planFor($employee->fresh(), $accrual->yearFor(Carbon::parse($date)), Carbon::parse($date));
    if ($row['status'] === Accrual::READY) {
        $accrual->apply($row);
    }
}

/** A paid Casual request as the employee filed it, still pending. */
function lpPending(string $start, string $end, float $days): LeaveRequest
{
    return LeaveRequest::create(['employee_id' => test()->employee->id, 'leave_type_id' => test()->casual->id, 'main_category' => LeaveCategory::EARNED,
        'balance_leave_type_id' => test()->earned->id, 'start_date' => $start, 'end_date' => $end, 'days' => $days,
        'reason' => 'Family function', 'status' => 'pending', 'requested_leave_status' => 'paid']);
}

function lpEvaluate(string $start, string $end, ?int $exclude = null): array
{
    return app(LeaveRequestEvaluator::class)->evaluate(test()->employee->fresh(), test()->casual, Carbon::parse($start), Carbon::parse($end), false, $exclude);
}

function lpSubmit(string $start, string $end): LeaveRequest
{
    return app(LeaveService::class)->submitRequest(test()->employee->fresh(), test()->casual, $start, $end, 'Family function', false, null, 'paid', null, null, []);
}

function lpApprove(LeaveRequest $request): LeaveRequest
{
    return app(LeaveService::class)->reviewRequest($request->fresh(), ['leave_type_id' => $request->leave_type_id,
        'start_date' => $request->start_date->toDateString(), 'end_date' => $request->end_date->toDateString(), 'reason' => $request->reason, 'is_half_day' => false,
    ], 'approved', test()->hr->id);
}

function lpAvailable(): float
{
    return app(EarnedLeaveBreakdown::class)->for(test()->employee->fresh(), test()->year)['total_available'];
}

test('with October already applied for in November, December leave can still use the November accrual — never December\'s own', function () {
    lpPending('2026-10-20', '2026-10-23', 4);            // past-dated, pending: today's 4 days are reserved

    $december = lpEvaluate('2026-12-15', '2026-12-15');
    expect($december)->toMatchArray(['blocked' => false, 'paid_days' => 1.0, 'available' => 1.0, 'remaining_after' => 0.0])
        ->and($december['projection'])->toMatchArray(['available_now' => 0.0, 'balance_today' => 4.0, 'expected_accrual' => 1.0,
            'expected_months' => ['2026-11'], 'booked_by_start' => 4.0, 'capacity' => 1.0])
        ->and($december['projection']['bookings'])->toHaveCount(1);

    $two = lpEvaluate('2026-12-14', '2026-12-15');
    expect($two['blocked'])->toBeTrue()
        ->and($two['errors'][0])->toBe('You have only 1 Earned Leave day(s) available. For 14 Dec 2026: 4 balance today + 1 expected monthly accrual (Nov) − 4 already booked on or before that date = 1.');

    // 30 November: November is not yet complete, so nothing is expected by then.
    expect(lpEvaluate('2026-11-30', '2026-11-30'))->toMatchArray(['blocked' => true, 'available' => 0.0])
        ->and(lpEvaluate('2026-11-30', '2026-11-30')['projection']['expected_accrual'])->toBe(0.0);
});

test('bookings are checked date by date: a later booking relies on later accrual, and no day is promised twice', function () {
    lpPending('2027-02-02', '2027-02-05', 4);            // February, relying on Nov–Jan accrual

    // Today's figure alone would refuse it (4 − 4 = 0); by its own date the November leave fits.
    expect(lpEvaluate('2026-11-20', '2026-11-20'))->toMatchArray(['blocked' => false, 'paid_days' => 1.0])
        ->and(lpEvaluate('2026-11-20', '2026-11-20')['projection'])->toMatchArray(['available_now' => 0.0, 'available_at_start' => 4.0, 'kept_for_later' => 1.0, 'capacity' => 3.0])
        ->and(lpEvaluate('2026-11-20', '2026-11-20')['projection']['limited_by']['start_date'])->toBe('2027-02-02');

    // Four days in November would leave February short: 4 + 3 accrued by then − 8 booked.
    $four = lpEvaluate('2026-11-23', '2026-11-26');
    expect($four['blocked'])->toBeTrue()
        ->and($four['errors'][0])->toContain('only 3 Earned Leave day(s) available')
        ->and($four['errors'][0])->toContain('− 1 kept for your leave on 02 Feb 2027 = 3.');
});

test('leave approved ahead of its accrual is deducted once at approval; until the accrual arrives the balance is below zero by that leave only, and integrity stays clean', function () {
    $request = lpSubmit('2026-12-14', '2026-12-18');     // 5 days: 4 now + November
    expect($request->days)->toEqual(5.0);

    lpApprove($request);
    expect(LeaveLedgerEntry::where('source_type', 'leave_request')->where('source_id', $request->id)->where('entry_type', LeaveLedgerEntry::TYPE_USAGE)->count())->toBe(1)
        ->and(lpAvailable())->toBe(-1.0)
        ->and(app(EarnedLeaveBreakdown::class)->for($this->employee->fresh(), $this->year)['booked_ahead'])->toBe(5.0)
        ->and(app(LeaveIntegrityService::class)->scan($this->year)->all())->toBe([]);
    $this->artisan('leave:verify', ['--year' => '2026/27'])->assertSuccessful();
    // The employee's balance card says why it is below zero.
    Livewire::actingAs($this->employee->user)->test(MyLeaveBalances::class)
        ->assertSeeHtml('data-booked-ahead')->assertSee('booked against monthly accrual still to come');

    // Nothing more can be booked before the accrual that leave relies on.
    expect(lpEvaluate('2026-11-25', '2026-11-25')['blocked'])->toBeTrue();

    lpAccrue($this->employee, '2026-12-01');
    expect(lpAvailable())->toBe(0.0)
        ->and(app(LeaveIntegrityService::class)->scan($this->year)->all())->toBe([]);
});

test('approval re-checks the same figure: refused when the paid days no longer fit, and nothing is deducted', function () {
    $request = lpSubmit('2026-12-14', '2026-12-18');
    app(LeaveBalanceService::class)->adjust($this->employee->fresh(), $this->earned, 'debit', 1, 'Correction', '', $this->hr, 2026,
        effectiveDate: Carbon::parse('2026-11-02'));

    expect(fn () => lpApprove($request))->toThrow(DomainException::class, 'Cannot approve: 5 paid day(s) no longer fit Earned Leave — only 4 day(s) are available for these dates.');
    expect($request->fresh()->status)->toBe('pending')
        ->and(LeaveLedgerEntry::where('source_type', 'leave_request')->where('source_id', $request->id)->exists())->toBeFalse();
});

test('the projection excludes the request itself, never counts unpaid or rejected requests, and stops at the last working day', function () {
    $own = lpPending('2026-12-14', '2026-12-14', 1);
    LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->casual->id, 'balance_leave_type_id' => $this->earned->id,
        'start_date' => '2026-12-21', 'end_date' => '2026-12-21', 'days' => 1, 'reason' => 'Unpaid', 'status' => 'pending', 'requested_leave_status' => 'unpaid']);
    LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->casual->id, 'balance_leave_type_id' => $this->earned->id,
        'start_date' => '2026-12-22', 'end_date' => '2026-12-22', 'days' => 1, 'reason' => 'Rejected', 'status' => 'rejected', 'requested_leave_status' => 'paid']);

    $projection = app(LeaveBalanceProjection::class)->for($this->employee->fresh(), $this->earned, Carbon::parse('2026-12-14'), $own->id);
    expect($projection)->toMatchArray(['bookings' => [], 'capacity' => 5.0, 'expected_months' => ['2026-11']]);

    // Leaving 15 December: November still counts for December leave, January's leave gets nothing more.
    $this->employee->update(['status' => 'notice_period']);
    ExitRecord::create(['employee_id' => $this->employee->id, 'last_working_day' => '2026-12-15', 'exit_type' => 'resignation']);
    expect(app(LeaveBalanceProjection::class)->for($this->employee->fresh(), $this->earned, Carbon::parse('2027-01-20'), $own->id)['expected_months'])->toBe(['2026-11']);
});

test('the request drawer shows the same breakdown as soon as dates are chosen, with the other requests already holding days', function () {
    $october = lpPending('2026-10-20', '2026-10-23', 4);

    Livewire::actingAs($this->employee->user)->test(RequestLeave::class)
        ->call('openDrawer')
        ->call('chooseCategory', LeaveCategory::EARNED)
        ->call('next')
        ->set('leaveTypeId', $this->casual->id)
        ->set('startDate', '2026-12-15')
        ->set('endDate', '2026-12-15')
        ->set('reason', 'Family function out of town.')
        ->assertSeeHtml('data-dates-balance')
        ->assertSee('Your balance for these dates')
        ->assertSee('Expected monthly accrual by')
        ->assertSee('Nov 2026')
        ->assertSee('Already booked on or before')
        ->assertSeeHtml('data-booking="LR-'.str_pad((string) $october->id, 4, '0', STR_PAD_LEFT).'"')
        ->call('next')
        ->assertSet('step', 3)
        ->assertSee('Earned Leave available before')
        ->assertSee('Remaining after approval')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('step', 4);
});

test('HR sees the same figures and the employee\'s other requests on the request page; approval refuses what no longer fits', function () {
    $october = lpPending('2026-10-20', '2026-10-23', 4);
    $december = lpSubmit('2026-12-15', '2026-12-15');

    $page = Livewire::actingAs($this->hr)->test(LeaveRequestDetail::class, ['leaveRequest' => $december]);
    $page->assertOk()->assertSeeHtml('data-balance-projection')
        ->assertSee("The employee's other approved and pending requests")
        ->assertSeeHtml('data-booking="LR-'.str_pad((string) $october->id, 4, '0', STR_PAD_LEFT).'"');
    expect($page->instance()->projection)->toBe(lpEvaluate('2026-12-15', '2026-12-15', $december->id)['projection']);

    // HR approves October first: December still fits (4 now + November − 4).
    lpApprove($october);
    lpApprove($december);
    expect($december->fresh()->status)->toBe('approved')
        ->and(lpAvailable())->toBe(-1.0);
});
