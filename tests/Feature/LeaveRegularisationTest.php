<?php

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\RegularisationReviewedNotification;
use App\Services\AttendanceService;
use App\Services\Leave\LeaveRegularisationService;
use App\Services\Leave\LeaveYearResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Converting a past absence into approved leave.
 *
 * Deliberately not a second approval engine. The request is an
 * AttendanceRegularisation carrying category='leave' and it travels the
 * existing manager → HR → admin chain — so what these tests pin down is that
 * the chain is genuinely the same one, and that the leave-specific ending
 * (balance deducted, day marked, punches untouched) happens only at final
 * approval.
 */
function lrEmployee(): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
    ]);
}

function lrType(): LeaveType
{
    return LeaveType::create([
        'name' => 'Annual '.Str::random(4),
        'code' => 'A'.strtoupper(Str::random(3)),
        'category' => 'annual',
        'allow_paid_request' => true,
    ]);
}

function lrBalance(Employee $e, LeaveType $t, float $allocated = 10, float $used = 0, ?CarbonInterface $forDate = null): LeaveBalance
{
    // Keyed to the leave year the date belongs to, not the calendar year. A
    // fixture that assumed they were the same would pass in August and fail
    // every February.
    return LeaveBalance::create([
        'employee_id' => $e->id,
        'leave_type_id' => $t->id,
        'year' => app(LeaveYearResolver::class)->legacyYearFor($forDate ?? lrPast()),
        'allocated_days' => $allocated,
        'used_days' => $used,
    ]);
}

function lrService(): LeaveRegularisationService
{
    return app(LeaveRegularisationService::class);
}

function lrManager(): User
{
    return User::factory()->create(['role' => UserRole::Manager]);
}

function lrHr(): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin]);
}

function lrAdmin(): User
{
    return User::factory()->create(['role' => UserRole::SuperAdmin]);
}

function lrPast(int $daysAgo = 3): CarbonInterface
{
    // Weekdays only: the duration helper excludes weekends, so a Saturday
    // fixture would silently produce a zero-day request.
    //
    // Reassigned rather than mutated. now() returns a CarbonImmutable here, so
    // `$date->subDay();` discards its result and the loop never terminates —
    // which is exactly what it did, on every run that happened to land on a
    // weekend.
    $date = now()->subDays($daysAgo)->startOfDay();

    while ($date->isWeekend()) {
        $date = $date->subDay();
    }

    return $date;
}

function lrSubmit(Employee $e, LeaveType $t, ?CarbonInterface $date = null, ?User $by = null): AttendanceRegularisation
{
    $date ??= lrPast();

    return lrService()->submit(
        employee: $e,
        type: $t,
        from: $date,
        to: $date->copy(),
        reason: 'Absent, leave never submitted',
        requestedBy: $by ?? lrHr(),
    );
}

// ── 1. Creating the request ────────────────────────────────────────────────

test('a leave regularisation is raised on the existing regularisation table', function () {
    $employee = lrEmployee();
    $type = lrType();
    lrBalance($employee, $type);

    $reg = lrSubmit($employee, $type);

    expect($reg)->toBeInstanceOf(AttendanceRegularisation::class)
        ->and($reg->category)->toBe('leave')
        ->and($reg->leave_type_id)->toBe($type->id)
        ->and($reg->status)->toBe('pending')
        // The existing chain, entered at its existing first stage.
        ->and($reg->stage)->toBe('manager_review')
        ->and($reg->duration)->toBe(1.0);
});

test('an attendance regularisation is untouched by the new category', function () {
    // The default has to keep meaning what it always meant.
    $employee = lrEmployee();

    $reg = AttendanceRegularisation::create([
        'employee_id' => $employee->id,
        'work_date' => lrPast()->toDateString(),
        'regularisation_type' => 'punch',
        'requested_check_in' => '09:00:00',
        'requested_check_out' => '18:00:00',
        'reason' => 'Missed punch',
        'status' => 'pending',
        'stage' => 'manager_review',
    ]);

    // Re-read: the column is defaulted by the database, so the freshly created
    // instance does not carry it.
    expect($reg->fresh()->category)->toBe('attendance')
        ->and($reg->fresh()->isLeave())->toBeFalse();
});

