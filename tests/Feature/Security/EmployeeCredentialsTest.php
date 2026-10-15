<?php

use App\Livewire\Employees\EmployeeEdit;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\Security\RoleDelegationGuard;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * QA-F-030: login credentials need manage_employee_credentials (Super Admin
 * and HR Admin by default). Editing employees — at any scope — never
 * implies control of their account.
 */
function credUser(string $slug, Department $department, array $attributes = []): User
{
    $role = Role::where('slug', $slug)->firstOrFail();
    $user = User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id, 'email_verified_at' => now(), ...$attributes]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active', 'manager_id' => null]);
    if ($slug === 'department_head') {
        $department->update(['head_id' => $user->id]);
    }

    return $user->fresh();
}

beforeEach(function () {
    Mail::fake();
    $this->ops = Department::factory()->create(['name' => 'Cred Operations']);
    $this->target = credUser('employee', $this->ops, ['password' => Hash::make('Original#Pass1')])->employee;
});

test('the permission is held by Super Admin and HR Admin only, and only a Super Admin can hand it on', function () {
    $holders = Role::query()->get()->filter(fn (Role $r) => in_array('manage_employee_credentials', $r->permissionKeys(), true))->pluck('slug')->sort()->values()->all();

    expect($holders)->toBe(['hr_admin', 'super_admin'])
        ->and(RoleDelegationGuard::PRIVILEGED)->toContain('manage_employee_credentials');
});

test('Super Admin and HR Admin can manage credentials', function (string $slug) {
    $actor = credUser($slug, $this->ops);

    Livewire::actingAs($actor)->test(EmployeeEdit::class, ['employee' => $this->target])
        ->assertSee('Set temporary password')
        ->call('setTemporaryPassword')->assertHasNoErrors()->assertSet('showCredentialsModal', true);
    expect(Hash::check('Original#Pass1', $this->target->user->fresh()->password))->toBeFalse();

    Livewire::actingAs($actor)->test(EmployeeEdit::class, ['employee' => $this->target->fresh()])
        ->call('resetPassword')->assertHasNoErrors();
    expect($actor->can('invite', $this->target))->toBeTrue();
})->with(['super_admin', 'hr_admin']);

test('a Director cannot touch credentials, even inside their own department — direct calls fail', function () {
    $director = credUser('director', $this->ops);
    $before = $this->target->user->password;

    // Department-scoped edit stays (QA-F-016); the credential controls are hidden…
    Livewire::actingAs($director)->test(EmployeeEdit::class, ['employee' => $this->target])
        ->assertOk()->assertDontSee('Set temporary password')->assertDontSee('Send reset link');

    // …and refused on the server when called directly.
    foreach (['resetPassword', 'setTemporaryPassword', 'forcePasswordReset'] as $action) {
        Livewire::actingAs($director)->test(EmployeeEdit::class, ['employee' => $this->target->fresh()])->call($action)->assertForbidden();
    }

    expect($this->target->user->fresh()->password)->toBe($before)
        ->and((bool) $this->target->user->fresh()->must_change_password)->toBeFalse()
        ->and($director->can('invite', $this->target))->toBeFalse()
        ->and($director->can('update', $this->target))->toBeTrue();
});

test('Department Heads and Managers have no credential control', function (string $slug) {
    $actor = credUser($slug, $this->ops);
    $this->target->update(['manager_id' => $actor->id]);

    expect($actor->can('manageCredentials', $this->target->fresh()))->toBeFalse()
        ->and($actor->can('invite', $this->target->fresh()))->toBeFalse();
    $this->actingAs($actor)->get(route('employees.edit', $this->target))->assertForbidden();
})->with(['department_head', 'manager', 'coordinator', 'finance']);

test('editing permission never implies credential control; a revoke beats the HR role', function () {
    $hr = credUser('hr_admin', $this->ops);
    UserPermissionOverride::create(['user_id' => $hr->id, 'permission_id' => Permission::where('key', 'manage_employee_credentials')->value('id'), 'effect' => 'revoke']);

    expect($hr->fresh()->can('update', $this->target))->toBeTrue()
        ->and($hr->fresh()->can('manageCredentials', $this->target))->toBeFalse();
    Livewire::actingAs($hr->fresh())->test(EmployeeEdit::class, ['employee' => $this->target])->call('forcePasswordReset')->assertForbidden();
});

test("HR cannot manage a Super Admin's credentials", function () {
    $hr = credUser('hr_admin', $this->ops);
    $admin = credUser('super_admin', $this->ops);

    expect($hr->can('manageCredentials', $admin->employee))->toBeFalse();
});

test('a Super Admin viewing as a Director gets the Director\'s permissions: no credential control', function () {
    $admin = credUser('super_admin', $this->ops);
    $director = credUser('director', $this->ops);

    $this->actingAs($admin)->post(route('impersonate.start', $director))->assertRedirect();
    Livewire::actingAs($director)->test(EmployeeEdit::class, ['employee' => $this->target])->call('setTemporaryPassword')->assertForbidden();
});

test('a Super Admin viewing as HR may act with HR permissions; the audit names the acting account and the target', function () {
    $admin = credUser('super_admin', $this->ops);
    $hr = credUser('hr_admin', $this->ops);

    $this->actingAs($admin)->post(route('impersonate.start', $hr))->assertRedirect();
    expect(session('impersonator_id'))->toBe($admin->id);
    Livewire::actingAs($hr)->test(EmployeeEdit::class, ['employee' => $this->target->fresh()])->call('setTemporaryPassword')->assertHasNoErrors();

    // The real admin is added to every audit entry from the browser session
    // (AuditLog::record; pinned in AuditTrailIntegrityTest). Here: actor + target.
    $entry = AuditLog::where('event', 'EMPLOYEE_TEMPORARY_PASSWORD_SET')->latest('id')->firstOrFail();
    expect($entry->user_id)->toBe($hr->id)
        ->and($entry->subject_employee_id)->toBe($this->target->id)
        ->and($entry->new_values['target_user_id'])->toBe($this->target->user_id)
        ->and($entry->category)->toBe('security');
});

test('every credential action is audited with the actor and the target', function () {
    $hr = credUser('hr_admin', $this->ops);

    foreach (['resetPassword' => 'EMPLOYEE_PASSWORD_RESET_LINK_SENT', 'setTemporaryPassword' => 'EMPLOYEE_TEMPORARY_PASSWORD_SET'] as $action => $event) {
        Livewire::actingAs($hr)->test(EmployeeEdit::class, ['employee' => $this->target->fresh()])->call($action);
        $entry = AuditLog::where('event', $event)->latest('id')->firstOrFail();
        expect($entry->user_id)->toBe($hr->id)->and($entry->subject_employee_id)->toBe($this->target->id);
    }
});
