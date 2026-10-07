<?php

use App\Enums\UserRole;
use App\Livewire\Settings\MyNotificationPreferences;
use App\Livewire\Settings\NotificationSettings;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\NotificationPreference;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Notifications\AttendanceRegularisationNotification;
use App\Notifications\MissingCheckoutNotification;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationRecipientPolicy;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Per-event recipient control (include / exclude roles, people,
 * departments, apply to all, mandatory), personal mutes, and sending each
 * reminder at most once per person.
 */
function nrcSetting(array $attributes = []): NotificationSetting
{
    return NotificationSetting::updateOrCreate(
        ['key' => AttendanceRegularisationNotification::class],
        $attributes + ['label' => 'Regularisation', 'group' => 'Attendance', 'mail_enabled' => true, 'database_enabled' => true, 'is_automatic' => true],
    );
}

function nrcUser(UserRole $role, ?Department $department = null): User
{
    $user = User::factory()->create(['role' => $role]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active', 'department_id' => $department?->id]);

    return $user->fresh();
}

function nrcInbox(User $user): int
{
    return $user->notifications()->where('type', AttendanceRegularisationNotification::class)->count();
}

function nrcNotification(): AttendanceRegularisationNotification
{
    return new AttendanceRegularisationNotification('Sam', '12 Oct 2026', 'pending');
}

// ── Who receives it ────────────────────────────────────────────────────────

test('nothing configured: the code\'s recipients, each once', function () {
    $a = nrcUser(UserRole::HrAdmin);

    $resolved = app(NotificationRecipientPolicy::class)->resolve('App\\Notifications\\Unconfigured', collect([$a, $a]));

    expect($resolved->pluck('id')->all())->toBe([$a->id]);
});

test('included roles and people are added; excluded roles removed; nobody twice', function () {
    $hr = nrcUser(UserRole::HrAdmin);
    $manager = nrcUser(UserRole::Manager);
    $director = nrcUser(UserRole::Director);
    $named = nrcUser(UserRole::Employee);
    nrcSetting(['include_roles' => ['manager', 'director'], 'exclude_roles' => ['director'], 'include_user_ids' => [$named->id]]);

    $ids = app(NotificationRecipientPolicy::class)->resolve(AttendanceRegularisationNotification::class, collect([$hr, $manager]))->pluck('id');

    expect($ids)->toContain($hr->id, $manager->id, $named->id)
        ->not->toContain($director->id)
        ->and($ids->count())->toBe($ids->unique()->count());
});

test('departments limit who is added; apply to all reaches everyone in them', function () {
    $sales = Department::factory()->create();
    $ops = Department::factory()->create();
    $inSales = nrcUser(UserRole::Manager, $sales);
    $inOps = nrcUser(UserRole::Manager, $ops);
    nrcSetting(['include_roles' => ['manager'], 'department_ids' => [$sales->id]]);

    $ids = app(NotificationRecipientPolicy::class)->resolve(AttendanceRegularisationNotification::class, collect())->pluck('id');
    expect($ids)->toContain($inSales->id)->not->toContain($inOps->id);

    nrcSetting(['include_roles' => null, 'department_ids' => null, 'apply_to_all' => true]);
    $ids = app(NotificationRecipientPolicy::class)->resolve(AttendanceRegularisationNotification::class, collect())->pluck('id');
    expect($ids)->toContain($inSales->id, $inOps->id);
});

// ── Enforced at delivery, for every sender ─────────────────────────────────

test('an excluded role never receives the event, whoever sends it', function () {
    nrcSetting(['exclude_roles' => ['finance']]);
    $finance = nrcUser(UserRole::Finance);
    $hr = nrcUser(UserRole::HrAdmin);

    $finance->notify(nrcNotification());
    $hr->notify(nrcNotification());

    expect(nrcInbox($finance))->toBe(0)
        ->and(nrcInbox($hr))->toBe(1);
});

test('a person can mute an optional event, but not a mandatory one', function () {
    $hr = nrcUser(UserRole::HrAdmin);
    nrcSetting();
    NotificationPreference::create(['user_id' => $hr->id, 'notification_key' => AttendanceRegularisationNotification::class, 'database_muted' => true]);

    $hr->notify(nrcNotification());
    expect(nrcInbox($hr))->toBe(0);

    nrcSetting(['is_mandatory' => true]);
    $hr->notify(nrcNotification());
    expect(nrcInbox($hr))->toBe(1);
});

test('a reminder goes to each person at most once per reason', function () {
    $hr = nrcUser(UserRole::HrAdmin);
    $dispatcher = app(NotificationDispatcher::class);

    expect($dispatcher->sendOnce($hr, nrcNotification(), 'reg:1'))->toBeTrue()
        ->and($dispatcher->sendOnce($hr, nrcNotification(), 'reg:1'))->toBeFalse()
        ->and($dispatcher->sendOnce($hr, nrcNotification(), 'reg:2'))->toBeTrue()
        ->and(nrcInbox($hr))->toBe(2);
});

test('the missing check-out job adds configured roles and never repeats', function () {
    $this->travelTo(Carbon::parse('2026-10-15 00:30:00'));   // the day is over: no shift end to wait for
    $hr = nrcUser(UserRole::HrAdmin);
    $employee = nrcUser(UserRole::Employee)->employee;
    NotificationSetting::updateOrCreate(['key' => MissingCheckoutNotification::class], [
        'label' => 'Missing checkout', 'group' => 'Attendance', 'mail_enabled' => false, 'database_enabled' => true, 'is_automatic' => true,
        'include_roles' => ['hr_admin'],
    ]);
    Attendance::create([
        'employee_id' => $employee->id, 'date' => '2026-10-14', 'check_in' => '2026-10-14 09:00:00',
        'status' => 'on_time', 'work_mode' => 'office',
    ]);

    $this->artisan('hrms:flag-missing-checkouts')->assertSuccessful();
    $this->artisan('hrms:flag-missing-checkouts')->assertSuccessful();

    expect($hr->notifications()->where('type', MissingCheckoutNotification::class)->count())->toBe(1)
        ->and($employee->user->notifications()->where('type', MissingCheckoutNotification::class)->count())->toBe(1);
});

// ── Screens ────────────────────────────────────────────────────────────────

test('HR sets recipients on the Notifications & Email page, audited', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $setting = nrcSetting();

    Livewire::actingAs($hr)->test(NotificationSettings::class)
        ->call('openRecipients', $setting->id)
        ->set('rcIncludeRoles', ['manager'])
        ->set('rcExcludeRoles', ['manager'])
        ->call('saveRecipients')
        ->assertHasErrors('rcExcludeRoles')
        ->set('rcExcludeRoles', ['finance'])
        ->set('rcMandatory', true)
        ->call('saveRecipients')
        ->assertHasNoErrors();

    $setting->refresh();
    expect($setting->include_roles)->toBe(['manager'])
        ->and($setting->exclude_roles)->toBe(['finance'])
        ->and($setting->is_mandatory)->toBeTrue()
        ->and(AuditLog::where('event', 'NOTIFICATION_SETTING_UPDATED')->exists())->toBeTrue();
});

test('people manage their own optional notifications; mandatory ones are locked', function () {
    $user = nrcUser(UserRole::Employee);
    $optional = nrcSetting();
    $mandatory = NotificationSetting::create(['key' => 'App\\Notifications\\PayslipReadyProbe', 'label' => 'Payslip', 'group' => 'Payroll', 'mail_enabled' => true, 'database_enabled' => true, 'is_mandatory' => true]);

    Livewire::actingAs($user)->test(MyNotificationPreferences::class)
        ->assertSee('Regularisation')
        ->call('toggle', $optional->id, 'mail')
        ->call('toggle', $mandatory->id, 'mail');

    expect(NotificationPreference::where('user_id', $user->id)->where('notification_key', $optional->key)->value('mail_muted'))->toBeTrue()
        ->and(NotificationPreference::where('user_id', $user->id)->where('notification_key', $mandatory->key)->exists())->toBeFalse();
});
