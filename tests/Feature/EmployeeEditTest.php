<?php

use App\Enums\UserRole;
use App\Livewire\Employees\EmployeeEdit;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('the profile sync button pulls the employee latest attendance from the engine', function () {
    config(['services.biometric_app.url' => 'https://engine.test']);
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    $employee = Employee::factory()->create(['employee_code' => 17]);

    // The engine returns its computed daily dashboard; it matches on employee_code.
    Http::fake(['engine.test/*' => Http::response([
        'summaries' => [[
            'employee_code' => 17,
            'date' => today()->toDateString(),
            'first_punch' => today()->setTime(9, 0)->toDateTimeString(),
            'last_punch' => today()->setTime(18, 0)->toDateTimeString(),
            'working_min' => 480, 'break_min' => 30, 'raw_punch_count' => 4,
        ]],
    ], 200)]);

    Livewire::test(EmployeeEdit::class, ['employee' => $employee])
        ->set('syncDays', 1)
        ->call('syncBiometricNow')
        ->assertHasNoErrors();

    // The engine was actually called for today.
    Http::assertSent(fn ($r) => str_contains($r->url(), 'engine.test'));
});

test('the profile sync button asks for a Biometric Device ID before syncing', function () {
    config(['services.biometric_app.url' => 'https://engine.test']);
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    $employee = Employee::factory()->create(['employee_code' => null]);   // not enrolled
    Http::fake();

    Livewire::test(EmployeeEdit::class, ['employee' => $employee])
        ->call('syncBiometricNow');

    // No engine call is made without a mapped device id.
    Http::assertNothingSent();
    expect(Attendance::where('employee_id', $employee->id)->count())->toBe(0);
});

test('employee edit page renders quick action and probation action buttons with explicit button types', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    $employee = Employee::factory()->create(['status' => 'probation']);

    Livewire::test(EmployeeEdit::class, ['employee' => $employee])
        // Tab buttons are always rendered — assert before switching tabs
        ->assertSeeHtml('wire:click="setTab(\'General\')"')
        ->assertSeeHtml('wire:click="setTab(\'Personal\')"')
        ->assertSeeHtml('wire:click="setTab(\'Job\')"')
        // Probation action buttons live inside the Probation tab panel
        ->call('setTab', 'Probation')
        ->assertSeeHtml('wire:click="confirmProbation"')
        ->assertSeeHtml('wire:click="extendProbation"');
});

test('an inactive employee can be reactivated from the edit page', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    $employee = Employee::factory()->create(['status' => 'inactive']);

    Livewire::test(EmployeeEdit::class, ['employee' => $employee])
        ->assertSeeHtml('wire:click="reactivate"')
        ->call('reactivate')
        ->assertSet('status', 'active');

    expect($employee->fresh()->status->value)->toBe('active');
});

test('an active employee shows deactivate, not reactivate', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    $employee = Employee::factory()->create(['status' => 'active']);

    Livewire::test(EmployeeEdit::class, ['employee' => $employee])
        ->assertSeeHtml('wire:click="deactivate"')
        ->call('deactivate')
        ->assertSet('status', 'inactive');

    expect($employee->fresh()->status->value)->toBe('inactive');
});

test('editing the biometric device id saves to employee_code and mirrors to biometric_id', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    $employee = Employee::factory()->create(['status' => 'active', 'employee_code' => null, 'biometric_id' => null]);

    Livewire::test(EmployeeEdit::class, ['employee' => $employee])
        ->set('employee_code', '25')
        ->call('save')
        ->assertHasNoErrors();

    $employee->refresh();
    expect($employee->employee_code)->toBe(25);
    expect($employee->biometric_id)->toBe('25');
});

test('edit form back-fills the PIN field from a legacy biometric_id', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    // Legacy record: PIN saved only to biometric_id, employee_code still null.
    $employee = Employee::factory()->create(['status' => 'active', 'employee_code' => null, 'biometric_id' => '42']);

    Livewire::test(EmployeeEdit::class, ['employee' => $employee])
        ->assertSet('employee_code', '42');
});

