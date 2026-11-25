<?php

namespace App\Services\Notifications;

use App\Mail\EmployeeInvitationMail;
use App\Mail\IncrementLetterMail;
use App\Mail\PayslipMail;
use App\Mail\WelcomeEmployeeMail;
use App\Models\NotificationRoleSetting;
use App\Models\NotificationSetting;
use App\Notifications\ExcessBreakNotification;
use App\Notifications\LeaveRequestNotification;
use App\Notifications\LeaveRevisionNotification;
use App\Notifications\MissedPunchAllowanceExceededNotification;
use App\Notifications\MissedPunchNotification;
use App\Notifications\MissedPunchWeeklyWarningNotification;
use App\Notifications\MissingCheckoutNotification;
use Illuminate\Auth\Notifications\ResetPassword;
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
     * The only events that email by default (D5, 8 Oct 2026; spec §2.2):
     * payslip issued, account created / login issued, and password reset —
     * and, since 9 Nov 2026, the leave request workflow (request, decision,
     * HR's changes), which HR and the people HR chooses follow by email.
     * Everything else is in-app by default; HR may still switch email on for
     * an event in Settings → Notifications & Email.
     *
     * @var array<int, class-string>
     */
    public const CRITICAL_MAIL = [
        PayslipMail::class,
        WelcomeEmployeeMail::class,
        EmployeeInvitationMail::class,
        ResetPassword::class,
        LeaveRequestNotification::class,
        LeaveRevisionNotification::class,
    ];

    public static function isCriticalMail(string $key): bool
    {
        return in_array(self::parentKey($key), self::CRITICAL_MAIL, true);
    }

    /**
     * Shown names for events whose class name no longer says what they are
     * (the class name is the key of their settings row, so it stays).
     *
     * @var array<class-string, string>
     */
    private const LABELS = [
        MissedPunchWeeklyWarningNotification::class => 'Missed Punch Warning',
    ];

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
     * Events whose stages are configured separately: each stage is its own
     * settings row keyed "FQCN@stage" — its own email / in-app / auto-send
     * switches, template and recipients — so "Leave approved" can email while
     * "Leave application" only shows in-app. A stage with no row of its own
     * follows the event. The notification says which stage it is through
     * notificationStage().
     *
     * @var array<class-string, array<string, string>>
     */
    public const STAGES = [
        'App\Notifications\LeaveRequestNotification' => [
            'submitted' => 'Leave application',
            'approved' => 'Leave approved',
            'rejected' => 'Leave rejected',
            'cancelled' => 'Leave cancelled',
        ],
        'App\Notifications\RegularisationReviewedNotification' => [
            'approved' => 'Missed punch (regularisation) approved',
            'rejected' => 'Missed punch (regularisation) rejected',
        ],
    ];

    /** "FQCN@stage", or the class itself when there is no stage. */
    public static function stageKey(string $class, ?string $stage): string
    {
        return $stage !== null && isset(self::STAGES[$class][$stage]) ? $class.'@'.$stage : $class;
    }

    /** The event (class) a key belongs to: "FQCN@stage" → FQCN. */
    public static function parentKey(string $key): string
    {
        return Str::before($key, '@');
    }

    /**
     * The settings key one notification is governed by: its stage's row when
     * that stage has one, else its event's. Fail-open: a stage not synced yet
     * follows the event, exactly as before stages existed.
     */
    public static function eventKeyFor(object $notification): string
    {
        $class = $notification::class;
        $stage = method_exists($notification, 'notificationStage') ? $notification->notificationStage() : null;
        $key = self::stageKey($class, $stage);

        return $key !== $class && NotificationSetting::for($key) !== null ? $key : $class;
    }

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
        MissedPunchNotification::class => ['employee' => 'Employee', 'hr_admin' => 'HR Admin'],
        MissedPunchWeeklyWarningNotification::class => ['employee' => 'Employee', 'hr_admin' => 'HR Admin'],
        MissedPunchAllowanceExceededNotification::class => ['employee' => 'Employee', 'hr_admin' => 'HR Admin'],
        'App\Notifications\AttendanceRegularisationNotification' => [
            'employee' => 'Employee', 'manager' => 'Manager', 'hr_admin' => 'HR Admin',
        ],
        // Leave
        'App\Notifications\LeaveRequestNotification' => [
            'employee' => 'Employee', 'manager' => 'Manager', 'hr_admin' => 'HR Admin', 'approver' => 'Approver',
            // People HR adds to a decision (9 Nov 2026), the employee adds to an
            // application (Notify Others, 25 Nov 2026), or a settings rule adds.
            'recipient' => 'Also notified (added by HR, the employee or a rule)',
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
        MissedPunchNotification::class => [
            'employee_name' => "The employee's name",
            'employee_code' => "The employee's ID",
            'department' => "The employee's department",
            'date' => 'The day of the missed punch',
            'missed_punch' => 'What was missed, e.g. "Missed OUT between 12:39 PM and 01:05 PM"',
            'month_count' => 'Punches missed so far this month (Mon–Fri days)',
            'monthly_allowance' => 'Missed punches allowed per month',
            'action_url' => 'Link to request a regularisation for that day',
            'company_name' => 'The company name',
        ],
        MissedPunchWeeklyWarningNotification::class => [
            'employee_name' => "The employee's name",
            'employee_code' => "The employee's ID",
            'department' => "The employee's department",
            'month' => 'The month, e.g. "November 2026"',
            'missed_count' => 'Punches missed that month (Mon–Fri days)',
            'monthly_allowance' => 'Missed punches allowed per month',
            'action_url' => 'Link to the attendance page',
            'company_name' => 'The company name',
        ],
        MissedPunchAllowanceExceededNotification::class => [
            'employee_name' => "The employee's name",
            'employee_code' => "The employee's ID",
            'department' => "The employee's department",
            'month' => 'The month, e.g. "November 2026"',
            'missed_count' => 'Punches missed that month (Mon–Fri days)',
            'monthly_allowance' => 'Missed punches allowed per month',
            'action_url' => 'Link to the Missed Punches review page',
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
            MissedPunchNotification::class => [
                'employee_name' => 'Jordan Lee', 'employee_code' => 'CNS042', 'department' => 'Operations',
                'date' => now()->format('d M Y'), 'missed_punch' => 'Missed OUT between 12:39 PM and 01:05 PM',
                'month_count' => '2', 'monthly_allowance' => '5', 'action_url' => url('/attendance/my'), 'company_name' => (string) config('app.name'),
            ],
            MissedPunchWeeklyWarningNotification::class => [
                'employee_name' => 'Jordan Lee', 'employee_code' => 'CNS042', 'department' => 'Operations',
                'month' => now()->format('F Y'), 'missed_count' => '3', 'monthly_allowance' => '5',
                'action_url' => url('/attendance/my'), 'company_name' => (string) config('app.name'),
            ],
            MissedPunchAllowanceExceededNotification::class => [
                'employee_name' => 'Jordan Lee', 'employee_code' => 'CNS042', 'department' => 'Operations',
                'month' => now()->format('F Y'), 'missed_count' => '6', 'monthly_allowance' => '5',
                'action_url' => url('/attendance/missed-punches'), 'company_name' => (string) config('app.name'),
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
        $curated = $this->samples()[$key] ?? $this->samples()[self::parentKey($key)] ?? [];
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
                'label' => self::LABELS[$class] ?? Str::headline($base ?: class_basename($class)),
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

        // Each event's stages right under it, in their own order.
        $withStages = [];
        foreach ($entries as $entry) {
            $withStages[] = $entry;
            foreach (self::STAGES[$entry['key']] ?? [] as $stage => $label) {
                $withStages[] = [
                    'key' => self::stageKey($entry['key'], $stage),
                    'label' => $label,
                    'group' => $entry['group'],
                    'description' => 'A stage of '.$entry['label'].' — configured on its own; with nothing set it follows '.$entry['label'].'.',
                ];
            }
        }
        $entries = $withStages;

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
                // A new stage starts exactly as its event is set now, so adding
                // stages never starts or stops a single email by itself.
                $parent = $entry['key'] !== self::parentKey($entry['key'])
                    ? NotificationSetting::where('key', self::parentKey($entry['key']))->first()
                    : null;
                $setting = NotificationSetting::create([
                    'key' => $entry['key'],
                    'label' => $entry['label'],
                    'group' => $entry['group'],
                    'description' => $entry['description'],
                    // D5: in-app by default; email only for critical events.
                    'mail_enabled' => $parent?->mail_enabled ?? self::isCriticalMail($entry['key']),
                    'database_enabled' => $parent?->database_enabled ?? true,
                    'is_automatic' => $parent?->is_automatic ?? true,
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
        // A stage has no role rows of its own: its switch governs it, and the
        // event's role rows still apply (NotificationDeliveryGate).
        if (str_contains($setting->key, '@')) {
            return;
        }

        $roles = $this->rolesFor($setting->key);

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
        return self::ROLES[$key] ?? self::ROLES[self::parentKey($key)] ?? [];
    }

    /**
     * The {{variable}} tokens this event's template may reference: name =>
     * description.
     *
     * @return array<string, string>
     */
    public function variablesFor(string $key): array
    {
        return self::VARIABLES[$key] ?? self::VARIABLES[self::parentKey($key)] ?? self::DEFAULT_VARIABLES;
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
            Str::contains($name, ['Attendance', 'Regularisation', 'Break', 'Checkout', 'CheckIn', 'NewHire', 'Punch']) => 'Attendance',
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