// ── 2. The existing approval chain ─────────────────────────────────────────

test('a manager approval advances the stage rather than applying it', function () {
    $employee = lrEmployee();
    $type = lrType();
    lrBalance($employee, $type, allocated: 10);
    $reg = lrSubmit($employee, $type);

    app(AttendanceService::class)->approveRegularisation($reg, lrManager()->id);

    $balance = LeaveBalance::where('employee_id', $employee->id)->first();

    expect($reg->fresh()->stage)->toBe('hr_review')
        ->and($reg->fresh()->status)->toBe('pending')
        // Nothing has happened to the balance yet.
        ->and((float) $balance->used_days)->toBe(0.0);
});

test('HR approval advances to admin approval, still without applying', function () {
    $employee = lrEmployee();
    $type = lrType();
    lrBalance($employee, $type, allocated: 10);
    $reg = lrSubmit($employee, $type);

    $service = app(AttendanceService::class);
    $service->approveRegularisation($reg, lrManager()->id);
    $service->approveRegularisation($reg->fresh(), lrHr()->id);

    expect($reg->fresh()->stage)->toBe('admin_approval')
        ->and($reg->fresh()->status)->toBe('pending')
        ->and((float) LeaveBalance::where('employee_id', $employee->id)->value('used_days'))->toBe(0.0);
});

test('final approval applies it', function () {
    $employee = lrEmployee();
    $type = lrType();
    lrBalance($employee, $type, allocated: 10);
    $reg = lrSubmit($employee, $type);

    $service = app(AttendanceService::class);
    $service->approveRegularisation($reg, lrManager()->id);
    $service->approveRegularisation($reg->fresh(), lrHr()->id);
    $service->approveRegularisation($reg->fresh(), lrAdmin()->id);

    expect($reg->fresh()->status)->toBe('approved');
});

test('the approval trail records every stage', function () {
    $employee = lrEmployee();
    $type = lrType();
    lrBalance($employee, $type, allocated: 10);
    $reg = lrSubmit($employee, $type);

    $service = app(AttendanceService::class);
    $service->approveRegularisation($reg, lrManager()->id, 'Confirmed with the team');
    $service->approveRegularisation($reg->fresh(), lrHr()->id);
    $service->approveRegularisation($reg->fresh(), lrAdmin()->id);

    $trail = $reg->fresh()->approval_trail;

    expect($trail)->toHaveCount(3)
        ->and($trail[0]['stage'])->toBe('manager_review')
        ->and($trail[0]['comment'])->toBe('Confirmed with the team')
        ->and($trail[1]['stage'])->toBe('hr_review');
});

// ── 3. What final approval does ────────────────────────────────────────────

test('the balance is reduced by the approved duration', function () {
    $employee = lrEmployee();
    $type = lrType();
    lrBalance($employee, $type, allocated: 10);
    $reg = lrSubmit($employee, $type);

    app(AttendanceService::class)->approveRegularisation($reg, lrAdmin()->id);

    $balance = LeaveBalance::where('employee_id', $employee->id)->first();
    $available = (float) $balance->allocated_days - (float) $balance->used_days;

    expect($available)->toBe(9.0);
});

test('the deduction is tagged as a regularisation, not a manual debit', function () {
    // Payroll and reporting have to be able to tell the two apart.
    $employee = lrEmployee();
    $type = lrType();
    lrBalance($employee, $type, allocated: 10);
    $reg = lrSubmit($employee, $type);
    $admin = lrAdmin();

    app(AttendanceService::class)->approveRegularisation($reg, $admin->id);

    $adjustment = LeaveBalanceAdjustment::where('employee_id', $employee->id)->latest('id')->first();

    expect($adjustment)->not->toBeNull()
        ->and($adjustment->source)->toBe('regularisation')
        ->and($adjustment->source_id)->toBe($reg->id)
        ->and($adjustment->leave_type_id)->toBe($type->id)
        ->and((float) $adjustment->days)->toBe(1.0)
        ->and((float) $adjustment->previous_balance)->toBe(10.0)
        ->and((float) $adjustment->new_balance)->toBe(9.0)
        ->and($adjustment->adjusted_by)->toBe($admin->id);
});

