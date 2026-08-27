<?php

use App\Enums\UserRole;
use App\Exceptions\InvitationNotAllowed;
use App\Livewire\Employees\EmployeeIndex;
use App\Livewire\Profile\EmployeeProfile;
use App\Mail\EmployeeInvitationMail;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Models\User;
use App\Services\EmployeeInvitationService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Handing somebody a login.
 *
 * Import used to be able to mail credentials to everyone it created, which
 * meant a half-finished row, a duplicate, or a person with a generated
 * placeholder address could all be issued access before anybody looked at
 * them. Access is now a separate, deliberate act: HR checks the record, then
 * invites.
 *
 * The temporary password is never stored, never logged and never shown in the
 * employee list. It exists in the email that was sent, and nowhere else.
 */
function invHr(): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin]);
}

function invEmployee(array $userAttributes = [], array $employeeAttributes = []): Employee
{
    $user = User::factory()->create($userAttributes + [
        'email' => 'new.starter'.Str::random(4).'@conexus-ns.com',
        'role' => UserRole::Employee,
        'last_login_at' => null,
    ]);

    return Employee::factory()->create($employeeAttributes + [
        'user_id' => $user->id,
        'status' => 'active',
    ]);
}

function invService(): EmployeeInvitationService
{
    return app(EmployeeInvitationService::class);
}

beforeEach(function () {
    Mail::fake();
});

// ── 1. The happy path ──────────────────────────────────────────────────────

test('inviting an employee sends them a login', function () {
    $employee = invEmployee();

    $invitation = invService()->invite($employee, invHr());

    expect($invitation->sent_to)->toBe($employee->user->email)
        ->and($invitation->accepted_at)->toBeNull()
        ->and($invitation->resend_count)->toBe(0);

    Mail::assertSent(EmployeeInvitationMail::class, fn ($mail) => $mail->hasTo($employee->user->email));
});

test('the invitation expires 48 hours out by default', function () {
    config(['security.invitation_expiry_hours' => 48]);

    $invitation = invService()->invite(invEmployee(), invHr());

    expect($invitation->expires_at->diffInHours($invitation->invited_at, true))->toBe(48.0);
});

test('the expiry window is configurable', function () {
    config(['security.invitation_expiry_hours' => 6]);

    $invitation = invService()->invite(invEmployee(), invHr());

    expect($invitation->expires_at->diffInHours($invitation->invited_at, true))->toBe(6.0);
});

// ── 2. Eligibility ─────────────────────────────────────────────────────────

test('a missing work email blocks the invitation', function () {
    $employee = invEmployee(['email' => '']);

    expect(fn () => invService()->invite($employee, invHr()))
        ->toThrow(InvitationNotAllowed::class, 'Cannot send invitation — employee work email is missing.');

    Mail::assertNothingSent();
});

test('a generated placeholder address is not a work email', function () {
    // The importer invents an address so a user row can exist at all. It
    // belongs to nobody, so mailing a live credential to it would be worse
    // than refusing.
    $employee = invEmployee(['email' => 'yogesh.sapkal@conexus.local'], ['has_placeholder_email' => true]);

    expect(fn () => invService()->invite($employee, invHr()))
        ->toThrow(InvitationNotAllowed::class);

    Mail::assertNothingSent();
});

test('somebody who has left cannot be issued a login', function () {
    $employee = invEmployee([], ['status' => 'terminated']);

    expect(fn () => invService()->invite($employee, invHr()))
        ->toThrow(InvitationNotAllowed::class);

    Mail::assertNothingSent();
});

test('an employee who already signed in is not invited again', function () {
    $employee = invEmployee(['last_login_at' => now()->subDay()]);

    expect(fn () => invService()->invite($employee, invHr()))
        ->toThrow(InvitationNotAllowed::class, 'This employee already has an active login. Reset their password instead.');
});

// ── 3. The credential itself ───────────────────────────────────────────────

test('the temporary password is not stored anywhere in plaintext', function () {
    $employee = invEmployee();
    $before = $employee->user->password;

    invService()->invite($employee, invHr());

    $sent = null;
    Mail::assertSent(EmployeeInvitationMail::class, function ($mail) use (&$sent) {
        $sent = $mail->temporaryPassword;

        return true;
    });

    $user = $employee->user->fresh();

    expect($sent)->not->toBeNull()
        ->and($user->password)->not->toBe($before)
        ->and($user->password)->not->toBe($sent)
        ->and(Hash::check($sent, $user->password))->toBeTrue();

    // Not in the invitation row, and not in any audit entry either.
    $invitation = EmployeeInvitation::first();
    expect(json_encode($invitation->toArray()))->not->toContain($sent);

    foreach (AuditLog::all() as $log) {
        expect(json_encode($log->toArray()))->not->toContain($sent);
    }
});

