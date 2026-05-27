<?php

use App\Enums\UserRole;
use App\Livewire\Employees\EmployeeEdit;
use App\Models\Employee;
use App\Models\User;
use Livewire\Livewire;

test('employee edit page renders quick action and probation action buttons with explicit button types', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    $employee = Employee::factory()->create(['status' => 'probation']);

    $component = Livewire::test(EmployeeEdit::class, ['employee' => $employee]);

    $component
        ->assertSeeHtml('type="button" wire:click="confirmProbation"')
        ->assertSeeHtml('type="button" wire:click="extendProbation"')
        ->assertSeeHtml('type="button" wire:click="setTab(\'General\')"')
        ->assertSeeHtml('type="button" wire:click="setTab(\'Personal\')"')
        ->assertSeeHtml('type="button" wire:click="setTab(\'Job\')"');
});
