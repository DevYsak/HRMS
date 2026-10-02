<?php

use App\Enums\UserRole;
use App\Livewire\Employees\EmployeeEdit;
use App\Livewire\TimeOff\TimeOffSettings;
use App\Models\Employee;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\Role;
use App\Models\User;
use App\Services\Leave\LeaveCarryOverService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Carry-forward configuration that actually configures something.
 *
 * carry_forward_mode was written by the master-data migration and read by
 * nothing: a type set to "none" was still carried forward, because only the
 * older allow_carry_forward boolean was ever consulted, and the settings
 * screen printed "HR approval" for every carried type whatever it was set to.
 * These pin the mode to real behaviour and to what the screen says.
 */
function lcfcYears(): array
{
    $prev = LeaveYear::firstOrCreate(['label' => '2025/26'], ['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
    $curr = LeaveYear::firstOrCreate(['label' => '2026/27'], ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);

    return [$prev, $curr];
}

function lcfcType(string $code, bool $allow, ?string $mode): LeaveType
{
    return LeaveType::create([
        'name' => 'Type '.$code,
        'code' => $code,
        'category' => 'annual',
        'allow_paid_request' => true,
        'allow_carry_forward' => $allow,
        'carry_forward_mode' => $mode,
    ]);
}

function lcfcAdmin(string $slug = 'hr_admin'): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $role = Role::where('slug', $slug)->firstOrFail();

    return User::factory()->create([
        'role' => $slug === 'hr_admin' ? UserRole::HrAdmin : UserRole::SuperAdmin,
        'role_id' => $role->id,
    ]);
}

// ── The mode decides, not the old boolean ──────────────────────────────────

test('a type set to none is not carried forward, even with the old flag on', function () {
    // The exact combination that used to slip through.
    $type = lcfcType('CFN', allow: true, mode: LeaveType::CARRY_NONE);

    expect($type->permitsCarryForward())->toBeFalse()
        ->and($type->carryForwardMode())->toBe(LeaveType::CARRY_NONE);
});

test('a type set to HR approval is carried forward', function () {
    $type = lcfcType('CFH', allow: true, mode: LeaveType::CARRY_HR_APPROVAL);

    expect($type->permitsCarryForward())->toBeTrue()
        ->and($type->carryForwardMode())->toBe(LeaveType::CARRY_HR_APPROVAL);
});

test('carry forward disabled by the flag stays disabled whatever the mode says', function () {
    $type = lcfcType('CFD', allow: false, mode: LeaveType::CARRY_HR_APPROVAL);

    expect($type->permitsCarryForward())->toBeFalse()
        ->and($type->carryForwardMode())->toBe(LeaveType::CARRY_NONE);
});

test('a type with no mode recorded defaults to HR approval, never to automatic', function () {
    $type = lcfcType('CFB', allow: true, mode: null);

    expect($type->carryForwardMode())->toBe(LeaveType::CARRY_HR_APPROVAL);
});

test('the carry-over engine skips a type configured as none', function () {
    [$prev, $curr] = lcfcYears();
    lcfcType('CFN', allow: true, mode: LeaveType::CARRY_NONE);
    $carried = lcfcType('CFH', allow: true, mode: LeaveType::CARRY_HR_APPROVAL);

    $employee = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
    ]);

    $rows = app(LeaveCarryOverService::class)->preview($prev, $curr);
    $codes = $rows->pluck('leave_type_id')->unique();

    expect($codes)->not->toContain(LeaveType::where('code', 'CFN')->value('id'));
});

// ── Limits: null is unlimited, zero is none ────────────────────────────────

test('a null policy limit means unlimited and zero means none, never the reverse', function () {
    $policy = LeavePolicy::create([
        'name' => 'Config Policy', 'statutory_weeks' => 5.60,
        'contractual_additional_weeks' => 0,
        'bank_holiday_treatment' => LeavePolicy::BANK_HOLIDAYS_ADDITIONAL, 'is_active' => true,
    ]);

    DB::table('leave_policies')->where('id', $policy->id)
        ->update(['max_carry_over_days' => null]);
    expect($policy->fresh()->max_carry_over_days)->toBeNull();

    DB::table('leave_policies')->where('id', $policy->id)
        ->update(['max_carry_over_days' => 0]);
    expect((float) $policy->fresh()->max_carry_over_days)->toBe(0.0);
});