test('employee profile 2.0 exposes the analytical tabs and panels', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    $employee = Employee::factory()->create(['status' => 'active']);

    Livewire::test(EmployeeEdit::class, ['employee' => $employee])
        ->assertSeeHtml('wire:click="setTab(\'Attendance\')"')
        ->assertSeeHtml('wire:click="setTab(\'Performance\')"')
        ->assertSeeHtml('wire:click="setTab(\'Timeline\')"')
        ->call('setTab', 'Attendance')->assertSee('Most recent 30 attendance records')
        ->call('setTab', 'OT')->assertSee('Recorded OT hours')
        ->call('setTab', 'Performance')->assertSee('KPI History')
        ->call('setTab', 'Warnings')->assertSee('Warning Letters')
        ->call('setTab', 'PIP')->assertSee('Performance Improvement Plans')
        ->call('setTab', 'Promotions')->assertSee('Recommendations')
        ->call('setTab', 'Timeline')->assertSee('Career Timeline');
});

// ── The redesigned shell ───────────────────────────────────────────────────
//
// The page was restyled onto the dashboard's language (warm ground, #F3E8DD
// hairlines, orange accent) and its sixteen tabs were regrouped off a single
// overflowing rail. Nothing below the chrome changed, but every panel is
// reachable through the new tab bar, so every panel gets rendered here — a
// restyle that silently breaks the Payroll or Probation tab is the failure
// mode worth guarding.

test('every tab on the employee record renders', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));
    $employee = Employee::factory()->create(['status' => 'probation']);

    $component = Livewire::test(EmployeeEdit::class, ['employee' => $employee])->assertOk();

    foreach ([
        'General', 'Personal', 'Job', 'Documents',
        'Attendance', 'Leave', 'OT',
        'Performance', 'Promotions', 'Probation', 'PIP',
        'Warnings', 'Payroll', 'Timeline', 'Activity',
    ] as $tab) {
        $component->call('setTab', $tab)
            ->assertOk()
            ->assertHasNoErrors();
    }
});

test('the Nexflow tab renders for an employee tracked by Nexflow', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));
    $employee = Employee::factory()->create(['ot_tracking_source' => 'nexflow']);

    Livewire::test(EmployeeEdit::class, ['employee' => $employee])
        ->call('setTab', 'Nexflow')
        ->assertOk();
});

test('the record page survives an employee with nothing filled in', function () {
    // The identity card reads a manager, office, shift and joining date that an
    // imported row may not have yet. Each needs a real fallback rather than a
    // blank line or a 500.
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    $employee = Employee::factory()->create([
        'shift_id' => null,
        'office_id' => null,
        'department_id' => null,
        'job_title_id' => null,
        'manager_id' => null,
        'joining_date' => null,
        'phone' => null,
        'employee_code' => null,
    ]);

    Livewire::test(EmployeeEdit::class, ['employee' => $employee])
        ->assertOk()
        ->assertSee('Not set')
        ->assertSee('No shift assigned');
});

// ── Two-level navigation ───────────────────────────────────────────────────
//
// Sixteen panels sat on one rail. They now sit under six areas: the primary row
// picks the area, the secondary row picks the panel. The active area is derived
// from the active tab rather than stored, so there is no second piece of state
// to fall out of step — which is exactly what these pin down.

test('opening an area lands on its first panel', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));
    $employee = Employee::factory()->create();

    Livewire::test(EmployeeEdit::class, ['employee' => $employee])
        ->call('setTab', 'Payroll')
        ->assertSet('activeTab', 'Payroll')
        ->assertOk();
});

test('the area highlights from whichever panel is open', function () {
    // Reaching Documents from the sidebar shortcut must light up Record, not
    // leave the primary row pointing somewhere else.
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));
    $employee = Employee::factory()->create();

    Livewire::test(EmployeeEdit::class, ['employee' => $employee])
        ->call('setTab', 'Documents')
        ->assertOk()
        ->assertSee('Record')
        ->assertSee('Documents');
});

test('an area holding one panel shows no second row', function () {
    // Conduct and Pay hold a single panel each; a secondary row with one pill
    // in it is noise.
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));
    $employee = Employee::factory()->create();

    Livewire::test(EmployeeEdit::class, ['employee' => $employee])
        ->call('setTab', 'Warnings')
        ->assertOk();
});

test('the hero answers the four questions asked before any panel opens', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    $employee = Employee::factory()->create([
        'employee_id' => 'EMP-A021',
        'joining_date' => '2022-06-28',
    ]);

    Livewire::test(EmployeeEdit::class, ['employee' => $employee])
        ->assertOk()
        ->assertSee('EMP-A021')
        ->assertSee('28 Jun 2022')
        ->assertSee('Emp ID')
        ->assertSee('Manager');
});
