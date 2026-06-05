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
