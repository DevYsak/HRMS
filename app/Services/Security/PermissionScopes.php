<?php

namespace App\Services\Security;

use App\Enums\DataScope;

/**
 * Which permissions reach employee data (and so carry a scope), and the
 * scope each built-in role gets when an admin has not set one.
 *
 * The defaults reproduce the reach the application had before scopes were
 * configurable, so existing roles behave the same on the day this ships:
 * HR / Director company-wide, Finance company-wide for payroll and
 * attendance / leave summaries, managers their reporting line,
 * department heads their department(s), employees their own records.
 */
final class PermissionScopes
{
    /**
     * Permission keys whose effect depends on whose data it is.
     *
     * @var array<int, string>
     */
    public const SCOPED = [
        // Employees
        'manage_employees', 'view_employee', 'edit_employee', 'delete_employee',
        'manage_onboarding', 'manage_offboarding', 'approve_profile_changes',
        // Attendance
        'view_attendance', 'manage_attendance', 'export_attendance',
        'approve_regularisation', 'hr_approve_regularisation',
        'monitor_attendance_exceptions', 'remind_employees',
        'approve_wfh',
        // Overtime
        'view_overtime', 'approve_overtime', 'manage_overtime',
        // Leave
        'view_leave_management', 'approve_leave', 'manage_leave_balances',
        'add_leave_balance', 'deduct_leave_balance', 'correct_leave_balance',
        'apply_leave_on_behalf', 'record_approved_leave', 'manage_approved_leave',
        'override_leave_policy', 'export_leave',
        'view_leave_regularisation', 'approve_leave_regularisation',
        // Performance
        'view_performance', 'review_performance', 'manage_promotions', 'manage_pip', 'manage_warning_letters', 'manage_scorecards',
        // Documents
        'view_documents', 'manage_documents', 'upload_documents', 'view_kyc_documents',
        // Payroll
        'view_payroll', 'view_finance_profile',
        // Reports & notifications
        'view_reports', 'export_reports', 'manage_notifications',
    ];

    /**
     * Permissions added with scoped access (keys that did not exist before).
     *
     * @var array<string, array<int, array{key: string, label: string, description: string}>>
     */
    public const NEW_PERMISSIONS = [
        'Attendance' => [
            ['key' => 'export_attendance', 'label' => 'Export Attendance', 'description' => 'Download attendance registers and logs for employees in scope'],
        ],
        'Overtime' => [
            ['key' => 'view_overtime', 'label' => 'View Overtime', 'description' => 'See overtime requests and hours for employees in scope'],
            ['key' => 'manage_overtime', 'label' => 'Manage Overtime', 'description' => 'Open OT windows and correct overtime records for employees in scope'],
        ],
        'Notifications' => [
            ['key' => 'manage_notifications', 'label' => 'Manage Notifications', 'description' => 'Send and manage notifications for employees in scope'],
        ],
    ];

    /**
     * Scope a built-in role gets for any scoped permission it holds, when
     * the grant itself carries none.
     *
     * @var array<string, DataScope>
     */
    public const ROLE_DEFAULTS = [
        'super_admin' => DataScope::All,
        'hr_admin' => DataScope::All,
        'director' => DataScope::All,
        // Finance reaches its own reporting line for people work (reviews,
        // PIPs) like any manager; payroll and summaries are company-wide below.
        'finance' => DataScope::Team,
        'department_head' => DataScope::Department,
        'manager' => DataScope::Team,
        'coordinator' => DataScope::Team,
        'employee' => DataScope::Own,
    ];

    /**
     * Per-permission exceptions to ROLE_DEFAULTS.
     *
     * @var array<string, array<string, DataScope>>
     */
    public const ROLE_PERMISSION_DEFAULTS = [
        'finance' => [
            'view_payroll' => DataScope::All,
            'view_finance_profile' => DataScope::All,
            'view_employee' => DataScope::All,
            'view_attendance' => DataScope::All,
            'export_attendance' => DataScope::All,
            'view_overtime' => DataScope::All,
            'view_leave_management' => DataScope::All,
            'view_reports' => DataScope::All,
            'export_reports' => DataScope::All,
        ],
    ];

    /** Extra default grants per role for the new keys (role slug => keys). */
    public const NEW_ROLE_GRANTS = [
        'hr_admin' => ['export_attendance', 'view_overtime', 'manage_overtime', 'manage_notifications'],
        'director' => ['export_attendance', 'view_overtime'],
        'manager' => ['view_overtime'],
        // Finance: payroll in full; attendance / leave / OT summaries only.
        'finance' => ['view_attendance', 'export_attendance', 'view_overtime', 'view_leave_management'],
    ];

    public static function isScoped(string $key): bool
    {
        return in_array($key, self::SCOPED, true);
    }

    /**
     * The scope a role gets for a permission when the grant names none. A
     * custom role reaches every employee only when it manages employees
     * (as ApprovalGuard has always treated it); otherwise its reporting line.
     *
     * @param  array<int, string>  $roleKeys
     */
    public static function defaultFor(?string $roleSlug, string $permission, array $roleKeys = []): DataScope
    {
        if ($roleSlug !== null && isset(self::ROLE_PERMISSION_DEFAULTS[$roleSlug][$permission])) {
            return self::ROLE_PERMISSION_DEFAULTS[$roleSlug][$permission];
        }

        if ($roleSlug !== null && isset(self::ROLE_DEFAULTS[$roleSlug])) {
            return self::ROLE_DEFAULTS[$roleSlug];
        }

        return in_array('manage_employees', $roleKeys, true) ? DataScope::All : DataScope::Team;
    }
}
