<?php

use App\Enums\UserRole;
use App\Livewire\Settings\MyNotificationPreferences;
use App\Livewire\TimeOff\MyTimeOff;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Services\LeaveService;
use App\Support\DateDisplay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

function hardeningUser(UserRole $role = UserRole::Employee): User
{
    $user = User::factory()->create(['role' => $role]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    return $user->fresh();
}

// ── Settings navigation (SC10 / SC12) ────────────────────────────────────────

test('an employee settings nav offers only pages they can open — no Holidays, Teams or admin items', function () {
    $this->actingAs(hardeningUser())
        ->get(route('settings.preferences'))
        ->assertOk()
        ->assertSee('data-settings-link="profile.edit"', false)
        ->assertSee('data-settings-link="settings.my-notifications"', false)
        ->assertSee('data-settings-link="settings.preferences"', false)
        ->assertDontSee('data-settings-link="settings.holidays"', false)
        ->assertDontSee('data-settings-link="teams.index"', false)
        ->assertDontSee('data-settings-link="settings.general"', false)
        ->assertDontSee('data-settings-link="settings.roles"', false)
        ->assertSee('data-settings-nav="mobile"', false);
});

test('Holidays settings stays forbidden to an employee and is linked for HR', function () {
    $this->actingAs(hardeningUser())->get(route('settings.holidays'))->assertForbidden();

    $hr = hardeningUser(UserRole::HrAdmin);
    $this->actingAs($hr)->get(route('settings.preferences'))
        ->assertSee('data-settings-link="settings.holidays"', false);
    $this->actingAs($hr)->get(route('settings.holidays'))->assertOk();
});

test('My notifications keeps the settings navigation and an aligned, labelled toggle grid', function () {
    NotificationSetting::create(['key' => 'App\\Notifications\\UiHardeningProbe', 'label' => 'Leave decision', 'group' => 'Leave', 'mail_enabled' => true, 'database_enabled' => true, 'is_mandatory' => false]);
    NotificationSetting::create(['key' => 'App\\Notifications\\UiHardeningRequired', 'label' => 'Payslip ready', 'group' => 'Payroll', 'mail_enabled' => true, 'database_enabled' => true, 'is_mandatory' => true]);

    Livewire::actingAs(hardeningUser())->test(MyNotificationPreferences::class)
        ->assertSeeHtml('data-settings-nav="desktop"')
        ->assertSeeHtml('sm:grid-cols-[minmax(0,1fr)_6rem_6rem]')
        ->assertSeeHtml('role="switch"')
        ->assertSeeHtml('aria-checked="true"')
        ->assertSee('Required')
        ->assertDontSeeHtml('min-w-[480px]');
});

// ── Date / time preferences (SC11) ───────────────────────────────────────────

test('dates and times follow the reader preference everywhere through one formatter', function () {
    $user = hardeningUser();
    $user->update(['date_format' => 'd/m/Y', 'time_format' => 'h:i A', 'timezone' => 'Europe/London']);
    $this->actingAs($user);

    $date = CarbonImmutable::parse('2026-10-05 14:30:00', 'Asia/Kolkata');

    expect(app(DateDisplay::class)->date($date))->toBe('05/10/2026')
        ->and($date->userDate())->toBe('05/10/2026')
        ->and($date->userTime())->toBe('02:30 PM')
        ->and($date->userShortDate())->toBe('05/10')
        // Instants move to the reader's timezone; IST 14:30 is 10:00 in London (BST).
        ->and($date->userDateTime())->toBe('05/10/2026, 10:00 AM')
        // Date-only values never shift a day.
        ->and(app(DateDisplay::class)->date('2026-10-05'))->toBe('05/10/2026')
        ->and(app(DateDisplay::class)->date(null))->toBe('—');

    expect(Blade::render('@userDate($d) @userTime($d)', ['d' => $date]))->toBe('05/10/2026 02:30 PM');

    $user->update(['date_format' => 'Y-m-d', 'time_format' => 'H:i']);
    expect($date->userDate())->toBe('2026-10-05')->and($date->userTime())->toBe('14:30');
});

test('an unknown stored format falls back safely instead of printing garbage', function () {
    $user = hardeningUser();
    $user->forceFill(['date_format' => 'nonsense', 'time_format' => 'bad', 'timezone' => 'Mars/Base'])->save();
    $this->actingAs($user);

    expect(CarbonImmutable::parse('2026-10-05 09:00')->userDate())->toBe('05 Oct 2026')
        ->and(app(DateDisplay::class)->timezone())->toBe(config('app.timezone'));
});

test('saving preferences persists and immediately changes what pages show', function () {
    $user = hardeningUser();

    Livewire::actingAs($user)->test('pages::settings.preferences')
        ->set('date_format', 'm/d/Y')
        ->set('time_format', 'h:i A')
        ->set('timezone', 'Asia/Dubai')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-preferences-preview');

    $user->refresh();
    expect($user->only(['date_format', 'time_format', 'timezone']))
        ->toBe(['date_format' => 'm/d/Y', 'time_format' => 'h:i A', 'timezone' => 'Asia/Dubai']);

    $this->actingAs($user);
    expect(CarbonImmutable::parse('2026-10-05')->userDate())->toBe('10/05/2026');

    Livewire::actingAs($user)->test('pages::settings.preferences')
        ->set('date_format', 'l jS')
        ->call('save')
        ->assertHasErrors('date_format');
});

// ── Leave forms (SC16 / SC15 / SC17) ─────────────────────────────────────────

test('the leave request rejects an end date before the start date with the agreed message', function () {
    $user = hardeningUser();

    Livewire::actingAs($user)->test(MyTimeOff::class)
        ->set('showRequestModal', true)
        ->set('start_date', now()->addDays(10)->toDateString())
        ->set('end_date', now()->addDays(5)->toDateString())
        ->call('submitRequest')
        ->assertHasErrors(['end_date' => 'after_or_equal'])
        ->assertSee('The end date must be on or after the start date.');
});

test('the leave request form uses the shared date range and required markers', function () {
    Livewire::actingAs(hardeningUser())->test(MyTimeOff::class)
        ->set('showRequestModal', true)
        ->assertSeeHtml('data-date-range')
        ->assertSeeHtml('data-app-modal')
        ->assertSeeHtml('data-required-marker')
        ->assertSee('(Optional)');
});

test('a leave notification opens the review modal for an approver who covers the employee, and only for them', function () {
    Notification::fake();
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $employee = Employee::factory()->create(['manager_id' => $manager->id]);
    $type = LeaveType::create(['name' => 'UI Probe Leave', 'code' => 'UIP'.rand(100, 999), 'is_paid' => true, 'color' => '#1DB77A', 'category' => 'annual', 'allow_paid_request' => true, 'allow_unpaid_request' => true]);
    $request = app(LeaveService::class)->submitRequest($employee, $type, '2026-11-18', '2026-11-18', 'Family event', requestedLeaveStatus: 'unpaid');

    $this->actingAs($manager)
        ->get(route('time-off.team', ['selectedRequestId' => $request->id]))
        ->assertOk()
        ->assertSee('Review Leave Request')
        ->assertSee('data-app-modal', false)
        ->assertSee('data-leave-review', false)
        ->assertSee('data-team-leave-filters', false);

    $stranger = User::factory()->create(['role' => UserRole::Manager]);
    $this->actingAs($stranger)
        ->get(route('time-off.team', ['selectedRequestId' => $request->id]))
        ->assertForbidden();
});

test('an employee cannot open Team Leave at all', function () {
    $this->actingAs(hardeningUser())->get(route('time-off.team'))->assertForbidden();
});
