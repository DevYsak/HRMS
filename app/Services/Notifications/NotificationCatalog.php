<?php

namespace App\Services\Notifications;

use App\Mail\EmployeeInvitationMail;
use App\Mail\IncrementLetterMail;
use App\Mail\PayslipMail;
use App\Mail\WelcomeEmployeeMail;
use App\Models\NotificationRoleSetting;
use App\Models\NotificationSetting;
use App\Notifications\ExcessBreakNotification;
use App\Notifications\MissingCheckoutNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Discovers every notifiable event in the app (all Notification classes plus a
 * curated set of admin-controlled Mailables) and keeps the notification_settings
 * table in sync with them.
 *
 * Sync is additive and non-destructive: new events get a row with sensible
 * defaults, existing rows keep their admin-chosen toggles/overrides — only the
 * presentational metadata (label/group/description/sort_order) is refreshed.
 */
class NotificationCatalog
{
    /**
     * Mailables that are sent directly (not through the notification system) but
     * should still be admin-controllable. Keyed by FQCN.
     *
     * @var array<class-string, array{label:string, group:string, description:?string}>
     */
    private const MAILABLES = [
        EmployeeInvitationMail::class => [
            'label' => 'Employee Invitation',
            'group' => 'Onboarding',
            'description' => 'Login invitation and temporary password sent when HR invites an employee.',
        ],
        WelcomeEmployeeMail::class => [
            'label' => 'Welcome Email',
            'group' => 'Onboarding',
            'description' => 'Account credentials sent to a newly created employee.',
        ],
        PayslipMail::class => [
            'label' => 'Payslip Email',
            'group' => 'Payroll & Finance',
            'description' => 'Payslip PDF emailed to an employee.',
        ],
        IncrementLetterMail::class => [
            'label' => 'Increment Letter',
            'group' => 'Payroll & Finance',
            'description' => 'Salary increment letter emailed to an employee when their increment is applied.',
        ],
    ];

    /**
     * Notification classes that exist but can never fire, so they must not
     * appear as configurable rows — a toggle for an event that cannot happen
     * is a promise the system does not keep.
     *
     * Each is excluded from the catalog and its settings row is removed on
     * sync. Wiring any of them up is a matter of dispatching it somewhere and
     * deleting its line here.
     *
     * @var array<class-string, string> FQCN => why it is retired
     */
    private const RETIRED = [
        'App\Notifications\AssetReturnPendingNotification' => 'No dispatch site. AssetAssignmentService never references it.',
        'App\Notifications\ExitDocumentsReadyNotification' => 'No dispatch site. OffboardingManager handles exit docs without it.',
        'App\Notifications\FinanceClearancePendingNotification' => 'No dispatch site anywhere in app/.',
        'App\Notifications\LeaveDynamicApproverNotification' => 'No dispatch site. The approver chain sends LeaveRequestNotification instead.',
        'App\Notifications\OTApprovalRequiredNotification' => 'No dispatch site. OtRequestNotification covers approver alerts.',
        'App\Notifications\OTRejectedNotification' => 'No dispatch site. OtRequestNotification carries the reject decision.',
        'App\Notifications\OTApprovedNotification' => 'Dispatched only by the uat:prepare-employee data-prep command; the real approve flow sends OtRequestNotification.',
        'App\Notifications\OffboardingStartedNotification' => 'No dispatch site. OffboardingManager starts offboarding without announcing it.',
        'App\Notifications\Teams\TeamInvitation' => 'No dispatch site. The team invitation flow does not send it.',
    ];

