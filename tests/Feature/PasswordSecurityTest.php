<?php

use App\Enums\UserRole;
use App\Livewire\Employees\EmployeeCreate;
use App\Livewire\Employees\EmployeeEdit;
use App\Mail\WelcomeEmployeeMail;
use App\Models\Employee;
use App\Models\PasswordHistory;
use App\Models\User;
use App\Services\PasswordService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('PasswordService generates a strong password and records history', function () {
    $service = app(PasswordService::class);

    $password = $service->generate(16);
    expect(strlen($password))->toBe(16);
    expect($password)->toMatch('/[A-Z]/');
    expect($password)->toMatch('/[a-z]/');
    expect($password)->toMatch('/[0-9]/');

    $user = User::factory()->create();
    $plain = $service->resetPassword($user, null, null);

    expect(Hash::check($plain, $user->fresh()->password))->toBeTrue();
    expect(PasswordHistory::where('user_id', $user->id)->count())->toBe(1);
});

test('creating an employee generates a secure password, records history and reveals it once', function () {
    Mail::fake();
    Notification::fake();

    $hrAdmin = User::factory()->create(['role' => UserRole::HrAdmin]);

    $component = Livewire::actingAs($hrAdmin)->test(EmployeeCreate::class)
        ->set('name', 'Secure Sam')
        ->set('email', 'secure.sam@conexus-ns.com')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showCredentialsModal', true);

    $user = User::where('email', 'secure.sam@conexus-ns.com')->first();
    $plain = $component->get('generatedPassword');

    expect($plain)->not->toBe('');
    expect(Hash::check('Password@123', $user->password))->toBeFalse();       // no longer the hardcoded default
    expect(Hash::check($plain, $user->password))->toBeTrue();
    expect(PasswordHistory::where('user_id', $user->id)->count())->toBe(1);

    // The welcome email carries the real generated password, not the default.
    Mail::assertSent(WelcomeEmployeeMail::class, fn ($mail) => $mail->temporaryPassword === $plain);
});

test('setting a temporary password from the edit page is secure, recorded and revealed', function () {
    Mail::fake();

    $hrAdmin = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = Employee::factory()->create();

    $component = Livewire::actingAs($hrAdmin)
        ->test(EmployeeEdit::class, ['employee' => $employee])
        ->call('setTemporaryPassword')
        ->assertSet('showCredentialsModal', true);

    $plain = $component->get('generatedPassword');

    expect($plain)->not->toBe('');
    expect(str_starts_with($plain, 'Temp@'))->toBeFalse();                    // not the old weak scheme
    expect(Hash::check($plain, $employee->user->fresh()->password))->toBeTrue();
    expect(PasswordHistory::where('user_id', $employee->user->id)->count())->toBeGreaterThanOrEqual(1);
});
