<?php

use App\Enums\UserRole;
use App\Livewire\Employees\EmployeeCreate;
use App\Livewire\Employees\EmployeeEdit;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * New accounts start on one shared temporary password. It must open nothing
 * but the "Set your password" page until the employee chooses their own.
 */
const FLP_NEW_PASSWORD = 'MyOwn!Choice#2026x';

function flpTemporary(): string
{
    return config('security.temporary_password');
}

function flpEmployee(array $attributes = [], bool $temporary = true): User
{
    $factory = User::factory();

    if ($temporary) {
        $factory = $factory->onTemporaryPassword();
    }

    $user = $factory->create($attributes + ['role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    return $user;
}

function flpSetPassword(string $password = FLP_NEW_PASSWORD, ?string $confirmation = null): array
{
    return ['password' => $password, 'password_confirmation' => $confirmation ?? $password];
}

beforeEach(function () {
    $this->withoutVite();
});

// ── Creation ───────────────────────────────────────────────────────────────

test('a newly created employee starts on the temporary password with a reset required', function () {
    Mail::fake();
    Notification::fake();

    $hrAdmin = User::factory()->create(['role' => UserRole::HrAdmin]);

    Livewire::actingAs($hrAdmin)->test(EmployeeCreate::class)
        ->set('name', 'First Login')
        ->set('email', 'first.login@conexus-ns.com')
        ->call('save')
        ->assertHasNoErrors();

    $user = User::where('email', 'first.login@conexus-ns.com')->first();

    expect($user->requiresPasswordChange())->toBeTrue()
        ->and($user->password_changed_at)->toBeNull()
        ->and($user->password)->not->toBe(flpTemporary())
        ->and(Hash::check(flpTemporary(), $user->password))->toBeTrue();
});

test('existing employees are not put into a reset by the new column', function () {
    $user = flpEmployee(['password' => 'Their!Own#Passw0rd'], temporary: false);

    expect($user->fresh()->requiresPasswordChange())->toBeFalse();

    $this->post('/login', ['email' => $user->email, 'password' => 'Their!Own#Passw0rd']);

    $this->assertAuthenticatedAs($user);
    $this->get(route('dashboard'))->assertOk();
    expect($user->fresh()->requiresPasswordChange())->toBeFalse();
});

// ── Signing in on the temporary password ───────────────────────────────────

test('the temporary password signs in', function () {
    $user = flpEmployee();

    $this->post('/login', ['email' => $user->email, 'password' => flpTemporary()]);

    $this->assertAuthenticatedAs($user);
});

test('signing in with the temporary password flags an account that was not flagged', function () {
    // e.g. hrms:reset-admin --password set it directly.
    $user = flpEmployee(['password' => flpTemporary()], temporary: false);

    $this->post('/login', ['email' => $user->email, 'password' => flpTemporary()]);

    expect($user->fresh()->requiresPasswordChange())->toBeTrue();
    $this->get(route('dashboard'))->assertRedirect(route('password.first-change'));
});

test('a reset-required account is kept out of every module', function (string $routeName) {
    $user = flpEmployee();

    $this->actingAs($user)->get(route($routeName))->assertRedirect(route('password.first-change'));
})->with([
    'dashboard' => 'dashboard',
    'leave' => 'time-off.my',
    'attendance' => 'attendance.my',
    'payslips' => 'payroll.payslips',
    'profile' => 'profile.me',
    'settings' => 'security.edit',
]);

test('a reset-required account cannot act through the Livewire endpoint', function () {
    $user = flpEmployee();

    $this->actingAs($user)
        ->withHeader('X-Livewire', '1')
        ->postJson(app('livewire')->getUpdateUri(), ['components' => []])
        ->assertForbidden();
});

test('a reset-required account may log out', function () {
    $user = flpEmployee();

    $this->actingAs($user)->post(route('logout'));

    $this->assertGuest();
});

test('the set-password page is reachable and does not ask for the temporary password', function () {
    $user = flpEmployee();

    $this->actingAs($user)->get(route('password.first-change'))
        ->assertOk()
        ->assertSee('Welcome to Pulse HRMS')
        ->assertSee('Save &amp; Continue', false)
        ->assertDontSee('current_password')
        ->assertDontSee(flpTemporary());
});

test('an account without a reset required is sent away from the set-password page', function () {
    $user = flpEmployee(temporary: false);

    $this->actingAs($user)->get(route('password.first-change'))->assertRedirect(route('dashboard'));
});

// ── Choosing the password ──────────────────────────────────────────────────

test('a mismatched confirmation is rejected', function () {
    $user = flpEmployee();

    $this->actingAs($user)
        ->from(route('password.first-change'))
        ->post(route('password.first-change.update'), flpSetPassword(FLP_NEW_PASSWORD, 'Something!Else#2026'))
        ->assertSessionHasErrors('password');

    expect($user->fresh()->requiresPasswordChange())->toBeTrue();
});

test('the temporary password cannot be chosen as the new one', function () {
    $user = flpEmployee();

    $this->actingAs($user)
        ->from(route('password.first-change'))
        ->post(route('password.first-change.update'), flpSetPassword(flpTemporary()))
        ->assertSessionHasErrors('password');

    expect($user->fresh()->requiresPasswordChange())->toBeTrue();
});

test('a valid password is hashed, clears the flag, stamps the change and reaches the dashboard', function () {
    $user = flpEmployee();

    $this->actingAs($user);
    $sessionBefore = session()->getId();

    $this->post(route('password.first-change.update'), flpSetPassword())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $fresh = $user->fresh();

    expect($fresh->password)->not->toBe(FLP_NEW_PASSWORD)
        ->and(Hash::check(FLP_NEW_PASSWORD, $fresh->password))->toBeTrue()
        ->and($fresh->requiresPasswordChange())->toBeFalse()
        ->and($fresh->password_changed_at)->not->toBeNull()
        ->and(session()->getId())->not->toBe($sessionBefore);

    $this->get(route('dashboard'))->assertOk();
    $this->get(route('password.first-change'))->assertRedirect(route('dashboard'));

    expect(AuditLog::where('event', 'EMPLOYEE_FIRST_PASSWORD_CHANGED')->where('auditable_id', $user->id)->exists())->toBeTrue();
});

test('afterwards the new password signs in and the temporary one does not', function () {
    $user = flpEmployee();

    $this->actingAs($user)->post(route('password.first-change.update'), flpSetPassword());
    $this->post(route('logout'));
    $this->assertGuest();

    $this->post('/login', ['email' => $user->email, 'password' => flpTemporary()]);
    $this->assertGuest();

    $this->post('/login', ['email' => $user->email, 'password' => FLP_NEW_PASSWORD]);
    $this->assertAuthenticatedAs($user);
    $this->get(route('dashboard'))->assertOk();
});

test('no password, plaintext or hashed, reaches the audit log', function () {
    $user = flpEmployee();

    $this->post('/login', ['email' => $user->email, 'password' => flpTemporary()]);
    $this->post(route('password.first-change.update'), flpSetPassword());

    $hash = $user->fresh()->password;
    $logged = AuditLog::all()->map(fn (AuditLog $log) => json_encode([$log->old_values, $log->new_values, $log->reason]))->implode("\n");

    expect($logged)->not->toContain(flpTemporary())
        ->and($logged)->not->toContain(FLP_NEW_PASSWORD)
        ->and($logged)->not->toContain($hash);
});

// ── Authority over somebody else's flag ────────────────────────────────────

test('nobody can clear another employee\'s reset flag through the set-password route', function () {
    $other = flpEmployee();
    $attacker = flpEmployee(temporary: false);

    $this->actingAs($attacker)
        ->post(route('password.first-change.update'), flpSetPassword() + ['user_id' => $other->id, 'email' => $other->email])
        ->assertRedirect(route('dashboard'));

    expect($other->fresh()->requiresPasswordChange())->toBeTrue()
        ->and(Hash::check(flpTemporary(), $other->fresh()->password))->toBeTrue();
});

test('a reset-required employee choosing their password changes only their own account', function () {
    $other = flpEmployee();
    $self = flpEmployee();

    $this->actingAs($self)->post(route('password.first-change.update'), flpSetPassword() + ['user_id' => $other->id]);

    expect($self->fresh()->requiresPasswordChange())->toBeFalse()
        ->and($other->fresh()->requiresPasswordChange())->toBeTrue();
});

// ── HR: status and forced reset ────────────────────────────────────────────

test('HR sees the password status but never the password', function () {
    $hrAdmin = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = flpEmployee()->employee;

    Livewire::actingAs($hrAdmin)->test(EmployeeEdit::class, ['employee' => $employee])
        ->assertSee('Reset required')
        ->assertDontSee(flpTemporary());
});

test('HR can force a reset without changing or revealing the password', function () {
    $hrAdmin = User::factory()->create(['role' => UserRole::HrAdmin]);
    $user = flpEmployee(['password' => 'Their!Own#Passw0rd'], temporary: false);

    Livewire::actingAs($hrAdmin)->test(EmployeeEdit::class, ['employee' => $user->employee])
        ->call('forcePasswordReset')
        ->assertSee('Reset required');

    $fresh = $user->fresh();

    expect($fresh->requiresPasswordChange())->toBeTrue()
        ->and(Hash::check('Their!Own#Passw0rd', $fresh->password))->toBeTrue();

    $audit = AuditLog::where('event', 'EMPLOYEE_PASSWORD_RESET_FORCED')->where('auditable_id', $user->id)->first();

    expect($audit)->not->toBeNull()
        ->and($audit->user_id)->toBe($hrAdmin->id);

    // Their live session is confined at once, not at next sign-in.
    $this->actingAs($fresh)->get(route('dashboard'))->assertRedirect(route('password.first-change'));
});

test('an employee without employee-management rights cannot force a reset', function () {
    $user = flpEmployee(temporary: false);
    $target = flpEmployee(temporary: false);

    Livewire::actingAs($user)->test(EmployeeEdit::class, ['employee' => $target->employee])
        ->assertForbidden();

    expect($target->fresh()->requiresPasswordChange())->toBeFalse();
});

test('HR cannot force a Super Admin into a reset', function () {
    $hrAdmin = User::factory()->create(['role' => UserRole::HrAdmin]);
    $superAdmin = flpEmployee(['role' => UserRole::SuperAdmin], temporary: false);

    // HR cannot even open the Super Admin's record (EmployeePolicy::update),
    // so there is no screen to force the reset from.
    Livewire::actingAs($hrAdmin)->test(EmployeeEdit::class, ['employee' => $superAdmin->employee])
        ->assertForbidden();

    expect($superAdmin->fresh()->requiresPasswordChange())->toBeFalse();
});
