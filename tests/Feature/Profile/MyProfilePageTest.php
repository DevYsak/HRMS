<?php

use App\Livewire\Profile\MyProfile;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ProfileChangeRequest;
use App\Models\User;
use App\Services\Profile\ProfileChangeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * The employee's own profile page. The page must never decide permissions
 * itself — it delegates to ProfileChangeService — so these tests assert the
 * surface honours the tiers rather than re-implementing them.
 */
function selfProfileEmployee(string $name = 'Self Person'): Employee
{
    $user = User::factory()->create(['name' => $name, 'role' => 'employee']);

    return Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);
}

test('the page renders with hero, KPIs and tabs for the signed-in employee', function () {
    $employee = selfProfileEmployee('Render Person');

    Livewire::actingAs($employee->user)
        ->test(MyProfile::class)
        ->assertOk()
        ->assertSee('Render Person')
        ->assertSee('Attendance')
        ->assertSee('Leave balance')
        ->assertSee('Experience')
        ->assertSee('Who can change what');
});

test('a user with no employee record is refused rather than erroring', function () {
    $orphan = User::factory()->create(['role' => 'employee']);

    Livewire::actingAs($orphan)->test(MyProfile::class)->assertForbidden();
});

test('an editable field saves straight from the page', function () {
    $employee = selfProfileEmployee();

    Livewire::actingAs($employee->user)
        ->test(MyProfile::class)
        ->call('editField', 'phone')
        ->assertSet('editingField', 'phone')
        ->set('editingValue', '9812345670')
        ->call('saveField');

    expect($employee->fresh()->phone)->toBe('9812345670');
});

test('an invalid value surfaces the registry message instead of saving', function () {
    $employee = selfProfileEmployee();

    Livewire::actingAs($employee->user)
        ->test(MyProfile::class)
        ->call('editField', 'phone')
        ->set('editingValue', str_repeat('9', 40))
        ->call('saveField')
        ->assertHasErrors('editingValue');

    expect($employee->fresh()->phone)->toBeNull();
});

test('an approval field opens a request and leaves the stored value alone', function () {
    $employee = selfProfileEmployee();
    $employee->update(['address' => 'Old Address']);

    Livewire::actingAs($employee->user)
        ->test(MyProfile::class)
        ->call('requestField', 'address')
        ->set('editingValue', 'New Address')
        ->set('requestReason', 'Moved')
        ->call('submitRequest');

    expect($employee->fresh()->address)->toBe('Old Address');

    $request = ProfileChangeRequest::where('employee_id', $employee->id)->first();
    expect($request)->not->toBeNull()
        ->and($request->field)->toBe('address')
        ->and($request->new_value)->toBe('New Address')
        ->and($request->status)->toBe(ProfileChangeRequest::STATUS_PENDING);
});

test('the page refuses to edit an approval or locked field through the editable path', function () {
    $employee = selfProfileEmployee();

    // Neither opens the edit modal, and neither writes anything.
    Livewire::actingAs($employee->user)
        ->test(MyProfile::class)
        ->call('editField', 'address')
        ->assertSet('editingField', null)
        ->call('editField', 'joining_date')
        ->assertSet('editingField', null);
});

test('a locked field cannot be pushed through the request path either', function () {
    $employee = selfProfileEmployee();

    Livewire::actingAs($employee->user)
        ->test(MyProfile::class)
        ->call('requestField', 'department_id')
        ->assertSet('editingField', null);

    expect(ProfileChangeRequest::where('employee_id', $employee->id)->count())->toBe(0);
});

test('a pending request is surfaced and can be withdrawn by its owner', function () {
    $employee = selfProfileEmployee();

    Livewire::actingAs($employee->user)
        ->test(MyProfile::class)
        ->call('requestField', 'address')
        ->set('editingValue', 'Somewhere')
        ->call('submitRequest')
        ->assertSee('awaiting HR review');

    $request = ProfileChangeRequest::where('employee_id', $employee->id)->first();

    Livewire::actingAs($employee->user)
        ->test(MyProfile::class)
        ->call('withdrawRequest', $request->id);

    expect($request->fresh()->status)->toBe(ProfileChangeRequest::STATUS_CANCELLED);
});

test('a stored photo path is persisted and audited', function () {
    // Asserted at the service rather than through Livewire's temporary-upload
    // plumbing: that path needs the GD extension, which this environment does
    // not have. This covers our own logic — the write and the audit entry.
    $employee = selfProfileEmployee();

    app(ProfileChangeService::class)
        ->updateEditable($employee, 'photo', 'employee-photos/me.png', $employee->user);

    expect($employee->fresh()->photo)->toBe('employee-photos/me.png');

    expect(AuditLog::where('auditable_type', Employee::class)
        ->where('auditable_id', $employee->id)
        ->where('action', 'profile_updated')->exists())->toBeTrue();
});

test('a non-image upload is rejected', function () {
    Storage::fake('public');
    $employee = selfProfileEmployee();

    Livewire::actingAs($employee->user)
        ->test(MyProfile::class)
        ->set('photo', UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'))
        ->assertHasErrors('photo');

    expect($employee->fresh()->photo)->toBeNull();
});

test('the photo is not rendered as an editable text row', function () {
    // Regression: the generic field row opened a text input for the photo,
    // which made uploading impossible. Media belongs to the avatar control.
    $employee = selfProfileEmployee();

    Livewire::actingAs($employee->user)
        ->test(MyProfile::class)
        ->assertDontSee('PROFILE PHOTO')
        ->call('editField', 'photo')
        ->assertSet('editingField', null);   // never opens the text modal
});

test('the work email is shown and clearly locked', function () {
    $employee = selfProfileEmployee();

    Livewire::actingAs($employee->user)
        ->test(MyProfile::class)
        ->assertSee('Work email')
        ->assertSee('also your login');       // the lock explanation
});

test('tabs switch and only known tabs are accepted', function () {
    $employee = selfProfileEmployee();

    Livewire::actingAs($employee->user)
        ->test(MyProfile::class)
        ->assertSet('activeTab', 'overview')
        ->call('setTab', 'employment')
        ->assertSet('activeTab', 'employment')
        ->call('setTab', 'not-a-real-tab')
        ->assertSet('activeTab', 'employment');
});

test('locked employment fields render with their explanation', function () {
    $employee = selfProfileEmployee();

    Livewire::actingAs($employee->user)
        ->test(MyProfile::class)
        ->call('setTab', 'employment')
        ->assertSee('Managed by HR')
        ->assertSee('assigned by HR and never changes');   // employee_id reason
});
