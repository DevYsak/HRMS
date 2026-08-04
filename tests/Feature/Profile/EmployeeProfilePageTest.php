<?php

use App\Livewire\Profile\EmployeeProfile;
use App\Livewire\Profile\MyProfile;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ProfileChangeRequest;
use App\Models\User;
use App\Services\Profile\ProfileChangeService;
use App\Services\Profile\ProfileFieldRegistry as Registry;
use Livewire\Livewire;

/**
 * HR's view of an employee. Same components as the employee's own page,
 * passed as-hr — the difference between the surfaces is authority, not
 * markup, so these tests focus on what HR may additionally do.
 */
function hrProfileEmployee(string $name = 'Subject Person'): Employee
{
    $user = User::factory()->create(['name' => $name, 'role' => 'employee']);

    return Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);
}

function profileReviewer(): User
{
    return User::factory()->create(['role' => 'hr_admin']);
}

test('HR can open an employee profile', function () {
    $employee = hrProfileEmployee('Viewed Person');

    Livewire::actingAs(profileReviewer())
        ->test(EmployeeProfile::class, ['employee' => $employee])
        ->assertOk()
        ->assertSee('Viewed Person')
        ->assertSee('You are editing as HR');
});

test('a plain employee cannot open another employee profile', function () {
    $employee = hrProfileEmployee();
    $other = User::factory()->create(['role' => 'employee']);

    Livewire::actingAs($other)
        ->test(EmployeeProfile::class, ['employee' => $employee])
        ->assertForbidden();
});

test('HR can edit a field the employee sees as locked', function () {
    $employee = hrProfileEmployee();
    $hr = profileReviewer();

    expect(Registry::isLocked('joining_date'))->toBeTrue();

    Livewire::actingAs($hr)
        ->test(EmployeeProfile::class, ['employee' => $employee])
        ->call('editField', 'joining_date')
        ->assertSet('editingField', 'joining_date')
        ->set('editingValue', '2024-04-01')
        ->call('saveField');

    expect($employee->fresh()->joining_date->toDateString())->toBe('2024-04-01');
});

test('HR editing still validates against the registry rules', function () {
    $employee = hrProfileEmployee();

    Livewire::actingAs(profileReviewer())
        ->test(EmployeeProfile::class, ['employee' => $employee])
        ->call('editField', 'pan_number')
        ->set('editingValue', 'NOT-A-PAN')
        ->call('saveField')
        ->assertHasErrors('editingValue');

    expect($employee->fresh()->payrollSettings?->pan_number)->toBeNull();
});

test('HR cannot edit a field that is not in the registry', function () {
    $employee = hrProfileEmployee();

    Livewire::actingAs(profileReviewer())
        ->test(EmployeeProfile::class, ['employee' => $employee])
        ->call('editField', 'password')
        ->assertSet('editingField', null);
});

test('employment status stays owned by the lifecycle workflow', function () {
    // Writing employees.status straight from a profile page would skip
    // EmployeeStatus::allowedTransitions() and the side effects each
    // lifecycle screen applies, so neither surface may write it.
    $employee = hrProfileEmployee();
    $employee->update(['status' => 'active']);

    expect(Registry::isHrEditable('status'))->toBeFalse();

    Livewire::actingAs(profileReviewer())
        ->test(EmployeeProfile::class, ['employee' => $employee])
        ->call('editField', 'status')
        ->assertSet('editingField', null);

    // ...and the service refuses it even if the modal is bypassed.
    expect(fn () => app(ProfileChangeService::class)
        ->updateAsHr($employee, 'status', 'terminated', profileReviewer()))
        ->toThrow(DomainException::class);

    expect($employee->fresh()->status->value)->toBe('active');
});

test('relation and enum fields offer real choices instead of raw ids', function () {
    // HR should never have to type a department id into a text box.
    $department = Department::factory()->create(['name' => 'UK Operations']);

    expect(Registry::optionsFor('department_id'))->toContain('UK Operations')
        ->and(Registry::optionsFor('department_id'))->toHaveKey($department->id)
        ->and(Registry::optionsFor('gender'))->toBe(['male' => 'Male', 'female' => 'Female', 'other' => 'Other'])
        ->and(Registry::optionsFor('status'))->toHaveKey('notice_period')
        ->and(Registry::optionsFor('status')['notice_period'])->toBe('Notice Period')
        // A free-text field has no choices, which is how the input tells
        // "pick one" apart from "type something".
        ->and(Registry::optionsFor('bank_name'))->toBe([]);
});

test('only leadership roles are offered as a reporting manager', function () {
    User::factory()->create(['name' => 'Team Lead', 'role' => 'manager']);
    User::factory()->create(['name' => 'Plain Employee', 'role' => 'employee']);

    $managers = Registry::optionsFor('manager_id');

    expect($managers)->toContain('Team Lead')
        ->and($managers)->not->toContain('Plain Employee');
});

