<?php

use App\Enums\UserRole;
use App\Livewire\Settings\NotificationSettings;
use App\Models\EmailLog;
use App\Models\NotificationSetting;
use App\Models\Role;
use App\Models\User;
use App\Notifications\Concerns\SendsMailChannel;
use App\Services\Notifications\NotificationCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Notifications\Notification;
use Livewire\Livewire;

/**
 * Lightweight probe notification used to exercise the delivery gate without
 * pulling in real domain models.
 */
class GateProbeNotification extends Notification
{
    use SendsMailChannel;

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => 'Probe', 'body' => 'Probe body', 'action' => 'Open', 'url' => '/'];
    }
}

function makeSettingsAdmin(): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $role = Role::where('slug', 'super_admin')->firstOrFail();

    return User::factory()->create(['role' => UserRole::SuperAdmin, 'role_id' => $role->id]);
}

test('fail-open: with no setting row, both channels deliver', function () {
    $user = User::factory()->create();

    $user->notify(new GateProbeNotification);

    expect($user->notifications()->count())->toBe(1);
    expect(EmailLog::where('notification_key', GateProbeNotification::class)->count())->toBeGreaterThanOrEqual(1);
});

test('disabling mail keeps the in-app notification but suppresses the email', function () {
    $user = User::factory()->create();

    NotificationSetting::create([
        'key' => GateProbeNotification::class,
        'label' => 'Probe',
        'group' => 'General',
        'mail_enabled' => false,
        'database_enabled' => true,
        'is_automatic' => true,
    ]);

    $user->notify(new GateProbeNotification);

    expect($user->notifications()->count())->toBe(1);
    expect(EmailLog::where('notification_key', GateProbeNotification::class)->count())->toBe(0);
});

test('disabling the database channel suppresses the in-app notification only', function () {
    $user = User::factory()->create();

    NotificationSetting::create([
        'key' => GateProbeNotification::class,
        'label' => 'Probe',
        'group' => 'General',
        'mail_enabled' => true,
        'database_enabled' => false,
        'is_automatic' => true,
    ]);

    $user->notify(new GateProbeNotification);

    expect($user->notifications()->count())->toBe(0);
    expect(EmailLog::where('notification_key', GateProbeNotification::class)->count())->toBeGreaterThanOrEqual(1);
});

test('is_automatic=false suppresses automatic email', function () {
    $user = User::factory()->create();

    NotificationSetting::create([
        'key' => GateProbeNotification::class,
        'label' => 'Probe',
        'group' => 'General',
        'mail_enabled' => true,
        'database_enabled' => true,
        'is_automatic' => false,
    ]);

    $user->notify(new GateProbeNotification);

    expect($user->notifications()->count())->toBe(1);
    expect(EmailLog::where('notification_key', GateProbeNotification::class)->count())->toBe(0);
});

test('custom subject and body override the mail message', function () {
    $user = User::factory()->create();

    NotificationSetting::create([
        'key' => GateProbeNotification::class,
        'label' => 'Probe',
        'group' => 'General',
        'custom_subject' => 'Custom Subject',
        'custom_body' => 'Custom Body',
    ]);

    $mail = (new GateProbeNotification)->toMail($user);

    expect($mail->subject)->toBe('Custom Subject');
    expect($mail->introLines)->toContain('Custom Body');
});

test('catalog sync is idempotent and preserves admin toggles', function () {
    $catalog = app(NotificationCatalog::class);

    $catalog->sync();
    $count = NotificationSetting::count();
    expect($count)->toBeGreaterThan(0);

    $first = NotificationSetting::first();
    $first->update(['mail_enabled' => false]);

    $catalog->sync();

    expect(NotificationSetting::count())->toBe($count);
    expect($first->fresh()->mail_enabled)->toBeFalse();
});

test('settings page renders for an admin and an admin can toggle a channel', function () {
    $admin = makeSettingsAdmin();

    $setting = NotificationSetting::create([
        'key' => GateProbeNotification::class,
        'label' => 'Probe',
        'group' => 'General',
        'mail_enabled' => true,
        'database_enabled' => true,
        'is_automatic' => true,
    ]);

    Livewire::actingAs($admin)->test(NotificationSettings::class)
        ->assertSee('SMTP Status')
        ->assertSee('Probe')
        ->call('toggle', $setting->id, 'mail_enabled');

    expect($setting->fresh()->mail_enabled)->toBeFalse();
});

test('a non-admin cannot open the notification settings page', function () {
    $employee = User::factory()->create(['role' => UserRole::Employee]);

    Livewire::actingAs($employee)->test(NotificationSettings::class)->assertForbidden();
});