    /**
     * Events that notify more than one distinct role, and are configured
     * with per-role rows on sync. Every other event stays single-role —
     * governed only by its own NotificationSetting row, exactly as before
     * this table existed.
     *
     * Keyed by FQCN, value is role => human label.
     *
     * @var array<class-string, array<string, string>>
     */
    private const ROLES = [
        // Attendance
        ExcessBreakNotification::class => ['employee' => 'Employee', 'manager' => 'Manager'],
        MissingCheckoutNotification::class => ['employee' => 'Employee', 'manager' => 'Manager'],
        'App\Notifications\AttendanceRegularisationNotification' => [
            'employee' => 'Employee', 'manager' => 'Manager', 'hr_admin' => 'HR Admin',
        ],
        // Leave
        'App\Notifications\LeaveRequestNotification' => [
            'employee' => 'Employee', 'manager' => 'Manager', 'hr_admin' => 'HR Admin', 'approver' => 'Approver',
        ],
        'App\Notifications\LeaveEncashmentNotification' => [
            'employee' => 'Employee', 'hr_admin' => 'HR Admin', 'finance' => 'Finance',
        ],
        // Overtime / WFH / holiday work
        'App\Notifications\OtRequestNotification' => [
            'employee' => 'Employee', 'manager' => 'Manager', 'hr_admin' => 'HR Admin', 'approver' => 'Approver',
        ],
        'App\Notifications\WfhRequestNotification' => [
            'employee' => 'Employee', 'manager' => 'Manager', 'hr_admin' => 'HR Admin',
        ],
        'App\Notifications\HolidayWorkRequestNotification' => [
            'employee' => 'Employee', 'manager' => 'Manager', 'hr_admin' => 'HR Admin',
        ],
        // Expenses
        'App\Notifications\ExpenseClaimNotification' => [
            'employee' => 'Employee', 'manager' => 'Manager', 'hr_admin' => 'HR Admin',
        ],
        // Payroll & increments
        'App\Notifications\PayrollApprovalNotification' => [
            'employee' => 'Submitter', 'finance' => 'Finance', 'director' => 'Director', 'approver' => 'Approver',
        ],
        'App\Notifications\IncrementAppliedNotification' => [
            'employee' => 'Employee', 'director' => 'Director',
        ],
        // Probation / onboarding / offboarding
        'App\Notifications\ProbationConfirmedNotification' => [
            'employee' => 'Employee', 'hr_admin' => 'HR Admin',
        ],
        'App\Notifications\OnboardingCompletedNotification' => [
            'employee' => 'Employee', 'hr_admin' => 'HR Admin',
        ],
        'App\Notifications\OffboardingCompletedNotification' => [
            'employee' => 'Employee', 'hr_admin' => 'HR Admin',
        ],
        'App\Notifications\OnboardingTaskOverdueNotification' => [
            'employee' => 'Employee', 'manager' => 'Manager', 'hr_admin' => 'HR Admin', 'finance' => 'Finance',
        ],
        // Performance
        'App\Notifications\PipCreatedNotification' => [
            'manager' => 'Manager', 'hr_admin' => 'HR Admin',
        ],
        'App\Notifications\PipWeeklyReviewDueNotification' => [
            'employee' => 'Employee', 'manager' => 'Manager', 'hr_admin' => 'HR Admin',
        ],
    ];

    /**
     * Dynamic {{variable}} tokens each event's template may reference, with a
     * one-line description shown in the template editor. Applies to every
     * role of that event — the values differ by recipient, the names don't.
     *
     * @var array<class-string, array<string, string>>
     */
    private const VARIABLES = [
        ExcessBreakNotification::class => [
            'employee_name' => "The flagged employee's name",
            'employee_code' => "The flagged employee's code",
            'manager_name' => "The employee's manager (blank if none)",
            'department' => "The employee's department",
            'date' => 'The date the excess break occurred',
            'break_minutes' => 'Total break time taken, in minutes',
            'break_limit' => 'The configured break allowance, in minutes',
            'excess_minutes' => 'Minutes over the allowance',
            'action_url' => 'Link to the attendance record',
            'company_name' => 'The company name',
        ],
        MissingCheckoutNotification::class => [
            'employee_name' => "The employee's name",
            'employee_code' => "The employee's code",
            'manager_name' => "The employee's manager (blank if none)",
            'department' => "The employee's department",
            'date' => 'The date of the missing clock-out',
            'check_in_time' => 'The recorded clock-in time',
            'action_url' => 'Link to the attendance record',
            'company_name' => 'The company name',
        ],
    ];

