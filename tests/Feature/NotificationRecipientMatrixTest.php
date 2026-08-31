<?php

use App\Console\Commands\SendOnboardingReminders;
use App\Enums\UserRole;
use App\Mail\EmployeeInvitationMail;
use App\Mail\IncrementLetterMail;
use App\Mail\PayslipMail;
use App\Mail\WelcomeEmployeeMail;
use App\Models\EmailLog;
use App\Models\Employee;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Notifications\ExcessBreakNotification;
use App\Services\Notifications\NotificationCatalog;
use App\Services\Notifications\NotificationDeliveryGate;
use App\Services\Notifications\NotificationRecipients;
use Illuminate\Support\Facades\Mail;

/**
 * Who each event reaches, and that the settings screen only ever offers
 * toggles for something that can actually happen.
 *
 * Recipient resolution used to be an ad-hoc User::whereIn('role', [...]) at
 * ~20 call sites. These cover the named resolvers that replaced them, the
 * two fallbacks that were quietly over-broad, and the retired events.
 */
function nrmUser(UserRole $role): User
{
    return User::factory()->create(['role' => $role]);
}

// ── The named resolvers ────────────────────────────────────────────────────

test('the HR queue is HR admins and super admins, and nobody else', function () {
    $hr = nrmUser(UserRole::HrAdmin);
    $super = nrmUser(UserRole::SuperAdmin);
    nrmUser(UserRole::Manager);
    nrmUser(UserRole::Employee);
    nrmUser(UserRole::Finance);
    nrmUser(UserRole::Director);

    $queue = app(NotificationRecipients::class)->hrQueue();

    expect($queue->pluck('id')->sort()->values()->all())
        ->toBe(collect([$hr->id, $super->id])->sort()->values()->all());
});

test('finance approvers are finance and super admins, not HR', function () {
    $finance = nrmUser(UserRole::Finance);
    $super = nrmUser(UserRole::SuperAdmin);
    nrmUser(UserRole::HrAdmin);

    expect(app(NotificationRecipients::class)->financeApprovers()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$finance->id, $super->id])->sort()->values()->all());
});

test('directors are directors and super admins', function () {
    $director = nrmUser(UserRole::Director);
    $super = nrmUser(UserRole::SuperAdmin);
    nrmUser(UserRole::Manager);

    expect(app(NotificationRecipients::class)->directors()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$director->id, $super->id])->sort()->values()->all());
});

test('payroll approvers are finance, directors and super admins', function () {
    $finance = nrmUser(UserRole::Finance);
    $director = nrmUser(UserRole::Director);
    $super = nrmUser(UserRole::SuperAdmin);
    nrmUser(UserRole::HrAdmin);

    expect(app(NotificationRecipients::class)->payrollApprovers()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$finance->id, $director->id, $super->id])->sort()->values()->all());
});

test('no manager is ever included in a role broadcast just for being a manager', function () {
    // The OT and WFH fallbacks used to add every manager in the company to a
    // request that had nothing to do with them.
    nrmUser(UserRole::Manager);
    nrmUser(UserRole::Manager);
    $hr = nrmUser(UserRole::HrAdmin);

    $recipients = app(NotificationRecipients::class);

    expect($recipients->hrQueue()->pluck('id')->all())->toBe([$hr->id])
        ->and($recipients->financeApprovers())->toBeEmpty()
        ->and($recipients->directors())->toBeEmpty();
});

// ── Retired events must not be configurable ────────────────────────────────

test('retired notification classes are absent from the catalog', function () {
    $keys = collect(app(NotificationCatalog::class)->all())->pluck('key');

    expect($keys)->not->toContain('App\Notifications\AssetReturnPendingNotification')
        ->and($keys)->not->toContain('App\Notifications\ExitDocumentsReadyNotification')
        ->and($keys)->not->toContain('App\Notifications\FinanceClearancePendingNotification')
        ->and($keys)->not->toContain('App\Notifications\LeaveDynamicApproverNotification')
        ->and($keys)->not->toContain('App\Notifications\OTApprovalRequiredNotification')
        ->and($keys)->not->toContain('App\Notifications\OTRejectedNotification')
        ->and($keys)->not->toContain('App\Notifications\OTApprovedNotification');
});