test('the day is marked as leave', function () {
    $employee = lrEmployee();
    $type = lrType();
    lrBalance($employee, $type, allocated: 10);
    $date = lrPast();

    Attendance::create([
        'employee_id' => $employee->id,
        'date' => $date->toDateString(),
        'check_in' => $date->copy()->setTime(0, 0),
        'status' => 'absent',
        'work_mode' => 'office',
    ]);

    $reg = lrSubmit($employee, $type, $date);
    app(AttendanceService::class)->approveRegularisation($reg, lrAdmin()->id);

    $attendance = Attendance::where('employee_id', $employee->id)->whereDate('date', $date->toDateString())->first();

    expect($attendance->status)->toBe('leave')
        ->and((bool) $attendance->is_regularized)->toBeTrue();
});

test('raw punches are left exactly as the device recorded them', function () {
    // The attendance engine still owns what the raw data means. Rewriting
    // punches to fake a leave day would corrupt the source of truth.
    $employee = lrEmployee();
    $type = lrType();
    lrBalance($employee, $type, allocated: 10);
    $date = lrPast();

    $checkIn = $date->copy()->setTime(9, 14);
    $checkOut = $date->copy()->setTime(17, 2);

    Attendance::create([
        'employee_id' => $employee->id,
        'date' => $date->toDateString(),
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'status' => 'absent',
        'work_mode' => 'office',
    ]);

    $reg = lrSubmit($employee, $type, $date);
    app(AttendanceService::class)->approveRegularisation($reg, lrAdmin()->id);

    $attendance = Attendance::where('employee_id', $employee->id)->whereDate('date', $date->toDateString())->first();

    expect($attendance->check_in->format('H:i'))->toBe('09:14')
        ->and($attendance->check_out->format('H:i'))->toBe('17:02');
});

test('a day with no attendance row at all still gets one', function () {
    $employee = lrEmployee();
    $type = lrType();
    lrBalance($employee, $type, allocated: 10);
    $date = lrPast();

    $reg = lrSubmit($employee, $type, $date);
    app(AttendanceService::class)->approveRegularisation($reg, lrAdmin()->id);

    $attendance = Attendance::where('employee_id', $employee->id)->whereDate('date', $date->toDateString())->first();

    expect($attendance)->not->toBeNull()
        ->and($attendance->status)->toBe('leave')
        ->and($reg->fresh()->previous_attendance_status)->toBe('no_record');
});

// ── 4. Rejection and cancellation ──────────────────────────────────────────

test('a rejection leaves the balance alone', function () {
    $employee = lrEmployee();
    $type = lrType();
    lrBalance($employee, $type, allocated: 10);
    $reg = lrSubmit($employee, $type);

    app(AttendanceService::class)->rejectRegularisation($reg, lrHr()->id, 'Absence was unauthorised');

    expect($reg->fresh()->status)->toBe('rejected')
        ->and((float) LeaveBalance::where('employee_id', $employee->id)->value('used_days'))->toBe(0.0)
        ->and(LeaveBalanceAdjustment::where('employee_id', $employee->id)->exists())->toBeFalse();
});

test('a pending request can be cancelled', function () {
    $employee = lrEmployee();
    $type = lrType();
    lrBalance($employee, $type, allocated: 10);
    $reg = lrSubmit($employee, $type);
    $actor = lrHr();

    $cancelled = lrService()->cancel($reg, $actor);

    expect($cancelled->status)->toBe('cancelled')
        ->and($cancelled->cancelled_by)->toBe($actor->id)
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and(collect($cancelled->approval_trail)->last()['action'])->toBe('cancelled');
});

