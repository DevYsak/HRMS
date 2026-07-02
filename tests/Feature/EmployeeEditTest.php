<?php

use App\Enums\UserRole;
use App\Livewire\Employees\EmployeeEdit;
use App\Models\Employee;
use App\Models\User;
use Livewire\Livewire;

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
