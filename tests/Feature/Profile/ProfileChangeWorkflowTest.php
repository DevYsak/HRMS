<?php

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeePayrollSettings;
use App\Models\ProfileChangeRequest;
use App\Models\User;
use App\Services\Profile\ProfileChangeService;
use App\Services\Profile\ProfileFieldRegistry as Registry;
use Illuminate\Validation\ValidationException;

/**
 * The permission model underneath the profile redesign. Three tiers decide who
 * may change what, and the service — not a Blade template — is what enforces
 * them, so these tests are the real security boundary.
 */
function profileService(): ProfileChangeService
{
    return app(ProfileChangeService::class);
}

function profileEmployee(string $name = 'Profile Person'): Employee
{
    $user = User::factory()->create(['name' => $name]);

    return Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);
}

// ── Registry ────────────────────────────────────────────────────────────────

test('every registered field declares a known tier, source and group', function () {
    foreach (Registry::all() as $key => $field) {
        expect($field['tier'])->toBeIn([Registry::TIER_EDITABLE, Registry::TIER_APPROVAL, Registry::TIER_LOCKED], "tier of '{$key}'")
            ->and($field['source'])->toBeIn([Registry::SOURCE_USER, Registry::SOURCE_EMPLOYEE, Registry::SOURCE_PAYROLL], "source of '{$key}'")
            ->and(array_key_exists($field['group'], Registry::GROUPS))->toBeTrue("group of '{$key}'")
            ->and($field['rules'])->not->toBeEmpty("rules of '{$key}'");
    }
});

test('an unregistered field is treated as locked, so new columns fail closed', function () {
    expect(Registry::has('some_column_added_later'))->toBeFalse()
        ->and(Registry::tier('some_column_added_later'))->toBe(Registry::TIER_LOCKED)
        ->and(Registry::isLocked('some_column_added_later'))->toBeTrue();
});

test('every locked field explains why it is locked', function () {
    foreach (Registry::keysByTier(Registry::TIER_LOCKED) as $key) {
        expect(Registry::lockReason($key))->not->toBe('Managed by HR.', "'{$key}' should give a specific reason");
    }
});

test('values resolve from whichever table holds the field', function () {
    $employee = profileEmployee('Source Person');
    $employee->update(['phone' => '9876500000']);
    EmployeePayrollSettings::create(array_merge(
        EmployeePayrollSettings::defaults($employee->id)->toArray(),
        ['employee_id' => $employee->id, 'bank_name' => 'HDFC Bank'],
    ));
    $employee->refresh();

    expect(Registry::valueFor($employee, 'name'))->toBe('Source Person')        // users
        ->and(Registry::valueFor($employee, 'phone'))->toBe('9876500000')       // employees
        ->and(Registry::valueFor($employee, 'bank_name'))->toBe('HDFC Bank');   // payroll settings
});

// ── Editable tier ───────────────────────────────────────────────────────────

test('an editable field saves immediately and is audited', function () {
    $employee = profileEmployee();
    $actor = $employee->user;

    profileService()->updateEditable($employee, 'phone', '9123456780', $actor);

    expect($employee->fresh()->phone)->toBe('9123456780');
    expect(AuditLog::where('auditable_type', Employee::class)
        ->where('auditable_id', $employee->id)
        ->where('action', 'profile_updated')->exists())->toBeTrue();
});

test('an editable field still respects its validation rules', function () {
    $employee = profileEmployee();

    expect(fn () => profileService()->updateEditable($employee, 'phone', str_repeat('9', 40), $employee->user))
        ->toThrow(ValidationException::class);
});

// ── Approval tier ───────────────────────────────────────────────────────────

test('an approval field cannot be saved directly', function () {
    $employee = profileEmployee();

    expect(fn () => profileService()->updateEditable($employee, 'address', '12 New Road', $employee->user))
        ->toThrow(DomainException::class, 'cannot be changed directly');

    expect($employee->fresh()->address)->toBeNull();
});

