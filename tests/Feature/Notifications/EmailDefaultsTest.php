<?php

use App\Mail\PayslipMail;
use App\Models\NotificationRoleSetting;
use App\Models\NotificationSetting;
use App\Notifications\LeaveRequestNotification;
use App\Notifications\MissingCheckoutNotification;
use App\Services\Notifications\NotificationCatalog;
use App\Services\Notifications\NotificationDeliveryGate;
use Illuminate\Auth\Notifications\ResetPassword;

/**
 * D5 (8 Oct 2026): in-app is the default channel; email goes out by default
 * only for critical events — payslip, account created / login issued,
 * password reset. HR can still switch email on per event.
 */
test('with no settings row, a non-critical event does not email but still reaches the bell', function () {
    $gate = app(NotificationDeliveryGate::class);

    expect($gate->mail(LeaveRequestNotification::class, 'employee')->allowed)->toBeFalse()
        ->and($gate->mail(LeaveRequestNotification::class, 'employee')->reason)->toBe('email_off_by_default')
        ->and($gate->database(LeaveRequestNotification::class, 'employee')->allowed)->toBeTrue();
});

test('critical events email by default, and so does a send a person triggers', function () {
    $gate = app(NotificationDeliveryGate::class);

    expect($gate->mail(PayslipMail::class, 'employee')->allowed)->toBeTrue()
        ->and($gate->mail(ResetPassword::class, null)->allowed)->toBeTrue()
        ->and($gate->mail(LeaveRequestNotification::class, 'employee', manual: true)->allowed)->toBeTrue();
});

test('HR switching email on for an event is honoured', function () {
    NotificationSetting::create(['key' => MissingCheckoutNotification::class, 'label' => 'Missing checkout', 'group' => 'Attendance',
        'mail_enabled' => true, 'database_enabled' => true, 'is_automatic' => true, 'is_system' => true]);
    NotificationSetting::flushCache();

    expect(app(NotificationDeliveryGate::class)->mail(MissingCheckoutNotification::class, 'employee')->allowed)->toBeTrue();
});

test('newly synced events start with email off unless they are critical', function () {
    app(NotificationCatalog::class)->sync();

    expect(NotificationSetting::where('key', LeaveRequestNotification::class)->value('mail_enabled'))->toBeFalse()
        ->and(NotificationSetting::where('key', PayslipMail::class)->value('mail_enabled'))->toBeTrue()
        ->and(NotificationSetting::where('key', LeaveRequestNotification::class)->value('database_enabled'))->toBeTrue();
});

test('the defaults command previews, then switches email off on non-critical events only', function () {
    $leave = NotificationSetting::create(['key' => LeaveRequestNotification::class, 'label' => 'Leave request', 'group' => 'Leave',
        'mail_enabled' => true, 'database_enabled' => true, 'is_automatic' => true, 'is_system' => true]);
    NotificationRoleSetting::create(['notification_setting_id' => $leave->id, 'role' => 'employee', 'mail_enabled' => true, 'database_enabled' => true, 'is_automatic' => true]);
    $payslip = NotificationSetting::create(['key' => PayslipMail::class, 'label' => 'Payslip Email', 'group' => 'Payroll & Finance',
        'mail_enabled' => true, 'database_enabled' => true, 'is_automatic' => true, 'is_system' => true]);

    $this->artisan('notifications:apply-email-defaults')->expectsOutputToContain('would have email switched off')->assertSuccessful();
    expect($leave->fresh()->mail_enabled)->toBeTrue();   // preview changes nothing

    $this->artisan('notifications:apply-email-defaults', ['--apply' => true])->assertSuccessful();

    expect($leave->fresh()->mail_enabled)->toBeFalse()
        ->and(NotificationRoleSetting::where('notification_setting_id', $leave->id)->value('mail_enabled'))->toBeFalse()
        ->and($leave->fresh()->database_enabled)->toBeTrue()
        ->and($payslip->fresh()->mail_enabled)->toBeTrue();
});
