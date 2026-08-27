<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveCarryForwardTransaction;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Leave\LeaveAuditCategoriser;
use App\Services\Leave\LeaveCarryForwardService;
use App\Services\Leave\LeaveYearResolver;
use App\Services\LeaveBalanceService;
use Illuminate\Support\Str;

/**
 * Reading the leave audit trail.
 *
 * Entries are recorded as "created" or "updated" against a model class, which
 * is enough to reconstruct what happened and useless for reading:
 * "LeaveBalanceAdjustment created" does not say whether HR credited two days or
 * last year's leave was carried in. The categoriser derives both the category
 * and a sentence from data already recorded — no second write, and no parallel
 * table to fall out of step with the first.
 */
function lacType(string $name = 'Annual'): LeaveType
{
    return LeaveType::create([
        'name' => $name.' '.Str::random(4),
        'code' => strtoupper(substr($name, 0, 1)).strtoupper(Str::random(3)),
        'category' => 'annual',
        'allow_paid_request' => true,
        'allow_carry_forward' => true,
    ]);
}

function lacEmployee(): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
    ]);
}

function lacYears(): array
{
    return [
        LeaveYear::firstOrCreate(['label' => '2025/26'], ['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']),
        LeaveYear::firstOrCreate(['label' => '2026/27'], ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']),
    ];
}

function lacCarryable(Employee $e, LeaveType $t, LeaveYear $prev, float $allocated, float $used): LeaveBalance
{
    return LeaveBalance::create([
        'employee_id' => $e->id,
        'leave_type_id' => $t->id,
        'leave_year_id' => $prev->id,
        'year' => $prev->legacyYear(),
        'allocated_days' => $allocated,
        'used_days' => $used,
    ]);
}

function lacCategoriser(): LeaveAuditCategoriser
{
    return app(LeaveAuditCategoriser::class);
}

// ── Manual adjustment ──────────────────────────────────────────────────────

test('a manual adjustment is categorised and described', function () {
    $employee = lacEmployee();
    $type = lacType();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $this->actingAs($hr);

    app(LeaveBalanceService::class)->adjust($employee, $type, 'credit', 2, 'Correction', '', $hr);

    $log = AuditLog::where('auditable_type', LeaveBalanceAdjustment::class)->latest('id')->first();
    $c = lacCategoriser();

    expect($c->categoryFor($log))->toBe('leave_balance_adjustment')
        ->and($c->labelFor($log))->toBe('Manual Balance Adjustment')
        ->and($c->summarise($log))->toContain('+2 day(s)')
        ->and($log->reason)->toBe('Correction');
});

test('the adjustment audit records both sides, not just the result', function () {
    $employee = lacEmployee();
    $type = lacType();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $this->actingAs($hr);

    LeaveBalance::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'year' => app(LeaveYearResolver::class)->legacyYearFor(),
        'allocated_days' => 10,
        'used_days' => 0,
    ]);

    app(LeaveBalanceService::class)->adjust($employee, $type, 'credit', 2, 'Correction', 'Agreed', $hr);

    $log = AuditLog::where('auditable_type', LeaveBalanceAdjustment::class)->latest('id')->first();

    expect((float) $log->old_values['allocated_days'])->toBe(10.0)
        ->and((float) $log->new_values['allocated_days'])->toBe(12.0)
        ->and((float) $log->new_values['days'])->toBe(2.0)
        ->and($log->new_values['source'])->toBe('manual')
        ->and($log->new_values['adjusted_by'])->toBe($hr->id)
        ->and($log->subject_employee_id)->toBe($employee->id)
        ->and($log->user_id)->toBe($hr->id)
        ->and($log->created_at)->not->toBeNull();
});

test('a debit reads as a subtraction', function () {
    $employee = lacEmployee();
    $type = lacType();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $this->actingAs($hr);

    LeaveBalance::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'year' => app(LeaveYearResolver::class)->legacyYearFor(),
        'allocated_days' => 10,
        'used_days' => 0,
    ]);

    app(LeaveBalanceService::class)->adjust($employee, $type, 'debit', 3, 'Booked elsewhere', '', $hr);

    $log = AuditLog::where('auditable_type', LeaveBalanceAdjustment::class)->latest('id')->first();

    expect(lacCategoriser()->summarise($log))->toContain('-3 day(s)');
});

// ── Carry forward ──────────────────────────────────────────────────────────

test('a carry forward is categorised and reads as a sentence', function () {
    [$prev, $curr] = lacYears();
    $employee = lacEmployee();
    $type = lacType();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $this->actingAs($hr);

    lacCarryable($employee, $type, $prev, 28, 23);

    app(LeaveCarryForwardService::class)->apply($employee, $type, $prev, $curr, $hr);

    $log = AuditLog::where('action', 'leave.carry_forward_applied')->latest('id')->first();
    $c = lacCategoriser();

    expect($c->categoryFor($log))->toBe('carry_forward')
        ->and($c->labelFor($log))->toBe('Carry Forward')
        ->and($c->summarise($log))->toBe('5 day(s) carried forward from 2025/26 to 2026/27');
});