test('requesting a change leaves the stored value untouched until approved', function () {
    $employee = profileEmployee();
    $employee->update(['address' => 'Old Address']);

    $request = profileService()->requestChange($employee, 'address', 'New Address', $employee->user, 'Moved house');

    expect($request->status)->toBe(ProfileChangeRequest::STATUS_PENDING)
        ->and($request->old_value)->toBe('Old Address')
        ->and($request->new_value)->toBe('New Address');

    // The employee record must never show a value that has not been accepted.
    expect($employee->fresh()->address)->toBe('Old Address');
});

test('approving a request writes the value and audits who approved it', function () {
    $employee = profileEmployee();
    $employee->update(['address' => 'Old Address']);
    $hr = User::factory()->create(['role' => 'hr_admin']);

    $request = profileService()->requestChange($employee, 'address', 'New Address', $employee->user);
    $request = profileService()->approve($request, $hr, 'Proof verified');

    expect($request->status)->toBe(ProfileChangeRequest::STATUS_APPROVED)
        ->and($request->reviewer_id)->toBe($hr->id)
        ->and($request->reviewed_at)->not->toBeNull()
        ->and($employee->fresh()->address)->toBe('New Address');

    $audit = AuditLog::where('auditable_type', Employee::class)
        ->where('auditable_id', $employee->id)
        ->where('action', 'profile_updated')->latest('id')->first();
    expect($audit->reason)->toContain($hr->name);
});

test('rejecting a request leaves the employee record alone', function () {
    $employee = profileEmployee();
    $employee->update(['address' => 'Old Address']);
    $hr = User::factory()->create(['role' => 'hr_admin']);

    $request = profileService()->requestChange($employee, 'address', 'New Address', $employee->user);
    $request = profileService()->reject($request, $hr, 'No proof attached');

    expect($request->status)->toBe(ProfileChangeRequest::STATUS_REJECTED)
        ->and($request->reviewer_comment)->toBe('No proof attached')
        ->and($employee->fresh()->address)->toBe('Old Address');
});

test('only one pending request per field is allowed', function () {
    $employee = profileEmployee();

    profileService()->requestChange($employee, 'address', 'First', $employee->user);

    expect(fn () => profileService()->requestChange($employee, 'address', 'Second', $employee->user))
        ->toThrow(DomainException::class, 'already awaiting review');

    // Once resolved, a fresh request is allowed again.
    $hr = User::factory()->create(['role' => 'hr_admin']);
    profileService()->reject(ProfileChangeRequest::where('employee_id', $employee->id)->first(), $hr);

    $second = profileService()->requestChange($employee, 'address', 'Second', $employee->user);
    expect($second->status)->toBe(ProfileChangeRequest::STATUS_PENDING);
});

test('a resolved request cannot be approved or rejected twice', function () {
    $employee = profileEmployee();
    $hr = User::factory()->create(['role' => 'hr_admin']);

    $request = profileService()->requestChange($employee, 'address', 'New', $employee->user);
    profileService()->approve($request, $hr);

    expect(fn () => profileService()->approve($request->fresh(), $hr))
        ->toThrow(DomainException::class, 'already been resolved');
});

test('only the requester can withdraw their own request', function () {
    $employee = profileEmployee();
    $someoneElse = User::factory()->create();

    $request = profileService()->requestChange($employee, 'address', 'New', $employee->user);

    expect(fn () => profileService()->cancel($request, $someoneElse))
        ->toThrow(DomainException::class, 'Only the person who raised');

    $cancelled = profileService()->cancel($request, $employee->user);
    expect($cancelled->status)->toBe(ProfileChangeRequest::STATUS_CANCELLED);
});

