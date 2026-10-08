<?php

use App\Livewire\Profile\EmployeeProfile;
use App\Livewire\Profile\MyProfile;
use App\Livewire\TimeOff\MyTimeOff;
use App\Models\Employee;
use App\Models\User;
use Livewire\Livewire;

/**
 * A validation error must appear only under the field that failed. These pin
 * the reported bug — editing Account number showed "The Aadhaar field must be
 * 12 digits." — and the same class of leak elsewhere.
 */
function femEmployee(): Employee
{
    $user = User::factory()->create(['role' => 'employee']);

    return Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);
}

test('an Aadhaar error never shows when the employee next opens Account number', function () {
    $employee = femEmployee();

    Livewire::actingAs($employee->user)
        ->test(MyProfile::class)
        ->call('requestField', 'aadhar_number')
        ->set('editingValue', '12345')
        ->call('submitRequest')
        ->assertHasErrors('editingValue')
        ->assertSee('Aadhaar')
        // The user cancels and opens a different field.
        ->call('closeRequestModal')
        ->call('requestField', 'account_number')
        ->assertSet('editingField', 'account_number')
        ->assertHasNoErrors('editingValue')
        ->assertDontSee('must be 12 digits');
});

test('dismissing the request modal with X, Esc or the backdrop clears its error', function () {
    $employee = femEmployee();

    // Flux sends @close as a closeRequestModal call however the modal closes.
    Livewire::actingAs($employee->user)
        ->test(MyProfile::class)
        ->call('requestField', 'pan_number')
        ->set('editingValue', 'NOT-A-PAN')
        ->call('submitRequest')
        ->assertHasErrors('editingValue')
        ->call('closeRequestModal')
        ->assertHasNoErrors()
        ->assertSet('editingField', null);
});

test('opening another field directly also starts with a clean error bag', function () {
    $employee = femEmployee();

    Livewire::actingAs($employee->user)
        ->test(MyProfile::class)
        ->call('requestField', 'aadhar_number')
        ->set('editingValue', '12')
        ->call('submitRequest')
        ->assertHasErrors('editingValue')
        // No cancel — straight to the next field's button.
        ->call('requestField', 'ifsc_code')
        ->assertHasNoErrors();
});

test('a valid request after a failed one sends cleanly and leaves no error behind', function () {
    $employee = femEmployee();

    Livewire::actingAs($employee->user)
        ->test(MyProfile::class)
        ->call('requestField', 'aadhar_number')
        ->set('editingValue', '123')
        ->call('submitRequest')
        ->assertHasErrors('editingValue')
        ->set('editingValue', '123456789012')
        ->call('submitRequest')
        ->assertHasNoErrors()
        ->call('requestField', 'account_number')
        ->assertHasNoErrors();
});

test('HR editing: an error on one field does not follow HR to the next field', function () {
    $employee = femEmployee();
    $hr = User::factory()->create(['role' => 'hr_admin']);

    Livewire::actingAs($hr)
        ->test(EmployeeProfile::class, ['employee' => $employee])
        ->call('editField', 'aadhar_number')
        ->set('editingValue', '999')
        ->call('saveField')
        ->assertHasErrors('editingValue')
        ->call('closeFieldModal')
        ->call('editField', 'account_number')
        ->assertSet('editingField', 'account_number')
        ->assertHasNoErrors();
});

test('HR editing: the message names the field that actually failed', function () {
    $employee = femEmployee();
    $hr = User::factory()->create(['role' => 'hr_admin']);

    $component = Livewire::actingAs($hr)
        ->test(EmployeeProfile::class, ['employee' => $employee])
        ->call('editField', 'ifsc_code')
        ->set('editingValue', 'bad')
        ->call('saveField')
        ->assertHasErrors('editingValue');

    $message = $component->errors()->first('editingValue');

    expect($message)->toContain('IFSC')
        ->and($message)->not->toContain('Aadhaar');
});

test('a leave application is never blocked by encashment fields', function () {
    $employee = femEmployee();

    // The encash flag left on (e.g. the CSL card's Encash opened the form)
    // used to make the encashment fields required for every leave submit.
    Livewire::actingAs($employee->user)
        ->test(MyTimeOff::class)
        ->set('showEncashModal', true)
        ->call('submitRequest')
        ->assertHasErrors('leave_type_id')
        ->assertHasNoErrors(['encash_leave_type_id', 'encash_days']);
});

test('the encashment form validates its own fields, and reopening clears them', function () {
    $employee = femEmployee();

    Livewire::actingAs($employee->user)
        ->test(MyTimeOff::class)
        ->call('openEncashModal')
        ->call('submitEncashment')
        ->assertHasErrors(['encash_leave_type_id', 'encash_days'])
        ->assertHasNoErrors(['leave_type_id', 'reason'])
        ->call('closeEncashModal')
        ->assertHasNoErrors()
        ->call('openEncashModal')
        ->assertHasNoErrors();
});