    /** Offered to every event that has no entry of its own in VARIABLES. */
    private const DEFAULT_VARIABLES = [
        'employee_name' => "The subject employee's name",
        'action_url' => 'Link to the relevant record',
        'company_name' => 'The company name',
    ];

    /**
     * Dummy values for Preview / Send Test — never real employee data. A
     * method rather than a const: building it needs now()/url()/config(),
     * which a class constant can't call.
     *
     * @return array<class-string, array<string, string>>
     */
    private function samples(): array
    {
        return [
            ExcessBreakNotification::class => [
                'employee_name' => 'Jordan Lee', 'employee_code' => '1042', 'manager_name' => 'Priya Sharma',
                'department' => 'Operations', 'date' => now()->format('d M Y'), 'break_minutes' => '95',
                'break_limit' => '60', 'excess_minutes' => '35', 'action_url' => url('/attendance/my'),
                'company_name' => (string) config('app.name'),
            ],
            MissingCheckoutNotification::class => [
                'employee_name' => 'Jordan Lee', 'employee_code' => '1042', 'manager_name' => 'Priya Sharma',
                'department' => 'Operations', 'date' => now()->format('d M Y'), 'check_in_time' => '09:14',
                'action_url' => url('/attendance/my'), 'company_name' => (string) config('app.name'),
            ],
        ];
    }

    /**
     * Dummy values to render a Preview or Send Test with. Every variable the
     * event offers gets a value, even one with no curated sample.
     *
     * @return array<string, string>
     */
    public function sampleDataFor(string $key): array
    {
        $curated = $this->samples()[$key] ?? [];
        $variables = $this->variablesFor($key);

        $generic = [];
        foreach (array_keys($variables) as $name) {
            $generic[$name] = $curated[$name] ?? Str::headline($name);
        }

        return $generic;
    }

    /**
     * Build the full catalog of controllable events.
     *
     * @return array<int, array{key:string, label:string, group:string, description:?string, sort_order:int}>
     */
    public function all(): array
    {
        $entries = [];

        foreach ($this->discoverNotificationClasses() as $class) {
            if (array_key_exists($class, self::RETIRED)) {
                continue;
            }

            $base = Str::beforeLast(class_basename($class), 'Notification');
            $entries[$class] = [
                'key' => $class,
                'label' => Str::headline($base ?: class_basename($class)),
                'group' => $this->groupFor($class),
                'description' => null,
            ];
        }

        foreach (self::MAILABLES as $class => $meta) {
            if (! class_exists($class)) {
                continue;
            }
            $entries[$class] = [
                'key' => $class,
                'label' => $meta['label'],
                'group' => $meta['group'],
                'description' => $meta['description'],
            ];
        }

        // Stable ordering: group, then label.
        $entries = array_values($entries);
        usort($entries, fn ($a, $b) => [$a['group'], $a['label']] <=> [$b['group'], $b['label']]);

        return array_map(function ($entry, $i) {
            $entry['sort_order'] = $i;

            return $entry;
        }, $entries, array_keys($entries));
    }

    /**
     * Upsert settings rows for every catalog entry. Returns the number created.
     */
    public function sync(): int
    {
        $created = 0;

        // Retired events lose their row: leaving one behind means the settings
        // screen keeps offering toggles for something that can never fire.
        NotificationSetting::whereIn('key', array_keys(self::RETIRED))->get()->each->delete();

        foreach ($this->all() as $entry) {
            $setting = NotificationSetting::where('key', $entry['key'])->first();

            if ($setting) {
                // Refresh presentation only; never clobber admin choices.
                $setting->update([
                    'label' => $entry['label'],
                    'group' => $entry['group'],
                    'description' => $entry['description'],
                    'sort_order' => $entry['sort_order'],
                    'is_system' => true,
                ]);
            } else {
                $setting = NotificationSetting::create([
                    'key' => $entry['key'],
                    'label' => $entry['label'],
                    'group' => $entry['group'],
                    'description' => $entry['description'],
                    'mail_enabled' => true,
                    'database_enabled' => true,
                    'is_automatic' => true,
                    'is_system' => true,
                    'sort_order' => $entry['sort_order'],
                ]);

                $created++;
            }

            $this->syncRoles($setting);
        }

        return $created;
    }

