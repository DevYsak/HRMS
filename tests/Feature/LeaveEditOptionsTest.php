<?php

use App\Enums\UserRole;
use App\Livewire\TimeOff\AllTimeOff;
use App\Livewire\TimeOff\MyTimeOff;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use App\Services\Leave\LeaveYearResolver;
use App\Services\LeaveService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Leave editing options: an employee may apply for past dates (within the
 * leave year), and HR's Manage Leave Request edits half day (first / second
 * half) and paid / unpaid — the same choices as the employee's own form.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00')); // Wednesday

    $user = User::factory()->create(['role' => UserRole::Employee]);
    $this->employee = Employee::factory()->create(['user_id' => $user->id, 'status' => 'active', 'joining_date' => '2024-01-08']);
    $this->type = LeaveType::create([
        'name' => 'Edit Test Leave', 'code' => 'ETL', 'is_paid' => true, 'color' => '#3B82F6', 'category' => 'annual',
        'allow_paid_request' => true, 'allow_unpaid_request' => true, 'allow_hr_override' => true, 'hr_remark_required' => true,
        'allow_half_day' => true, 'is_sandwich_applicable' => false, 'attachment_required' => false, 'gender_restriction' => 'none',
    ]);
    $year = app(LeaveYearResolver::class)->current();
    LeaveBalance::create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->type->id, 'leave_year_id' => $year->id, 'year' => $year->legacyYear(), 'allocated_days' => 10, 'used_days' => 0, 'carried_forward_days' => 0]);

    $role = Role::where('slug', 'hr_admin')->firstOrFail();
    $this->hr = User::factory()->create(['role' => UserRole::HrAdmin, 'role_id' => $role->id]);
});

function leoRequest($test, array $attributes = []): LeaveRequest
{
    return LeaveRequest::create($attributes + [
        'employee_id' => $test->employee->id, 'leave_type_id' => $test->type->id,
        'start_date' => '2026-10-20', 'end_date' => '2026-10-20', 'is_half_day' => false, 'days' => 1,
        'reason' => 'Edit options test', 'requested_leave_status' => 'paid', 'status' => 'pending',
    ]);
}

function leoManage($test, LeaveRequest $request)
{
    return Livewire::actingAs($test->hr)->test(AllTimeOff::class)->call('manageRequest', $request->id);
}

// ── Employee: past dates ─────────────────────────────────────────────────

test('an employee can apply for a past date in the current leave year', function () {
    Livewire::actingAs($this->employee->user)->test(MyTimeOff::class)
        ->call('openRequestModal')
        ->set('leave_type_id', (string) $this->type->id)
        ->set('start_date', '2026-10-12')
        ->set('end_date', '2026-10-12')
        ->assertSeeHtml('data-backdated-note')
        ->set('requested_leave_status', 'paid')
        ->set('reason', 'Was unwell on Monday')
        ->call('submitRequest')
        ->assertHasNoErrors();

    $request = LeaveRequest::where('employee_id', $this->employee->id)->firstOrFail();
    expect($request->start_date->toDateString())->toBe('2026-10-12')
        ->and($request->status)->toBe('pending');
});

test('a date before the leave year (or the joining date) is refused', function () {
    $yearStart = app(LeaveYearResolver::class)->current()->starts_on;

    Livewire::actingAs($this->employee->user)->test(MyTimeOff::class)
        ->call('openRequestModal')
        ->set('leave_type_id', (string) $this->type->id)
        ->set('start_date', $yearStart->copy()->subDay()->toDateString())
        ->set('end_date', $yearStart->copy()->subDay()->toDateString())
        ->set('requested_leave_status', 'paid')
        ->set('reason', 'Too far back')
        ->call('submitRequest')
        ->assertHasErrors(['start_date' => 'after_or_equal'])
        ->assertSee('Leave can start no earlier than');

    $this->employee->update(['joining_date' => '2026-10-05']);
    $component = Livewire::actingAs($this->employee->user->fresh())->test(MyTimeOff::class);
    expect($component->instance()->earliestLeaveDate())->toBe('2026-10-05');
});

// ── HR Manage Leave Request: half day ────────────────────────────────────

