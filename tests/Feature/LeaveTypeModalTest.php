<?php

use App\Enums\UserRole;
use App\Livewire\TimeOff\TimeOffSettings;
use App\Models\LeaveType;
use App\Models\User;
use Livewire\Livewire;

/**
 * The Add / Edit Leave Type modal must always be closable and must reset.
 *
 * It used to close only through a server round-trip, kept old validation
 * errors, and crashed with a 500 when a number field was cleared — any of
 * which left it stuck open.
 */
function ltmAdmin(): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin]);
}

test('Add Leave Type opens a fresh, empty form', function () {
    Livewire::actingAs(ltmAdmin())->test(TimeOffSettings::class)
        ->call('openModal')
        ->assertSet('showModal', true)
        ->assertSet('editingId', null)
        ->assertSet('name', '')
        ->assertSee('Add Leave Type');
});

test('closing resets the modal state and its validation errors', function () {
    Livewire::actingAs(ltmAdmin())->test(TimeOffSettings::class)
        ->call('openModal')
        ->set('name', '')
        ->call('save')
        ->assertHasErrors('name')
        ->call('closeModal')
        ->assertSet('showModal', false)
        ->assertSet('editingId', null)
        ->assertHasNoErrors();
});

test('reopening does not bring back errors from the last attempt', function () {
    $type = LeaveType::create(['name' => 'Sick Leave', 'code' => 'SL', 'category' => 'sick', 'color' => '#22C55E']);

    Livewire::actingAs(ltmAdmin())->test(TimeOffSettings::class)
        ->call('openModal')
        ->call('save')
        ->assertHasErrors('name')
        ->call('openModal', $type->id)
        ->assertHasNoErrors()
        ->assertSet('name', 'Sick Leave')
        ->assertSee('Edit Leave Type');
});

test('every open is a new modal element, so a hidden leftover is never reused', function () {
    $component = Livewire::actingAs(ltmAdmin())->test(TimeOffSettings::class)->call('openModal');
    $first = $component->get('modalVersion');

    $component->call('closeModal')->call('openModal');

    expect($component->get('modalVersion'))->toBe($first + 1);
});

test('a cleared number field saves as empty instead of crashing', function () {
    Livewire::actingAs(ltmAdmin())->test(TimeOffSettings::class)
        ->call('openModal')
        ->set('name', 'Study Leave')
        ->set('code', 'STY')
        ->set('carry_forward_limit', '')
        ->set('accrual_days_per_month', '')
        ->set('max_consecutive_days', '')
        ->set('annual_allocation_days', '')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    $type = LeaveType::where('code', 'STY')->first();
    expect((int) $type->carry_forward_limit)->toBe(0)
        ->and($type->max_consecutive_days)->toBeNull()
        ->and($type->annual_allocation_days)->toBeNull();
});

test('an invalid value keeps the modal open with a visible error summary', function () {
    Livewire::actingAs(ltmAdmin())->test(TimeOffSettings::class)
        ->call('openModal')
        ->set('name', 'Study Leave')
        ->set('carry_forward_limit', '-2')
        ->set('max_consecutive_days', '0')
        ->call('save')
        ->assertHasErrors(['carry_forward_limit', 'max_consecutive_days'])
        ->assertSet('showModal', true)
        ->assertSee('Please fix the following');
});

test('saving a new leave type closes the modal and stores the numbers', function () {
    Livewire::actingAs(ltmAdmin())->test(TimeOffSettings::class)
        ->call('openModal')
        ->set('name', 'Study Leave')
        ->set('code', 'STL')
        ->set('max_consecutive_days', '5')
        ->set('annual_allocation_days', '')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    $type = LeaveType::where('code', 'STL')->first();
    expect($type)->not->toBeNull()
        ->and((int) $type->max_consecutive_days)->toBe(5)
        ->and($type->annual_allocation_days)->toBeNull();
});

test('editing CSL keeps its id and does not create a new type', function () {
    $csl = LeaveType::create([
        'name' => 'Casual / Sick Leave (CSL)', 'code' => 'CSL', 'category' => 'annual',
        'is_paid' => true, 'allow_paid_request' => true, 'color' => '#F97316',
    ]);
    $count = LeaveType::count();

    Livewire::actingAs(ltmAdmin())->test(TimeOffSettings::class)
        ->call('openModal', $csl->id)
        ->set('color', '#EA580C')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    expect(LeaveType::count())->toBe($count)
        ->and(LeaveType::where('code', 'CSL')->value('id'))->toBe($csl->id)
        ->and($csl->fresh()->color)->toBe('#EA580C');
});

test('the modal markup closes client-side on X, Cancel, Escape and the backdrop', function () {
    $html = Livewire::actingAs(ltmAdmin())->test(TimeOffSettings::class)->call('openModal')->html();

    expect($html)->toContain('x-trap.noscroll="open"')
        ->toContain('x-on:keydown.escape.window')
        ->toContain('$wire.closeModal()')
        ->and(substr_count($html, '@click="close()"'))->toBeGreaterThanOrEqual(3)
        ->and($html)->not->toContain("\$wire.set('showModal', false)");
});

test('a non-admin cannot open the modal', function () {
    Livewire::actingAs(User::factory()->create(['role' => UserRole::Employee]))
        ->test(TimeOffSettings::class)
        ->assertForbidden();
});
