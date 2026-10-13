<?php

namespace App\Services\Security;

use App\Enums\DataScope;

/**
 * Which permissions reach employee data (and so carry a scope), and the
 * scope each built-in role gets when an admin has not set one.
 *
 * Defaults: HR company-wide; Directors and department heads their own /
 * headed department(s) (D1, 8 Oct 2026 — company-wide only when granted);
 * Finance company-wide for payroll and attendance / leave summaries;
 * managers their reporting line; employees their own records.
 */
final class PermissionScopes
{
    /**
     * Permission keys whose effect depends on whose data it is.
     *
     * @var array<int, string>
     */
    public const SCOPED = [
        // Dashboards: the level a view may open at (DashboardAccess)
        'view_team_dashboard', 'view_department_dashboard',
        // Employees
        'manage_employees', 'view_employee', 'edit_employee', 'delete_employee', 'view_employee_360',
        'manage_onboarding', 'manage_offboarding', 'approve_profile_changes',
        // Attendance
        'view_attendance', 'manage_attendance', 'export_attendance', 'view_live_attendance',
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
        'Dashboard' => [
            ['key' => 'view_main_dashboard', 'label' => 'Main Dashboard', 'description' => 'Open the main dashboard: the company or HR overview, or the employee’s own self-service dashboard'],
            ['key' => 'view_team_dashboard', 'label' => 'Team View', 'description' => 'Open Team View (approvals and today’s attendance for the people they approve). Scope: the level it opens at — team, department or company'],
            ['key' => 'view_department_dashboard', 'label' => 'Department View', 'description' => 'Open Department View (a department’s attendance, leave and approvals). Scope: department or company'],
            ['key' => 'view_finance_dashboard', 'label' => 'Finance View', 'description' => 'Open Finance View (payroll queue, overtime payable, finance sign-off)'],
        ],
        'Employee Management' => [
            ['key' => 'view_employee_360', 'label' => 'View Employee 360°', 'description' => 'Open the Employee 360° page: one snapshot of attendance, leave, overtime, performance and activity for employees in scope'],
        ],
        'Attendance' => [
            ['key' => 'export_attendance', 'label' => 'Export Attendance', 'description' => 'Download attendance registers and logs for employees in scope'],
            ['key' => 'view_live_attendance', 'label' => 'View Live Attendance', 'description' => "Open the Live Attendance panel: today's punches and status for employees in scope"],
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
        // D1 (8 Oct 2026): a Director is the spec's Department Head — their
        // own / headed department(s) by default, never every department.
        // Company-wide reach is granted explicitly in Roles & Permissions.
        'director' => DataScope::Department,
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
        // A coordinator watches the attendance of the department(s) they work in.
        'coordinator' => [
            'view_live_attendance' => DataScope::Department,
        ],
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
        'hr_admin' => ['export_attendance', 'view_overtime', 'manage_overtime', 'manage_notifications', 'view_live_attendance', 'view_employee_360',
            'view_main_dashboard', 'view_team_dashboard', 'view_finance_dashboard'],
        'director' => ['export_attendance', 'view_overtime', 'view_live_attendance', 'view_employee_360',
            'view_team_dashboard', 'view_department_dashboard'],
        // Department View only when an admin grants it (it repeats Team View's people).
        'department_head' => ['view_live_attendance', 'view_employee_360', 'view_team_dashboard'],
        'manager' => ['view_overtime', 'view_live_attendance', 'view_employee_360', 'view_team_dashboard'],
        'coordinator' => ['view_live_attendance', 'view_main_dashboard'],
        // Finance: payroll in full; attendance / leave / OT summaries only.
        'finance' => ['view_attendance', 'export_attendance', 'view_overtime', 'view_leave_management', 'view_finance_dashboard'],
        'employee' => ['view_main_dashboard'],
    ];

    /**
     * Scoped dashboards open only at these levels (DashboardAccess); any
     * other scope — own records, none, unknown — keeps the view closed.
     *
     * @var array<string, array<int, DataScope>>
     */
    public const DASHBOARD_SCOPES = [
        'view_team_dashboard' => [DataScope::Team, DataScope::Department, DataScope::SelectedDepartments, DataScope::All],
        'view_department_dashboard' => [DataScope::Department, DataScope::SelectedDepartments, DataScope::All],
    ];

    /** @return array<int, DataScope>|null the scopes a permission accepts, or null for any */
    public static function allowedScopes(string $key): ?array
    {
        return self::DASHBOARD_SCOPES[$key] ?? null;
    }

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