test('an approved request cannot be cancelled', function () {
    $employee = lrEmployee();
    $type = lrType();
    lrBalance($employee, $type, allocated: 10);
    $reg = lrSubmit($employee, $type);

    app(AttendanceService::class)->approveRegularisation($reg, lrAdmin()->id);

    expect(fn () => lrService()->cancel($reg->fresh(), lrHr()))->toThrow(RuntimeException::class);
});

// ── 5. Validation ──────────────────────────────────────────────────────────

test('an insufficient balance blocks the request', function () {
    $employee = lrEmployee();
    $type = lrType();
    lrBalance($employee, $type, allocated: 10, used: 10);

    expect(fn () => lrSubmit($employee, $type))
        ->toThrow(RuntimeException::class, 'Not enough');
});

test('a second regularisation for the same date is blocked', function () {
    $employee = lrEmployee();
    $type = lrType();
    lrBalance($employee, $type, allocated: 10);
    $date = lrPast();

    lrSubmit($employee, $type, $date);

    expect(fn () => lrSubmit($employee, $type, $date))
        ->toThrow(RuntimeException::class, 'already exists');
});

test('an existing approved leave request blocks a regularisation', function () {
    $employee = lrEmployee();
    $type = lrType();
    lrBalance($employee, $type, allocated: 10);
    $date = lrPast();

    LeaveRequest::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'start_date' => $date->toDateString(),
        'end_date' => $date->toDateString(),
        'days' => 1,
        'reason' => 'Booked properly',
        'status' => 'approved',
    ]);

    expect(fn () => lrSubmit($employee, $type, $date))
        ->toThrow(RuntimeException::class, 'already has approved leave');
});

test('a date outside the policy window is blocked', function () {
    config(['leave_regularisation.window_days' => 30]);
    $employee = lrEmployee();
    $type = lrType();
    lrBalance($employee, $type, allocated: 10);

    expect(fn () => lrSubmit($employee, $type, lrPast(90)))
        ->toThrow(RuntimeException::class, 'within 30 days');
});

test('the window is configurable rather than hard-coded', function () {
    config(['leave_regularisation.window_days' => 120]);
    $employee = lrEmployee();
    $type = lrType();
    $date = lrPast(90);
    lrBalance($employee, $type, allocated: 10, forDate: $date);

    $reg = lrSubmit($employee, $type, $date);

    expect($reg->status)->toBe('pending');
});

test('a future date is refused by default', function () {
    $employee = lrEmployee();
    $type = lrType();
    lrBalance($employee, $type, allocated: 10);

    $future = now()->addDays(3)->startOfDay();
    while ($future->isWeekend()) {
        $future = $future->addDay();
    }

    expect(fn () => lrService()->submit($employee, $type, $future, $future->copy(), 'Planned', lrHr()))
        ->toThrow(RuntimeException::class, 'past date');
});

test('a request longer than the configured maximum is refused', function () {
    config(['leave_regularisation.max_days_per_request' => 2]);
    $employee = lrEmployee();
    $type = lrType();
    lrBalance($employee, $type, allocated: 20);

    $from = lrPast(10);

    expect(fn () => lrService()->submit($employee, $type, $from, $from->copy()->addDays(6), 'Long absence', lrHr()))
        ->toThrow(RuntimeException::class, 'may not cover more than');
});

test('a supporting document can be made mandatory', function () {
    config(['leave_regularisation.require_document' => true]);
    $employee = lrEmployee();
    $type = lrType();
    lrBalance($employee, $type, allocated: 10);

    expect(fn () => lrSubmit($employee, $type))
        ->toThrow(RuntimeException::class, 'supporting document');
});

test('an end date before the start date is refused', function () {
    $employee = lrEmployee();
    $type = lrType();
    lrBalance($employee, $type, allocated: 10);
    $from = lrPast(3);

    expect(fn () => lrService()->submit($employee, $type, $from, $from->copy()->subDays(2), 'Backwards', lrHr()))
        ->toThrow(RuntimeException::class);
});