test('HR can reassign a department through the profile page', function () {
    $employee = hrProfileEmployee();
    $department = Department::factory()->create(['name' => 'Finance']);

    Livewire::actingAs(profileReviewer())
        ->test(EmployeeProfile::class, ['employee' => $employee])
        ->call('editField', 'department_id')
        ->set('editingValue', (string) $department->id)
        ->call('saveField');

    expect($employee->fresh()->department_id)->toBe($department->id);
});

test('HR approving a request applies the value and closes it', function () {
    $employee = hrProfileEmployee();
    $employee->update(['address' => 'Old Address']);
    $hr = profileReviewer();

    $request = app(ProfileChangeService::class)
        ->requestChange($employee, 'address', 'New Address', $employee->user, 'Moved');

    Livewire::actingAs($hr)
        ->test(EmployeeProfile::class, ['employee' => $employee])
        ->assertSee('needs a decision')
        ->call('openReview', $request->id)
        ->set('reviewComment', 'Proof seen')
        ->call('approveRequest');

    $request->refresh();
    expect($request->status)->toBe(ProfileChangeRequest::STATUS_APPROVED)
        ->and($request->reviewer_id)->toBe($hr->id)
        ->and($request->reviewer_comment)->toBe('Proof seen')
        ->and($employee->fresh()->address)->toBe('New Address');
});

test('HR rejecting a request leaves the employee record untouched', function () {
    $employee = hrProfileEmployee();
    $employee->update(['address' => 'Old Address']);

    $request = app(ProfileChangeService::class)
        ->requestChange($employee, 'address', 'New Address', $employee->user);

    Livewire::actingAs(profileReviewer())
        ->test(EmployeeProfile::class, ['employee' => $employee])
        ->call('openReview', $request->id)
        ->set('reviewComment', 'Need a utility bill')
        ->call('rejectRequest');

    expect($request->fresh()->status)->toBe(ProfileChangeRequest::STATUS_REJECTED)
        ->and($employee->fresh()->address)->toBe('Old Address');
});

test('a role without approve_profile_changes cannot decide a request', function () {
    $employee = hrProfileEmployee();
    // Director can manage employees but is not granted approve_profile_changes.
    $director = User::factory()->create(['role' => 'director']);

    $request = app(ProfileChangeService::class)
        ->requestChange($employee, 'address', 'New Address', $employee->user);

    expect($director->hasPermission('approve_profile_changes'))->toBeFalse();

    Livewire::actingAs($director)
        ->test(EmployeeProfile::class, ['employee' => $employee])
        ->call('openReview', $request->id)
        ->assertForbidden();

    expect($request->fresh()->status)->toBe(ProfileChangeRequest::STATUS_PENDING);
});

test('an already-resolved request cannot be decided again from the page', function () {
    $employee = hrProfileEmployee();
    $hr = profileReviewer();
    $service = app(ProfileChangeService::class);

    $request = $service->requestChange($employee, 'address', 'New', $employee->user);
    $service->approve($request, $hr);

    Livewire::actingAs($hr)
        ->test(EmployeeProfile::class, ['employee' => $employee])
        ->call('openReview', $request->id)
        ->call('approveRequest');   // surfaces a toast rather than double-applying

    expect(ProfileChangeRequest::where('employee_id', $employee->id)
        ->where('status', ProfileChangeRequest::STATUS_APPROVED)->count())->toBe(1);
});

test('the HR page shows the data-quality flags the import left behind', function () {
    $employee = hrProfileEmployee();
    $employee->update(['has_placeholder_email' => true, 'joining_date' => null]);

    Livewire::actingAs(profileReviewer())
        ->test(EmployeeProfile::class, ['employee' => $employee])
        ->assertSee('Email Pending')
        ->assertSee('Joining Date Missing');
});

test('tabs switch and unknown tabs are ignored', function () {
    $employee = hrProfileEmployee();

    Livewire::actingAs(profileReviewer())
        ->test(EmployeeProfile::class, ['employee' => $employee])
        ->assertSet('activeTab', 'overview')
        ->call('setTab', 'financial')
        ->assertSet('activeTab', 'financial')
        ->call('setTab', 'nonsense')
        ->assertSet('activeTab', 'financial');
});

test('both profile surfaces report the same headline numbers', function () {
    // The two pages share ShowsProfileSummary precisely so they cannot drift.
    $employee = hrProfileEmployee();

    $hrView = Livewire::actingAs(profileReviewer())
        ->test(EmployeeProfile::class, ['employee' => $employee]);

    $selfView = Livewire::actingAs($employee->user)
        ->test(MyProfile::class);

    expect($hrView->viewData('kpis'))->toBe($selfView->viewData('kpis'));
});
