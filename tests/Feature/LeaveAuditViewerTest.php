<?php

use App\Enums\UserRole;
use App\Livewire\AuditLogViewer;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Leave\LeaveCarryForwardService;
use App\Services\LeaveBalanceService;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The admin audit log, filtered by what actually happened.
 *
 * Filtering by action or model cannot answer "show me every carry forward" —
 * those are all recorded as "created" against different models. The category
 * filter is derived, so it answers the question a person actually asks.
 */
function lavSetup(): array
{
    $prev = LeaveYear::firstOrCreate(['label' => '2025/26'], ['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
    $curr = LeaveYear::firstOrCreate(['label' => '2026/27'], ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);

    $employee = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
    ]);

    $type = LeaveType::create([
        'name' => 'Annual '.Str::random(4),
        'code' => 'A'.strtoupper(Str::random(3)),
        'category' => 'annual',
        'allow_carry_forward' => true,
    ]);

    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'leave_year_id' => $prev->id, 'year' => $prev->legacyYear(),
        'allocated_days' => 28, 'used_days' => 20,
    ]);

    return [$employee, $type, $hr, $prev, $curr];
}

test('the audit log opens for an administrator', function () {
    [, , $hr] = lavSetup();

    Livewire::actingAs($hr)->test(AuditLogViewer::class)->assertOk();
});

test('filtering by carry forward shows only carry forwards', function () {
    [$employee, $type, $hr, $prev, $curr] = lavSetup();
    $this->actingAs($hr);

    app(LeaveCarryForwardService::class)->apply($employee, $type, $prev, $curr, $hr);
    app(LeaveBalanceService::class)->adjust($employee, $type, 'credit', 2, 'Correction', '', $hr);

    Livewire::actingAs($hr)->test(AuditLogViewer::class)
        ->set('category', 'carry_forward')
        ->assertOk()
        ->assertViewHas('logs', fn ($logs) => $logs->count() === 1
            && $logs->first()->action === 'leave.carry_forward_applied');
});

test('filtering by employee shows only that person', function () {
    [$mine, $type, $hr, $prev, $curr] = lavSetup();
    $this->actingAs($hr);

    $theirs = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
    ]);
    LeaveBalance::create([
        'employee_id' => $theirs->id, 'leave_type_id' => $type->id,
        'leave_year_id' => $prev->id, 'year' => $prev->legacyYear(),
        'allocated_days' => 28, 'used_days' => 20,
    ]);

    $service = app(LeaveCarryForwardService::class);
    $service->apply($mine, $type, $prev, $curr, $hr);
    $service->apply($theirs, $type, $prev, $curr, $hr);

    Livewire::actingAs($hr)->test(AuditLogViewer::class)
        ->set('employeeId', $mine->id)
        ->assertOk()
        ->assertViewHas('logs', fn ($logs) => $logs->count() === 1
            && $logs->first()->subject_employee_id === $mine->id);
});

test('the log describes a leave entry rather than saying created', function () {
    [$employee, $type, $hr, $prev, $curr] = lavSetup();
    $this->actingAs($hr);

    app(LeaveCarryForwardService::class)->apply($employee, $type, $prev, $curr, $hr);

    Livewire::actingAs($hr)->test(AuditLogViewer::class)
        ->set('category', 'carry_forward')
        ->assertOk()
        ->assertSee('Carry Forward')
        ->assertSee('8 day(s) carried forward from 2025/26 to 2026/27');
});

test('clearing filters drops the leave filters too', function () {
    // A filter that survives a reset is worse than no reset button.
    [$employee, $type, $hr, $prev, $curr] = lavSetup();
    $this->actingAs($hr);

    app(LeaveCarryForwardService::class)->apply($employee, $type, $prev, $curr, $hr);
    app(LeaveBalanceService::class)->adjust($employee, $type, 'credit', 2, 'Correction', '', $hr);

    Livewire::actingAs($hr)->test(AuditLogViewer::class)
        ->set('category', 'carry_forward')
        ->set('employeeId', $employee->id)
        ->call('clearFilters')
        ->assertSet('category', '')
        ->assertSet('employeeId', null)
        ->assertViewHas('logs', fn ($logs) => $logs->count() >= 2);
});

test('filtering by performer shows only their actions', function () {
    [$employee, $type, $hr, $prev, $curr] = lavSetup();
    $other = User::factory()->create(['role' => UserRole::HrAdmin]);

    $this->actingAs($hr);
    app(LeaveBalanceService::class)->adjust($employee, $type, 'credit', 2, 'By HR', '', $hr);

    $this->actingAs($other);
    app(LeaveBalanceService::class)->adjust($employee, $type, 'credit', 1, 'By other', '', $other);

    Livewire::actingAs($hr)->test(AuditLogViewer::class)
        ->set('performedBy', $other->id)
        ->assertOk()
        ->assertViewHas('logs', fn ($logs) => $logs->every(fn ($l) => $l->user_id === $other->id));
});

test('an employee cannot open the audit log', function () {
    lavSetup();

    Livewire::actingAs(User::factory()->create(['role' => UserRole::Employee]))
        ->test(AuditLogViewer::class)
        ->assertForbidden();
});

test('a non-leave entry keeps its plain action label', function () {
    [$employee, , $hr] = lavSetup();
    $this->actingAs($hr);

    AuditLog::record($employee, 'updated', ['status' => 'active'], ['status' => 'probation']);

    Livewire::actingAs($hr)->test(AuditLogViewer::class)
        ->assertOk()
        ->assertSee('Updated');
});
