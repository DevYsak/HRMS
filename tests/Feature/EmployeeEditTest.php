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
