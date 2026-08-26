<?php

namespace App\Services\Notifications;

use App\Mail\EmployeeInvitationMail;
use App\Mail\PayslipMail;
use App\Mail\WelcomeEmployeeMail;
use App\Models\NotificationSetting;
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
    ];

    /**
     * Build the full catalog of controllable events.
     *
     * @return array<int, array{key:string, label:string, group:string, description:?string, sort_order:int}>
     */
    public function all(): array
    {
        $entries = [];

        foreach ($this->discoverNotificationClasses() as $class) {
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

        foreach ($this->all() as $entry) {
            $existing = NotificationSetting::where('key', $entry['key'])->first();

            if ($existing) {
                // Refresh presentation only; never clobber admin choices.
                $existing->update([
                    'label' => $entry['label'],
                    'group' => $entry['group'],
                    'description' => $entry['description'],
                    'sort_order' => $entry['sort_order'],
                    'is_system' => true,
                ]);

                continue;
            }

            NotificationSetting::create([
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

        return $created;
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