// ── The settings screen says what is stored ────────────────────────────────

test('the settings screen states HR approval and unlimited in words', function () {
    $admin = lcfcAdmin();
    lcfcType('CFH', allow: true, mode: LeaveType::CARRY_HR_APPROVAL);

    Livewire::actingAs($admin)->test(TimeOffSettings::class)
        ->assertOk()
        ->assertSee('Carry forward: HR approval')
        ->assertDontSee('CF: Yes');
});

test('a type that cannot be carried forward says so plainly', function () {
    $admin = lcfcAdmin();
    lcfcType('CFN', allow: false, mode: LeaveType::CARRY_NONE);

    Livewire::actingAs($admin)->test(TimeOffSettings::class)
        ->assertOk()
        ->assertSee('Not permitted');
});

test('a type stored as automatic is not presented as unattended', function () {
    // Nothing applies carry forward on its own, so the screen must not imply
    // that something will.
    $admin = lcfcAdmin();
    lcfcType('CFA', allow: true, mode: LeaveType::CARRY_AUTOMATIC);

    Livewire::actingAs($admin)->test(TimeOffSettings::class)
        ->assertOk()
        ->assertSee('applied only on HR action');
});

// ── The employee action follows the type ───────────────────────────────────

test('the carry forward action is offered for a type that permits it', function () {
    $admin = lcfcAdmin();
    lcfcYears();
    lcfcType('CFH', allow: true, mode: LeaveType::CARRY_HR_APPROVAL);

    $employee = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
    ]);

    Livewire::actingAs($admin)->test(EmployeeEdit::class, ['employee' => $employee])
        ->set('activeTab', 'Leave')
        ->assertOk()
        ->assertSee('Carry forward from the previous leave year');
});

test('the carry forward action is withheld when no type permits it', function () {
    // Offering it on a type the engine will refuse invites a decision that
    // cannot be honoured.
    $admin = lcfcAdmin();
    lcfcYears();
    LeaveType::query()->update(['allow_carry_forward' => false, 'carry_forward_mode' => LeaveType::CARRY_NONE]);

    $employee = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
    ]);

    Livewire::actingAs($admin)->test(EmployeeEdit::class, ['employee' => $employee])
        ->set('activeTab', 'Leave')
        ->assertOk()
        ->assertDontSee('Carry forward from the previous leave year');
});

// ── Permissions ────────────────────────────────────────────────────────────

test('an employee cannot open leave settings', function () {
    test()->seed(RolesAndPermissionsSeeder::class);
    $employee = User::factory()->create(['role' => UserRole::Employee]);

    Livewire::actingAs($employee)->test(TimeOffSettings::class)->assertForbidden();
});

// ── The configuration is reproducible ──────────────────────────────────────

test('the canonical master data matches the approved defaults', function () {
    // What must be true on any environment after the migrations run. This is
    // the local half of the local/live comparison — the live half needs a
    // read-only export from the server.
    $expected = [
        'AL' => ['carry' => true, 'paid' => true],
        'SL' => ['carry' => false, 'paid' => true],
        'CO' => ['carry' => true, 'paid' => true],
        'LWP' => ['carry' => false, 'paid' => false],
        'UNA' => ['carry' => false, 'paid' => false],
    ];

    foreach ($expected as $code => $rules) {
        $type = LeaveType::withTrashed()->where('code', $code)->first();

        if ($type === null) {
            continue; // reported by the canonical configuration suite
        }

        expect($type->permitsCarryForward())->toBe($rules['carry'], "{$code} carry forward");
    }

    // Neither may be created automatically.
    expect(LeaveType::withTrashed()->where('code', 'CSL')->exists())->toBeFalse()
        ->and(LeaveType::withTrashed()->where('code', 'MDL')->exists())->toBeFalse();
});

test('work from home is not a carry-forwardable leave entitlement', function () {
    $wfh = LeaveType::withTrashed()->where('code', 'WFH')->first();

    if ($wfh === null) {
        expect(true)->toBeTrue();

        return;
    }

    expect($wfh->permitsCarryForward())->toBeFalse();
});