test('syncing removes a settings row for an event that can never fire', function () {
    NotificationSetting::create([
        'key' => 'App\Notifications\OTApprovedNotification',
        'label' => 'O T Approved',
        'group' => 'Overtime',
        'mail_enabled' => true,
        'database_enabled' => true,
        'is_automatic' => true,
    ]);

    app(NotificationCatalog::class)->sync();

    expect(NotificationSetting::where('key', 'App\Notifications\OTApprovedNotification')->exists())->toBeFalse();
});

// ── Every configurable role row is one the code can actually produce ───────

test('every role offered in the settings UI belongs to a known role vocabulary', function () {
    $catalog = app(NotificationCatalog::class);
    $allowed = ['employee', 'manager', 'hr_admin', 'director', 'finance', 'approver'];

    foreach ($catalog->all() as $entry) {
        foreach (array_keys($catalog->rolesFor($entry['key'])) as $role) {
            expect($allowed)->toContain($role);
        }
    }
});

test('sync creates a role row for every role an event declares', function () {
    app(NotificationCatalog::class)->sync();
    $catalog = app(NotificationCatalog::class);

    foreach ($catalog->all() as $entry) {
        $declared = array_keys($catalog->rolesFor($entry['key']));

        if ($declared === []) {
            continue;
        }

        $setting = NotificationSetting::where('key', $entry['key'])->first();
        $stored = $setting->roleSettings->pluck('role')->sort()->values()->all();

        sort($declared);
        expect($stored)->toBe($declared);
    }
});

test('a second sync neither duplicates role rows nor resets an admin choice', function () {
    $catalog = app(NotificationCatalog::class);
    $catalog->sync();

    $setting = NotificationSetting::where('key', ExcessBreakNotification::class)->firstOrFail();
    $role = $setting->roleSettings()->where('role', 'manager')->firstOrFail();
    $role->update(['mail_enabled' => false, 'custom_subject' => 'Kept']);

    $before = $setting->roleSettings()->count();
    $catalog->sync();

    expect($setting->fresh()->roleSettings()->count())->toBe($before)
        ->and($role->fresh()->mail_enabled)->toBeFalse()
        ->and($role->fresh()->custom_subject)->toBe('Kept');
});

// ── The onboarding-task recipient bug ──────────────────────────────────────

test('a finance-owned onboarding task reaches finance, not HR', function () {
    $finance = nrmUser(UserRole::Finance);
    $hr = nrmUser(UserRole::HrAdmin);
    $employee = Employee::factory()->create();

    $command = new SendOnboardingReminders;
    $resolve = (new ReflectionClass($command))->getMethod('resolveRecipients');
    $resolve->setAccessible(true);

    $recipients = $resolve->invoke($command, 'finance', $employee);

    expect($recipients->pluck('id')->all())->toContain($finance->id)
        ->and($recipients->pluck('id')->all())->not->toContain($hr->id);
});

test('an HR-owned onboarding task still reaches the whole HR queue', function () {
    $hr = nrmUser(UserRole::HrAdmin);
    $super = nrmUser(UserRole::SuperAdmin);
    $employee = Employee::factory()->create();

    $command = new SendOnboardingReminders;
    $resolve = (new ReflectionClass($command))->getMethod('resolveRecipients');
    $resolve->setAccessible(true);

    expect($resolve->invoke($command, 'hr', $employee)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$hr->id, $super->id])->sort()->values()->all());
});

// ── No outgoing mail path escapes the gate ─────────────────────────────────

test('every Mailable in app/Mail stamps a notification key', function () {
    // The gate identifies a message by its X-Notification-Key header. A
    // Mailable without one is invisible to the settings screen and the log.
    $unstamped = [];

    foreach (glob(app_path('Mail/*.php')) as $file) {
        if (! str_contains(file_get_contents($file), 'X-Notification-Key')) {
            $unstamped[] = basename($file);
        }
    }

    expect($unstamped)->toBe([]);
});

test('every admin-controllable Mailable appears in the catalog', function () {
    $keys = collect(app(NotificationCatalog::class)->all())->pluck('key');

    expect($keys)->toContain(PayslipMail::class)
        ->and($keys)->toContain(IncrementLetterMail::class)
        ->and($keys)->toContain(WelcomeEmployeeMail::class)
        ->and($keys)->toContain(EmployeeInvitationMail::class);
});