test('an approval request writes to payroll settings when approved', function () {
    $employee = profileEmployee();
    $hr = User::factory()->create(['role' => 'hr_admin']);

    // No payroll settings row exists yet — the service must create one.
    expect(EmployeePayrollSettings::where('employee_id', $employee->id)->exists())->toBeFalse();

    $request = profileService()->requestChange($employee, 'bank_name', 'ICICI Bank', $employee->user);
    profileService()->approve($request, $hr);

    expect($employee->fresh()->payrollSettings?->bank_name)->toBe('ICICI Bank');
});

// ── Locked tier ─────────────────────────────────────────────────────────────

test('a locked field can neither be saved nor requested', function () {
    $employee = profileEmployee();

    expect(fn () => profileService()->updateEditable($employee, 'department_id', 1, $employee->user))
        ->toThrow(DomainException::class, 'cannot be changed directly');

    expect(fn () => profileService()->requestChange($employee, 'joining_date', '2020-01-01', $employee->user))
        ->toThrow(DomainException::class, 'managed by HR');
});

test('every locked field is genuinely unreachable from the employee paths', function () {
    $employee = profileEmployee();

    foreach (Registry::keysByTier(Registry::TIER_LOCKED) as $key) {
        expect(fn () => profileService()->updateEditable($employee, $key, 'x', $employee->user))
            ->toThrow(DomainException::class, null, "'{$key}' must not be self-editable");
        expect(fn () => profileService()->requestChange($employee, $key, 'x', $employee->user))
            ->toThrow(DomainException::class, null, "'{$key}' must not be requestable");
    }
});

// ── HR path ─────────────────────────────────────────────────────────────────

test('HR can write a locked field directly, and it is audited as an HR change', function () {
    $employee = profileEmployee();
    $hr = User::factory()->create(['role' => 'hr_admin']);

    profileService()->updateAsHr($employee, 'joining_date', '2024-04-01', $hr);

    expect($employee->fresh()->joining_date->toDateString())->toBe('2024-04-01');

    $audit = AuditLog::where('auditable_type', Employee::class)
        ->where('auditable_id', $employee->id)->latest('id')->first();
    expect($audit->reason)->toContain('Updated by HR');
});

test('HR cannot write a field that is not in the registry at all', function () {
    $employee = profileEmployee();
    $hr = User::factory()->create(['role' => 'hr_admin']);

    expect(fn () => profileService()->updateAsHr($employee, 'password', 'hunter2', $hr))
        ->toThrow(DomainException::class, 'Unknown profile field');
});

// ── Permissions ─────────────────────────────────────────────────────────────

test('the profile permission keys are seeded and scoped to the right roles', function () {
    $employee = User::factory()->create(['role' => 'employee']);
    $hr = User::factory()->create(['role' => 'hr_admin']);

    expect($employee->hasPermission('edit_own_profile'))->toBeTrue()
        ->and($employee->hasPermission('request_profile_change'))->toBeTrue()
        ->and($employee->hasPermission('approve_profile_changes'))->toBeFalse();

    expect($hr->hasPermission('approve_profile_changes'))->toBeTrue();
});

// ── Request presentation ────────────────────────────────────────────────────

test('a request exposes a timeline the requester can follow', function () {
    $employee = profileEmployee();
    $hr = User::factory()->create(['role' => 'hr_admin', 'name' => 'HR Reviewer']);

    $request = profileService()->requestChange($employee, 'address', 'New', $employee->user);
    expect($request->timelineSteps())->toHaveCount(2)
        ->and($request->timelineSteps()[1]['label'])->toContain('Awaiting HR');

    $approved = profileService()->approve($request, $hr);
    expect($approved->timelineSteps()[1]['label'])->toBe('Approved by HR')
        ->and($approved->timelineSteps()[1]['user'])->toBe('HR Reviewer');
});

test('a request labels its field from the registry, not the raw column name', function () {
    $employee = profileEmployee();
    $request = profileService()->requestChange($employee, 'aadhar_number', '123456789012', $employee->user);

    expect($request->fieldLabel())->toBe('Aadhaar');
});