test('the temporary password is not guessable from the employee', function () {
    $user = User::factory()->create(['name' => 'Yogesh Sapkal', 'email' => 'yogesh@conexus-ns.com', 'role' => UserRole::Employee]);
    $employee = Employee::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
        'employee_id' => 'CNS021',
        'employee_code' => 17,
        'phone' => '9876543210',
    ]);

    invService()->invite($employee, invHr());

    Mail::assertSent(EmployeeInvitationMail::class, function ($mail) {
        $password = Str::lower($mail->temporaryPassword);

        return strlen($mail->temporaryPassword) >= 12
            && ! str_contains($password, 'yogesh')
            && ! str_contains($password, 'sapkal')
            && ! str_contains($password, 'cns021')
            && ! str_contains($password, '9876543210')
            && $password !== 'password';
    });
});

test('the raw token is never written to the database', function () {
    invService()->invite(invEmployee(), invHr());

    $token = null;
    Mail::assertSent(EmployeeInvitationMail::class, function ($mail) use (&$token) {
        $token = Str::afterLast($mail->acceptUrl, '/');

        return true;
    });

    $invitation = EmployeeInvitation::first();

    expect($invitation->token_hash)->not->toBe($token)
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and(EmployeeInvitation::where('token_hash', $token)->exists())->toBeFalse();
});

// ── 4. The user account ────────────────────────────────────────────────────

test('an existing user account is reused rather than duplicated', function () {
    $employee = invEmployee();
    $hr = invHr();
    $usersBefore = User::count();

    invService()->invite($employee, $hr);

    expect(User::count())->toBe($usersBefore)
        ->and($employee->fresh()->user_id)->toBe($employee->user_id);
});

test('repeated invites never create a second account', function () {
    $employee = invEmployee();
    $hr = invHr();
    $usersBefore = User::count();

    $service = invService();
    $service->invite($employee, $hr);
    $service->invite($employee->fresh(), $hr);
    $service->invite($employee->fresh(), $hr);

    expect(User::count())->toBe($usersBefore)
        ->and(Employee::where('user_id', $employee->user_id)->count())->toBe(1);
});

test('inviting does not change the employee role', function () {
    // A manager who is invited must not be quietly demoted to Employee, and an
    // employee must not be promoted by the act of being given a login.
    $employee = invEmployee(['role' => UserRole::Manager]);

    invService()->invite($employee, invHr());

    expect($employee->user->fresh()->role)->toBe(UserRole::Manager);
});

// ── 5. Tokens: single use, expiry, supersession ────────────────────────────

function invTokenFor(Employee $employee, User $actor): string
{
    invService()->invite($employee, $actor);

    $token = null;
    Mail::assertSent(EmployeeInvitationMail::class, function ($mail) use (&$token) {
        $token = Str::afterLast($mail->acceptUrl, '/');

        return true;
    });

    return $token;
}

test('accepting an invitation closes it', function () {
    $employee = invEmployee();
    $token = invTokenFor($employee, invHr());

    $accepted = invService()->accept($token);

    expect($accepted)->not->toBeNull()
        ->and($accepted->accepted_at)->not->toBeNull();
});

test('a token cannot be used twice', function () {
    $employee = invEmployee();
    $token = invTokenFor($employee, invHr());

    $service = invService();

    expect($service->accept($token))->not->toBeNull()
        ->and($service->accept($token))->toBeNull();
});

test('an expired token is refused', function () {
    config(['security.invitation_expiry_hours' => 48]);
    $employee = invEmployee();
    $token = invTokenFor($employee, invHr());

    $this->travel(49)->hours();

    expect(invService()->accept($token))->toBeNull();
});

test('an unknown token is refused', function () {
    expect(invService()->accept(Str::random(64)))->toBeNull();
});

test('resending invalidates the previous link', function () {
    $employee = invEmployee();
    $firstToken = invTokenFor($employee, invHr());

    Mail::fake();
    $secondToken = invTokenFor($employee->fresh(), invHr());

    $service = invService();

    expect($firstToken)->not->toBe($secondToken)
        ->and($service->accept($firstToken))->toBeNull()
        ->and($service->accept($secondToken))->not->toBeNull();
});

test('resending issues a fresh password', function () {
    $employee = invEmployee();

    $service = invService();
    $service->invite($employee, invHr());
    $firstHash = $employee->user->fresh()->password;

    $service->invite($employee->fresh(), invHr());

    expect($employee->user->fresh()->password)->not->toBe($firstHash);
});