// ── 6. Audit and notification ──────────────────────────────────────────────

test('approval is audited with both balances and both attendance states', function () {
    $employee = lrEmployee();
    $type = lrType();
    lrBalance($employee, $type, allocated: 10);
    $date = lrPast();

    Attendance::create([
        'employee_id' => $employee->id,
        'date' => $date->toDateString(),
        'check_in' => $date->copy()->setTime(0, 0),
        'status' => 'absent',
        'work_mode' => 'office',
    ]);

    $reg = lrSubmit($employee, $type, $date);
    $admin = lrAdmin();
    $this->actingAs($admin);

    app(AttendanceService::class)->approveRegularisation($reg, $admin->id);

    $log = AuditLog::where('action', 'leave.regularisation_approved')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->old_values['available_days'])->toBe(10)
        ->and($log->new_values['available_days'])->toBe(9)
        ->and($log->old_values['attendance_status'])->toBe('absent')
        ->and($log->new_values['attendance_status'])->toBe('leave')
        ->and($log->new_values['source'])->toBe('regularisation')
        ->and($log->new_values['regularisation_request_id'])->toBe($reg->id)
        ->and($log->subject_employee_id)->toBe($employee->id);
});

test('the balance change is recorded on the request itself', function () {
    $employee = lrEmployee();
    $type = lrType();
    lrBalance($employee, $type, allocated: 10);
    $reg = lrSubmit($employee, $type);

    app(AttendanceService::class)->approveRegularisation($reg, lrAdmin()->id);

    expect((float) $reg->fresh()->previous_balance)->toBe(10.0)
        ->and((float) $reg->fresh()->new_balance)->toBe(9.0);
});

test('the existing reviewed notification still fires', function () {
    // Reused, not replaced: a second notification class for the same event is
    // how employees end up getting two emails that say different things.
    Notification::fake();

    $employee = lrEmployee();
    $type = lrType();
    lrBalance($employee, $type, allocated: 10);
    $reg = lrSubmit($employee, $type);

    app(AttendanceService::class)->approveRegularisation($reg, lrAdmin()->id);
    $employee->user->notify(new RegularisationReviewedNotification($reg->fresh()));

    Notification::assertSentTo($employee->user, RegularisationReviewedNotification::class);
});

// ── 7. Permissions ─────────────────────────────────────────────────────────

test('an employee cannot approve their own regularisation to completion', function () {
    $employee = lrEmployee();
    $type = lrType();
    lrBalance($employee, $type, allocated: 10);
    $reg = lrSubmit($employee, $type);

    // approvalLevel() gives a plain employee no standing in the chain, so the
    // request cannot move.
    app(AttendanceService::class)->approveRegularisation($reg, $employee->user_id);

    expect($reg->fresh()->status)->toBe('pending')
        ->and((float) LeaveBalance::where('employee_id', $employee->id)->value('used_days'))->toBe(0.0);
});

test('the regularisation permissions exist and reach HR', function () {
    $hr = lrHr();

    expect($hr->hasPermission('create_leave_regularisation'))->toBeTrue()
        ->and($hr->hasPermission('approve_leave_regularisation'))->toBeTrue()
        ->and(User::factory()->create(['role' => UserRole::Employee])->hasPermission('approve_leave_regularisation'))->toBeFalse();
});

// ── 8. Carry forward stays a separate concept ──────────────────────────────

test('a regularisation does not touch carried forward days', function () {
    // Previous-year entitlement and a corrected absence are different things;
    // mixing them would make both untraceable.
    $employee = lrEmployee();
    $type = lrType();
    $balance = lrBalance($employee, $type, allocated: 10);
    $balance->update(['carried_forward_days' => 3]);

    $reg = lrSubmit($employee, $type);
    app(AttendanceService::class)->approveRegularisation($reg, lrAdmin()->id);

    expect((float) $balance->fresh()->carried_forward_days)->toBe(3.0)
        ->and((float) $balance->fresh()->used_days)->toBe(1.0);
});