test('a raw ad-hoc HR email is logged against a traceable key', function () {
    Mail::raw('hello', function ($msg) {
        $msg->to('someone@example.com')->subject('Ad hoc');
        $msg->getHeaders()->addTextHeader('X-Notification-Key', 'employee.direct_email');
        $msg->getHeaders()->addTextHeader('X-Notification-Manual', '1');
    });

    expect(EmailLog::where('notification_key', 'employee.direct_email')->exists())->toBeTrue();
});

test('no live notification class builds its own mail message outside the shared channel', function () {
    // A hand-rolled toMail() reaches the transport without the notification
    // key, so the final gate check cannot identify it, the email log cannot
    // attribute it, and any per-role template configured for it is ignored.
    $offenders = [];
    $catalogKeys = collect(app(NotificationCatalog::class)->all())->pluck('key')->all();

    foreach ($catalogKeys as $class) {
        if (! str_starts_with($class, 'App'.chr(92).'Notifications')) {
            continue; // Mailables build their own content by design
        }

        $file = app_path(str_replace(chr(92), '/', substr($class, strlen('App'.chr(92)))).'.php');

        if (! is_file($file)) {
            continue;
        }

        $contents = file_get_contents($file);

        if (str_contains($contents, 'public function toMail') && ! str_contains($contents, 'SendsMailChannel')) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([]);
});

test('every notification setting in the catalog has a real dispatch path', function () {
    // The inverse of the retired-events check: a configurable row must
    // correspond to something the application can actually send.
    $undispatched = [];

    foreach (app(NotificationCatalog::class)->all() as $entry) {
        $key = $entry['key'];

        if (! str_starts_with($key, 'App'.chr(92).'Notifications')) {
            continue; // Mailables are dispatched via Mail::to(), covered separately
        }

        $short = class_basename($key);
        $found = false;

        foreach (['app/Services', 'app/Livewire', 'app/Console', 'app/Http', 'app/Observers'] as $dir) {
            $path = base_path($dir);
            if (! is_dir($path)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
            foreach ($it as $f) {
                if ($f->getExtension() !== 'php' || str_contains($f->getPathname(), 'PrepareUatEmployeeData')) {
                    continue;
                }
                if (str_contains(file_get_contents($f->getPathname()), "new {$short}(")) {
                    $found = true;
                    break 2;
                }
            }
        }

        if (! $found) {
            $undispatched[] = $short;
        }
    }

    expect($undispatched)->toBe([]);
});

test('syncing an event an admin disabled does not start sending again', function () {
    // A role row overrides the event's own setting. Seeding one as enabled
    // over a disabled event would resume email while the screen still showed
    // the event as off.
    $catalog = app(NotificationCatalog::class);
    $catalog->sync();

    $setting = NotificationSetting::where('key', ExcessBreakNotification::class)->firstOrFail();
    $setting->roleSettings()->delete();
    $setting->update(['mail_enabled' => false, 'database_enabled' => false, 'is_automatic' => false]);

    $catalog->sync();

    $gate = app(NotificationDeliveryGate::class);

    foreach ($setting->fresh()->roleSettings as $role) {
        expect($role->mail_enabled)->toBeFalse()
            ->and($role->database_enabled)->toBeFalse()
            ->and($role->is_automatic)->toBeFalse();
        expect($gate->mail(ExcessBreakNotification::class, $role->role)->allowed)->toBeFalse();
        expect($gate->database(ExcessBreakNotification::class, $role->role)->allowed)->toBeFalse();
    }
});

test('syncing an enabled event leaves it enabled', function () {
    $catalog = app(NotificationCatalog::class);
    $catalog->sync();

    $setting = NotificationSetting::where('key', ExcessBreakNotification::class)->firstOrFail();
    $setting->roleSettings()->delete();
    $setting->update(['mail_enabled' => true, 'database_enabled' => true, 'is_automatic' => true]);

    $catalog->sync();

    foreach ($setting->fresh()->roleSettings as $role) {
        expect($role->mail_enabled)->toBeTrue();
    }
});