test('HR turns an approved full day into a first-half half day', function () {
    $request = leoRequest($this);
    app(LeaveService::class)->reviewRequest($request, ['leave_type_id' => $this->type->id, 'start_date' => '2026-10-20', 'end_date' => '2026-10-20', 'reason' => 'x', 'is_half_day' => false], 'approved', $this->hr->id);

    leoManage($this, $request->fresh())
        ->assertSeeHtml('data-manage-half-day')
        ->set('form.is_half_day', true)
        ->assertSee('First Half')
        ->set('form.half_day_period', 'first_half')
        ->call('saveManage')
        ->assertHasNoErrors();

    $request->refresh();
    expect($request->is_half_day)->toBeTrue()
        ->and($request->half_day_period)->toBe('first_half')
        ->and((float) $request->days)->toBe(0.5)
        ->and($request->status)->toBe('approved');
});

test('ticking half day makes the end date follow the start date', function () {
    $request = leoRequest($this, ['end_date' => '2026-10-22', 'days' => 3]);

    leoManage($this, $request)
        ->set('form.is_half_day', true)
        ->assertSet('form.end_date', '2026-10-20')
        ->set('form.start_date', '2026-10-21')
        ->assertSet('form.end_date', '2026-10-21');
});

test('a half day needs a half, and a single date', function () {
    $request = leoRequest($this);

    leoManage($this, $request)
        ->set('form.is_half_day', true)
        ->call('saveManage')
        ->assertHasErrors(['form.half_day_period' => 'required_if']);

    // Straight to the service with a range: refused.
    expect(fn () => app(LeaveService::class)->reviewRequest($request->fresh(), ['leave_type_id' => $this->type->id, 'start_date' => '2026-10-20', 'end_date' => '2026-10-21', 'reason' => 'x', 'is_half_day' => true, 'half_day_period' => 'first_half'], 'approved', $this->hr->id))
        ->toThrow(DomainException::class, 'single day');
});

test('a leave type without half days refuses one', function () {
    $this->type->update(['allow_half_day' => false]);
    $request = leoRequest($this);

    leoManage($this, $request)
        ->set('form.is_half_day', true)
        ->set('form.half_day_period', 'second_half')
        ->call('saveManage')
        ->assertHasErrors('form.leave_type_id')
        ->assertSee('does not allow half-day requests');
});

test('approving without editing keeps the employee\'s chosen half', function () {
    $request = leoRequest($this, ['is_half_day' => true, 'half_day_period' => 'second_half', 'days' => 0.5]);

    // Approvers that only approve send no half_day_period (Team Time Off, dashboards…).
    app(LeaveService::class)->reviewRequest($request, ['leave_type_id' => $this->type->id, 'start_date' => '2026-10-20', 'end_date' => '2026-10-20', 'reason' => 'x', 'is_half_day' => true], 'approved', $this->hr->id);

    expect($request->fresh()->half_day_period)->toBe('second_half')
        ->and((float) $request->fresh()->days)->toBe(0.5);
});

// ── HR Manage Leave Request: paid / unpaid ───────────────────────────────

test('HR changes an approved leave from paid to unpaid with a remark', function () {
    $request = leoRequest($this);
    app(LeaveService::class)->reviewRequest($request, ['leave_type_id' => $this->type->id, 'start_date' => '2026-10-20', 'end_date' => '2026-10-20', 'reason' => 'x', 'is_half_day' => false], 'approved', $this->hr->id);

    $component = leoManage($this, $request->fresh())
        ->assertSeeHtml('data-manage-payment')
        ->assertSee('Leave Payment Type')
        // Paid / unpaid is its own choice now, not part of the leave type's name.
        ->assertDontSee('Edit Test Leave (Paid)')
        ->set('form.approved_leave_status', 'unpaid')
        ->assertSee('HR remark (required for a payment change)')
        ->call('saveManage')
        ->assertHasErrors('form.hr_remark');

    $component->set('form.hr_remark', 'Balance exhausted — agreed with the employee')
        ->call('saveManage')
        ->assertHasNoErrors();

    expect($request->fresh()->approved_leave_status)->toBe('unpaid');
});

test('someone who is not HR sees the payment type but cannot change it', function () {
    $role = Role::where('slug', 'manager')->firstOrFail();
    $manager = User::factory()->create(['role' => UserRole::Manager, 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $manager->id, 'status' => 'active']);
    $this->employee->update(['manager_id' => $manager->id]);
    $request = leoRequest($this);

    Livewire::actingAs($manager)->test(AllTimeOff::class)
        ->call('manageRequest', $request->id)
        ->assertSee('Changed only by HR Admin or Super Admin')
        ->assertDontSeeHtml('value="unpaid"')
        ->set('form.approved_leave_status', 'unpaid')
        ->call('saveManage')
        ->assertHasErrors('form.approved_leave_status');

    expect($request->fresh()->approved_leave_status)->toBeNull();
})->skip(fn () => ! Role::where('slug', 'manager')->exists(), 'no manager role');