    /**
     * Add a row for any role this event should have that it doesn't yet —
     * new roles only, existing role rows (and their admin-chosen toggles or
     * templates) are never touched.
     */
    private function syncRoles(NotificationSetting $setting): void
    {
        $roles = self::ROLES[$setting->key] ?? [];

        if ($roles === []) {
            return;
        }

        $existing = NotificationRoleSetting::where('notification_setting_id', $setting->id)
            ->pluck('role')->all();

        foreach (array_keys($roles) as $role) {
            if (in_array($role, $existing, true)) {
                continue;
            }

            // Inherited from the event, never defaulted to on. A role row
            // overrides the event's own setting, so seeding one with true
            // over an event an admin had switched off would silently start
            // sending again — the settings screen would still show the event
            // as off while mail went out.
            NotificationRoleSetting::create([
                'notification_setting_id' => $setting->id,
                'role' => $role,
                'mail_enabled' => $setting->mail_enabled,
                'database_enabled' => $setting->database_enabled,
                'is_automatic' => $setting->is_automatic,
            ]);
        }
    }

    /**
     * The roles configured for this event: role key => human label. Empty
     * for a single-role event.
     *
     * @return array<string, string>
     */
    public function rolesFor(string $key): array
    {
        return self::ROLES[$key] ?? [];
    }

    /**
     * The {{variable}} tokens this event's template may reference: name =>
     * description.
     *
     * @return array<string, string>
     */
    public function variablesFor(string $key): array
    {
        return self::VARIABLES[$key] ?? self::DEFAULT_VARIABLES;
    }

    /**
     * @return array<int, class-string<Notification>>
     */
    private function discoverNotificationClasses(): array
    {
        $dir = app_path('Notifications');

        if (! File::isDirectory($dir)) {
            return [];
        }

        $classes = [];

        foreach (File::allFiles($dir) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = Str::of($file->getRealPath())
                ->after($dir.DIRECTORY_SEPARATOR)
                ->replace('.php', '')
                ->replace(['/', '\\'], '\\')
                ->value();

            $class = 'App\\Notifications\\'.$relative;

            if (! class_exists($class)) {
                continue;
            }

            if (! is_subclass_of($class, Notification::class)) {
                continue; // skip traits/concerns and non-notifications
            }

            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * Infer a friendly grouping from the notification class name.
     */
    private function groupFor(string $class): string
    {
        $name = class_basename($class);

        return match (true) {
            Str::contains($name, 'Leave') => 'Leave',
            Str::contains($name, ['Ot', 'OT', 'Overtime', 'Nexflow']) => 'Overtime',
            Str::contains($name, ['Attendance', 'Regularisation', 'Break', 'Checkout', 'CheckIn', 'NewHire']) => 'Attendance',
            Str::contains($name, ['Payroll', 'Payslip', 'Salary', 'Incentive', 'Reimbursement', 'Bonus', 'Expense', 'Finance']) => 'Payroll & Finance',
            Str::contains($name, 'Probation') => 'Probation',
            Str::contains($name, ['Onboarding', 'Welcome']) => 'Onboarding',
            Str::contains($name, ['Offboarding', 'Exit', 'AssetReturn']) => 'Offboarding',
            Str::contains($name, ['Review', 'Kpi', 'Goal', 'Pip', 'Warning', 'Promotion']) => 'Performance',
            Str::contains($name, 'Document') => 'Documents',
            Str::contains($name, ['EmployeeStatus', 'Status']) => 'Employee Lifecycle',
            Str::contains($name, ['Team', 'Invitation']) => 'Teams',
            default => 'General',
        };
    }
}