test('resending counts up rather than starting over', function () {
    $employee = invEmployee();

    $service = invService();
    $service->invite($employee, invHr());
    $service->invite($employee->fresh(), invHr());
    $third = $service->invite($employee->fresh(), invHr());

    expect($third->resend_count)->toBe(2)
        ->and(EmployeeInvitation::where('employee_id', $employee->id)->whereNull('revoked_at')->whereNull('accepted_at')->count())->toBe(1);
});

test('resending is throttled', function () {
    config(['security.invitation_resend_per_hour' => 2]);
    $employee = invEmployee();
    $service = invService();

    $service->invite($employee, invHr());        // first send, not a resend
    $service->invite($employee->fresh(), invHr()); // resend 1
    $service->invite($employee->fresh(), invHr()); // resend 2

    expect(fn () => $service->invite($employee->fresh(), invHr()))
        ->toThrow(InvitationNotAllowed::class);
});

// ── 6. Signing in counts as accepting ──────────────────────────────────────

test('signing in with the emailed password accepts the invitation', function () {
    // An employee who never clicks the link would otherwise sit at Invited
    // forever and eventually read as Expired.
    $employee = invEmployee();
    invService()->invite($employee, invHr());

    $password = null;
    Mail::assertSent(EmployeeInvitationMail::class, function ($mail) use (&$password) {
        $password = $mail->temporaryPassword;

        return true;
    });

    $this->post('/login', ['email' => $employee->user->email, 'password' => $password]);

    expect(EmployeeInvitation::first()->fresh()->accepted_at)->not->toBeNull();
});

// ── 7. The accept route ────────────────────────────────────────────────────

test('the accept link sends the employee to the login page', function () {
    $employee = invEmployee();
    $token = invTokenFor($employee, invHr());

    $this->get(route('invite.accept', ['token' => $token]))
        ->assertRedirect(route('login'));

    expect(EmployeeInvitation::first()->accepted_at)->not->toBeNull();
});

test('a bad token says the same thing as an expired one', function () {
    // Different wording would tell somebody holding a guessed token whether it
    // ever existed.
    $employee = invEmployee();
    $token = invTokenFor($employee, invHr());
    invService()->accept($token);

    $used = $this->get(route('invite.accept', ['token' => $token]));
    $unknown = $this->get(route('invite.accept', ['token' => Str::random(64)]));

    expect($used->getSession()->get('status'))->toBe($unknown->getSession()->get('status'));
});

// ── 8. Audit ───────────────────────────────────────────────────────────────

test('an invitation is audit logged against the employee', function () {
    $employee = invEmployee();
    $hr = invHr();

    $invitation = invService()->invite($employee, $hr);

    $log = AuditLog::where('action', 'employee.invited')->first();

    expect($log)->not->toBeNull()
        ->and($log->auditable_id)->toBe($employee->id)
        ->and($log->new_values['sent_to'])->toBe($invitation->sent_to)
        ->and($log->new_values['invited_by'])->toBe($hr->id)
        ->and($log->new_values)->not->toHaveKey('password');
});

test('a resend is logged as a resend', function () {
    $employee = invEmployee();
    $service = invService();

    $service->invite($employee, invHr());
    $service->invite($employee->fresh(), invHr());

    expect(AuditLog::where('action', 'employee.invited')->count())->toBe(1)
        ->and(AuditLog::where('action', 'employee.invite_resent')->count())->toBe(1);
});

test('an acceptance is logged', function () {
    $employee = invEmployee();
    $token = invTokenFor($employee, invHr());

    invService()->accept($token);

    expect(AuditLog::where('action', 'employee.invite_accepted')
        ->where('auditable_id', $employee->id)->exists())->toBeTrue();
});

test('an expired attempt is logged', function () {
    config(['security.invitation_expiry_hours' => 48]);
    $employee = invEmployee();
    $token = invTokenFor($employee, invHr());

    $this->travel(49)->hours();
    invService()->accept($token);

    expect(AuditLog::where('action', 'employee.invite_expired')->exists())->toBeTrue();
});

// ── 9. Authorisation ───────────────────────────────────────────────────────

test('an employee cannot invite anybody', function () {
    $target = invEmployee();

    Livewire::actingAs(User::factory()->create(['role' => UserRole::Employee]))
        ->test(EmployeeIndex::class)
        ->call('inviteEmployee', $target->id)
        ->assertForbidden();

    expect(EmployeeInvitation::count())->toBe(0);
    Mail::assertNothingSent();
});

test('an employee cannot invite themselves', function () {
    $employee = invEmployee();

    Livewire::actingAs($employee->user)
        ->test(EmployeeIndex::class)
        ->call('inviteEmployee', $employee->id)
        ->assertForbidden();

    expect(EmployeeInvitation::count())->toBe(0);
});

