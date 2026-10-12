<?php

use App\Enums\UserRole;
use App\Livewire\Employees\EmployeeEdit;
use App\Models\Employee;
use App\Models\User;
use Livewire\Livewire;

/**
 * Employee record (employees/{id}/edit) — layout only: the More / Account
 * access menu is a Flux dropdown (top-layer popover, never clipped by the
 * hero card) and the Personal tab is one two-column grid of identical cells.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));
});

test('the account access menu is a top-layer dropdown with every action, outside the clipping hero', function () {
    $employee = Employee::factory()->create(['status' => 'active']);

    $html = Livewire::test(EmployeeEdit::class, ['employee' => $employee])
        ->assertSee('View profile')
        ->assertSee('Send email')
        ->assertSeeHtml('aria-label="More actions"')
        ->assertSeeInOrder(['Account access', 'Send reset link', 'Set temporary password', 'Force password reset', 'Record', 'View activity', 'Deactivate employee'])
        ->html();

    // A Flux dropdown/menu (popover="manual" → browser top layer), not an absolutely
    // positioned child of the overflow-hidden hero.
    expect($html)->toMatch('/<ui-dropdown[^>]*data-test="more-actions"/')
        ->and($html)->toMatch('/<ui-menu[^>]*popover="manual"[^>]*data-test="more-actions-menu"|<ui-menu[^>]*data-test="more-actions-menu"[^>]*popover="manual"/')
        ->and($html)->toContain('max-h-[min(32rem,calc(100vh-5rem))] overflow-y-auto')
        ->and($html)->not->toContain('x-data="{ open: false }" class="relative" @click.outside="open = false"');

    // The actions still call the same component methods.
    foreach (['resetPassword', 'setTemporaryPassword', 'forcePasswordReset', "setTab('Activity')", 'deactivate'] as $action) {
        expect($html)->toContain('wire:click="'.$action.'"');
    }
});

test('the personal tab is one grid: Phone | Date of Birth, Gender | Emergency Contact, then full-width rows', function () {
    $employee = Employee::factory()->create(['gender' => 'female']);

    $html = Livewire::test(EmployeeEdit::class, ['employee' => $employee])
        ->call('setTab', 'Personal')
        ->assertSeeHtml('data-personal-grid')
        ->html();

    preg_match('/data-personal-grid.*?(?=<div class="sticky bottom-0)/s', $html, $grid);
    preg_match_all('/data-personal-field="([a-z_]+)"/', $grid[0], $fields);
    expect($fields[1])->toBe(['phone', 'date_of_birth', 'gender', 'emergency_contact', 'address', 'photo']);

    // Every cell has the same shape; the two text rows sit side by side, the last two span both columns.
    expect(substr_count($grid[0], 'class="flex min-w-0 flex-col gap-2"'))->toBe(4)
        ->and(substr_count($grid[0], 'class="flex min-w-0 flex-col gap-2 lg:col-span-2"'))->toBe(2)
        ->and($html)->toContain('<div class="grid grid-cols-1 gap-x-6 gap-y-5 lg:grid-cols-2" data-personal-grid>')
        ->and($grid[0])->not->toContain('x-clean-select')
        ->and($grid[0])->toMatch('/<select[^>]*id="personal-gender"/')
        ->and($grid[0])->toContain('<label for="personal-gender"');
});

test('gender still saves through the same field and rules', function () {
    $employee = Employee::factory()->create(['gender' => 'male']);

    Livewire::test(EmployeeEdit::class, ['employee' => $employee])
        ->call('setTab', 'Personal')
        ->set('gender', 'prefer_not_to_say')
        ->call('save')
        ->assertHasNoErrors();

    expect($employee->fresh()->gender)->toBe('prefer_not_to_say');

    Livewire::test(EmployeeEdit::class, ['employee' => $employee->fresh()])
        ->set('gender', 'not-a-gender')
        ->call('save')
        ->assertHasErrors(['gender']);
});
