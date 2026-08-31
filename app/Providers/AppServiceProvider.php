<?php

namespace App\Providers;

use App\Models\AttendanceRegularisation;
use App\Models\DocumentAcknowledgement;
use App\Models\EmailLog;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\Incentive;
use App\Models\LeaveRequest;
use App\Models\MailSetting;
use App\Models\OtRequest;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\Permission;
use App\Models\Reimbursement;
use App\Models\User;
use App\Observers\AttendanceRegularisationObserver;
use App\Observers\DocumentAcknowledgementObserver;
use App\Observers\EmployeeObserver;
use App\Observers\EmployeeSalaryObserver;
use App\Observers\IncentiveObserver;
use App\Observers\LeaveRequestObserver;
use App\Observers\OtRequestObserver;
use App\Observers\PayrollObserver;
use App\Observers\PayslipObserver;
use App\Observers\ReimbursementObserver;
use App\Observers\UserObserver;
use App\Services\EmployeeImportService;
use App\Services\Notifications\NotificationDeliveryGate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Allow up to 120s for dashboard/payroll renders in non-production.
        // Skip in console: CLI ignores this ini setting normally, but explicitly
        // setting it here causes `php artisan test` to hit a cumulative 120s
        // limit across the whole run and crash with a fatal error.
        if (! app()->isProduction() && ! $this->app->runningInConsole()) {
            ini_set('max_execution_time', '120');
        }

        $this->configureDefaults();
        $this->registerObservers();
        $this->configureNotificationPriority();
        $this->configureNotificationGates();
        $this->configureMailLogging();

        if (app()->environment('production')) {
            URL::forceScheme('https');
        }

        Gate::define('manageFullSettings', function (User $user) {
            return $user->isSuperAdmin() || $user->isHrAdmin();
        });

        Gate::define('manage-settings', function (User $user) {
            return $user->canManageSettings();
        });

        // Dynamic, database-driven authorization: any ability matching a
        // `permissions.key` row resolves through the user's assigned role.
        Gate::before(function (User $user, string $ability) {
            if (Permission::query()->where('key', $ability)->exists()) {
                return $user->hasPermission($ability);
            }

            return null;
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Register Eloquent model observers for audit log compliance.
     */
    protected function registerObservers(): void
    {
        Employee::observe(EmployeeObserver::class);
        EmployeeSalary::observe(EmployeeSalaryObserver::class);
        User::observe(UserObserver::class);
        LeaveRequest::observe(LeaveRequestObserver::class);
        OtRequest::observe(OtRequestObserver::class);
        Payslip::observe(PayslipObserver::class);
        Payroll::observe(PayrollObserver::class);
        Incentive::observe(IncentiveObserver::class);
        Reimbursement::observe(ReimbursementObserver::class);
        AttendanceRegularisation::observe(AttendanceRegularisationObserver::class);
        DocumentAcknowledgement::observe(DocumentAcknowledgementObserver::class);
    }

    /**
     * Populate the notifications.priority column from each notification's
     * data['priority'] payload (high|normal|low) at write time, so the
     * column can be filtered and sorted without parsing the data JSON.
     */
    protected function configureNotificationPriority(): void
    {
        DatabaseNotification::creating(function (DatabaseNotification $notification): void {
            $data = $notification->data;
            $priority = (is_array($data) || $data instanceof \ArrayAccess)
                ? ($data['priority'] ?? null)
                : null;

            $notification->priority = in_array($priority, ['high', 'normal', 'low'], true)
                ? $priority
                : 'normal';
        });
    }

    /**
     * Admin-controlled delivery gate. A single listener governs every
     * Notification's channels via the notification_settings table.
     *
     * Fail-open by design: a missing/unknown setting always allows the send,
     * so introducing this never silently stops an existing notification.
     */
    protected function configureNotificationGates(): void
    {
        Event::listen(function (NotificationSending $event): ?bool {
            $key = $event->notification::class;
            $role = method_exists($event->notification, 'notificationRole')
                ? $event->notification->notificationRole()
                : null;
            $gate = app(NotificationDeliveryGate::class);

            if ($event->channel === 'mail') {
                $decision = $gate->mail($key, $role);

                if (! $decision->allowed) {
                    $this->logSkippedMail($key, $event->notifiable, $decision->reason);

                    return false;
                }

                return null;
            }

            if ($event->channel === 'database' && ! $gate->database($key, $role)->allowed) {
                return false;
            }

            return null;
        });
    }

    /**
     * Record a blocked send the same way a real one is recorded, so "Recent
     * Emails" shows why nothing went out instead of showing nothing at all.
     */
    private function logSkippedMail(string $key, mixed $notifiable, ?string $reason): void
    {
        try {
            $email = method_exists($notifiable, 'routeNotificationForMail')
                ? $notifiable->routeNotificationForMail()
                : ($notifiable->email ?? null);

            if (! is_string($email)) {
                return;
            }

            EmailLog::create([
                'notification_key' => $key,
                'to_email' => $email,
                'to_name' => $notifiable->name ?? null,
                'status' => 'skipped',
                'skip_reason' => $reason,
                'notifiable_type' => $notifiable instanceof Model ? $notifiable::class : null,
                'notifiable_id' => $notifiable instanceof Model ? $notifiable->getKey() : null,
            ]);
        } catch (\Throwable) {
            // Logging a skip must never itself block or break a send decision.
        }
    }

    /**
     * Record every outgoing email in email_logs and gate admin-controlled
     * Mailables (which bypass the notification system) by their stamped
     * X-Notification-Key header.
     */
    protected function configureMailLogging(): void
    {
        Event::listen(function (MessageSending $event): ?bool {
            $message = $event->message;
            $key = $this->mailHeader($message, 'X-Notification-Key');

            // Global master kill switch — cancels every outgoing message
            // (transactional notifications AND directly-sent Mailables).
            // Fail-open: a config/schema error never blocks all mail.
            if (! $this->globalMailEnabled()) {
                $this->logSkippedMessage($message, $key, 'outgoing_email_paused');

                return false;
            }

            // Employees imported without an email address are given an
            // unroutable stand-in. Nothing may be sent to one — it belongs to
            // no real mailbox — until HR fills in the genuine address.
            foreach (($message->getTo() ?: []) as $address) {
                if (str_ends_with(strtolower($address->getAddress()), '@'.EmployeeImportService::PLACEHOLDER_EMAIL_DOMAIN)) {
                    $this->logSkippedMessage($message, $key, 'recipient_email_missing');

                    return false;
                }
            }

            // The final, authoritative check — re-verified here rather than
            // trusted from whenever the send was first queued, so a setting
            // flipped after a job was dispatched is still honoured. Covers
            // directly-sent Mailables too: is_automatic previously had no
            // effect on them at all.
            if ($key !== null) {
                $role = $this->mailHeader($message, 'X-Notification-Role');
                $manual = $this->mailHeader($message, 'X-Notification-Manual') !== null;
                $decision = app(NotificationDeliveryGate::class)->mail($key, $role, $manual);

                if (! $decision->allowed) {
                    $this->logSkippedMessage($message, $key, $decision->reason);

                    return false;
                }
            }

            $ids = [];
            $subject = $message->getSubject();

            foreach (($message->getTo() ?: []) as $address) {
                $log = EmailLog::create([
                    'notification_key' => $key,
                    'to_email' => $address->getAddress(),
                    'to_name' => $address->getName() ?: null,
                    'subject' => $subject,
                    'status' => 'sending',
                ]);
                $ids[] = $log->id;
            }

            if ($ids !== []) {
                $message->getHeaders()->addTextHeader('X-Email-Log-Ids', implode(',', $ids));
            }

            return null;
        });

        Event::listen(function (MessageSent $event): void {
            $ids = $this->mailHeader($event->message, 'X-Email-Log-Ids');

            if ($ids === null) {
                return;
            }

            EmailLog::whereIn('id', explode(',', $ids))
                ->update(['status' => 'sent', 'sent_at' => now()]);
        });
    }

    /**
     * Record every recipient of a message the gate just cancelled, mirroring
     * the one-row-per-recipient shape of a real send.
     */
    private function logSkippedMessage(object $message, ?string $key, string $reason): void
    {
        try {
            foreach (($message->getTo() ?: []) as $address) {
                EmailLog::create([
                    'notification_key' => $key,
                    'to_email' => $address->getAddress(),
                    'to_name' => $address->getName() ?: null,
                    'subject' => $message->getSubject(),
                    'status' => 'skipped',
                    'skip_reason' => $reason,
                ]);
            }
        } catch (\Throwable) {
            // Logging a skip must never itself block or break a send decision.
        }
    }

    /**
     * Whether the global master mail switch is on. Fail-open: any error reading
     * the setting (e.g. missing table during migration) allows the send so a
     * config problem can never silently block every outgoing email.
     */
    private function globalMailEnabled(): bool
    {
        try {
            return MailSetting::mailEnabled();
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Read a single text header value off a Symfony email message, or null.
     */
    private function mailHeader(object $message, string $name): ?string
    {
        $headers = $message->getHeaders();

        if (! $headers->has($name)) {
            return null;
        }

        return $headers->get($name)?->getBodyAsString();
    }
}