test('HR can invite from the employee list', function () {
    $employee = invEmployee();

    Livewire::actingAs(invHr())
        ->test(EmployeeIndex::class)
        ->call('inviteEmployee', $employee->id);

    expect(EmployeeInvitation::where('employee_id', $employee->id)->exists())->toBeTrue();
    Mail::assertSent(EmployeeInvitationMail::class);
});

// ── 10. The list ───────────────────────────────────────────────────────────

test('the list never shows the temporary password', function () {
    $employee = invEmployee();
    invService()->invite($employee, invHr());

    $password = null;
    Mail::assertSent(EmployeeInvitationMail::class, function ($mail) use (&$password) {
        $password = $mail->temporaryPassword;

        return true;
    });

    Livewire::actingAs(invHr())->test(EmployeeIndex::class)
        ->assertOk()
        ->assertDontSee($password);
});

test('the list reports where each employee stands', function () {
    $notInvited = invEmployee();
    $invited = invEmployee();
    invService()->invite($invited, invHr());

    $service = invService();

    expect($service->statusFor($notInvited->fresh()))->toBe('not_invited')
        ->and($service->statusFor($invited->fresh()))->toBe('invited');
});

test('an employee who has signed in reads as active', function () {
    $employee = invEmployee(['last_login_at' => now()]);

    expect(invService()->statusFor($employee))->toBe('active');
});

test('an invitation nobody accepted reads as expired', function () {
    config(['security.invitation_expiry_hours' => 48]);
    $employee = invEmployee();
    invService()->invite($employee, invHr());

    $this->travel(49)->hours();

    expect(invService()->statusFor($employee->fresh()))->toBe('expired');
});

test('HR can filter the list down to who still cannot get in', function () {
    $notInvited = invEmployee();
    $invited = invEmployee();
    invService()->invite($invited, invHr());

    Livewire::actingAs(invHr())->test(EmployeeIndex::class)
        ->set('invitation', 'not_invited')
        ->assertViewHas('employees', fn ($e) => $e->pluck('id')->contains($notInvited->id)
            && ! $e->pluck('id')->contains($invited->id));
});

test('the invited filter finds exactly the ones waiting', function () {
    $notInvited = invEmployee();
    $invited = invEmployee();
    invService()->invite($invited, invHr());

    Livewire::actingAs(invHr())->test(EmployeeIndex::class)
        ->set('invitation', 'invited')
        ->assertViewHas('employees', fn ($e) => $e->pluck('id')->contains($invited->id)
            && ! $e->pluck('id')->contains($notInvited->id));
});

// ── 11. The profile screen ─────────────────────────────────────────────────

test('HR can invite from the employee profile', function () {
    // The profile is the screen HR is on when they finish checking an imported
    // record, so the action has to be reachable from there too.
    $employee = invEmployee();

    Livewire::actingAs(invHr())
        ->test(EmployeeProfile::class, ['employee' => $employee])
        ->call('inviteEmployee');

    expect(EmployeeInvitation::where('employee_id', $employee->id)->exists())->toBeTrue();
    Mail::assertSent(EmployeeInvitationMail::class);
});

test('the profile actually renders an Invite button', function () {
    // Calling the method proves the component works; it does not prove HR can
    // reach it. An earlier version of this file passed with no button on the
    // page at all.
    $employee = invEmployee();

    Livewire::actingAs(invHr())
        ->test(EmployeeProfile::class, ['employee' => $employee])
        ->assertOk()
        ->assertSee('Invite')
        ->assertSeeHtml('wire:click="inviteEmployee"');
});

test('the profile offers a resend once somebody is invited', function () {
    $employee = invEmployee();
    invService()->invite($employee, invHr());

    Livewire::actingAs(invHr())
        ->test(EmployeeProfile::class, ['employee' => $employee->fresh()])
        ->assertOk()
        ->assertSee('Resend invite')
        ->assertSeeHtml('wire:confirm');
});

test('the employee list actually renders an Invite button', function () {
    $employee = invEmployee();

    Livewire::actingAs(invHr())
        ->test(EmployeeIndex::class)
        ->assertOk()
        ->assertSee('Not Invited')
        ->assertSeeHtml('inviteEmployee('.$employee->id.')');
});

test('the profile reports the invitation state', function () {
    $employee = invEmployee();

    $component = Livewire::actingAs(invHr())
        ->test(EmployeeProfile::class, ['employee' => $employee]);

    expect($component->instance()->invitationState)->toBe('not_invited');

    $component->call('inviteEmployee');

    expect($component->instance()->invitationState)->toBe('invited');
});

test('the profile refuses an employee with no work email', function () {
    $employee = invEmployee(['email' => '']);

    Livewire::actingAs(invHr())
        ->test(EmployeeProfile::class, ['employee' => $employee])
        ->call('inviteEmployee');

    expect(EmployeeInvitation::count())->toBe(0);
    Mail::assertNothingSent();
});