test('a carry forward reversal is its own category', function () {
    [$prev, $curr] = lacYears();
    $employee = lacEmployee();
    $type = lacType();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $this->actingAs($hr);

    lacCarryable($employee, $type, $prev, 28, 20);

    $service = app(LeaveCarryForwardService::class);
    $tx = $service->apply($employee, $type, $prev, $curr, $hr);
    $service->reverse($tx->fresh(), $hr, 'Carried in error');

    $log = AuditLog::where('action', 'leave.carry_forward_reversed')->latest('id')->first();
    $c = lacCategoriser();

    expect($c->categoryFor($log))->toBe('carry_forward_reversal')
        ->and($c->labelFor($log))->toBe('Carry Forward Reversal')
        ->and($c->summarise($log))->toContain('reversed')
        ->and($log->reason)->toBe('Carried in error');
});

test('the carry forward entry carries both leave years and the eligible figure', function () {
    [$prev, $curr] = lacYears();
    $employee = lacEmployee();
    $type = lacType();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $this->actingAs($hr);

    lacCarryable($employee, $type, $prev, 28, 20);

    $tx = app(LeaveCarryForwardService::class)->apply($employee, $type, $prev, $curr, $hr, days: 5);
    $log = AuditLog::where('action', 'leave.carry_forward_applied')->latest('id')->first();

    expect($log->auditable_id)->toBe($tx->id)
        ->and($log->auditable_type)->toBe(LeaveCarryForwardTransaction::class)
        ->and($log->new_values['previous_leave_year'])->toBe('2025/26')
        ->and($log->new_values['current_leave_year'])->toBe('2026/27')
        ->and((float) $log->new_values['eligible_days'])->toBe(8.0)
        ->and((float) $log->new_values['applied_days'])->toBe(5.0)
        ->and($log->new_values['partial'])->toBeTrue();
});

// ── Entries that are not about leave ───────────────────────────────────────

test('a non-leave entry is not claimed as leave', function () {
    $employee = lacEmployee();
    AuditLog::record($employee, 'updated', ['status' => 'active'], ['status' => 'probation']);

    $log = AuditLog::where('auditable_type', Employee::class)->latest('id')->first();
    $c = lacCategoriser();

    expect($c->categoryFor($log))->toBeNull()
        ->and($c->isLeaveEntry($log))->toBeFalse()
        ->and($c->summarise($log))->toBeNull();
});

test('every category has a human label and a machine key', function () {
    foreach (LeaveAuditCategoriser::CATEGORIES as $key => $label) {
        expect($label)->toBeString()->not->toBe('');
        expect($key)->toMatch('/^[a-z_]+$/');
    }
});

// ── Filtering ──────────────────────────────────────────────────────────────

test('filtering by category returns only that category', function () {
    [$prev, $curr] = lacYears();
    $employee = lacEmployee();
    $type = lacType();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $this->actingAs($hr);

    lacCarryable($employee, $type, $prev, 28, 20);

    app(LeaveCarryForwardService::class)->apply($employee, $type, $prev, $curr, $hr);
    app(LeaveBalanceService::class)->adjust($employee, $type, 'credit', 2, 'Correction', '', $hr);

    $c = lacCategoriser();

    $carried = $c->scopeToCategory(AuditLog::query(), 'carry_forward')->get();
    $adjusted = $c->scopeToCategory(AuditLog::query(), 'leave_balance_adjustment')->get();

    expect($carried)->toHaveCount(1)
        ->and($carried->first()->action)->toBe('leave.carry_forward_applied')
        ->and($adjusted)->toHaveCount(1)
        ->and($adjusted->first()->auditable_type)->toBe(LeaveBalanceAdjustment::class);
});

test('filtering by employee returns every leave action for that person', function () {
    [$prev, $curr] = lacYears();
    $mine = lacEmployee();
    $theirs = lacEmployee();
    $type = lacType();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $this->actingAs($hr);

    lacCarryable($mine, $type, $prev, 28, 20);
    lacCarryable($theirs, $type, $prev, 28, 20);

    $service = app(LeaveCarryForwardService::class);
    $service->apply($mine, $type, $prev, $curr, $hr);
    $service->apply($theirs, $type, $prev, $curr, $hr);
    app(LeaveBalanceService::class)->adjust($mine, $type, 'credit', 2, 'Correction', '', $hr);

    $forMine = AuditLog::where('subject_employee_id', $mine->id)->get();

    expect($forMine)->toHaveCount(2)
        ->and($forMine->every(fn ($l) => $l->subject_employee_id === $mine->id))->toBeTrue();
});
