<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Permission catalogue grouped by module, exactly matching the spec.
     * Four extra keys (marked below) are appended to preserve behaviour the
     * current enum-based system already enforces but the spec's list omits:
     * approve_overtime, approve_wfh, approve_finance, view_finance_profile.
     *
     * @var array<string, array<int, array{key: string, label: string, description: string}>>
     */
    private const CATALOGUE = [
        'Dashboard' => [
            ['key' => 'view_dashboard', 'label' => 'View Dashboard', 'description' => 'Access the personal dashboard'],
            ['key' => 'view_executive_dashboard', 'label' => 'View Executive Dashboard', 'description' => 'Access company-wide executive insights'],
            ['key' => 'view_hr_dashboard', 'label' => 'View HR Dashboard', 'description' => 'Access HR overview metrics and widgets'],
        ],
        'Employee Management' => [
            ['key' => 'manage_employees', 'label' => 'Manage Employees', 'description' => 'Full access to employee records and lifecycle'],
            ['key' => 'create_employee', 'label' => 'Create Employee', 'description' => 'Add new employee profiles'],
            ['key' => 'edit_employee', 'label' => 'Edit Employee', 'description' => 'Update existing employee profiles'],
            ['key' => 'delete_employee', 'label' => 'Delete Employee', 'description' => 'Remove employee records'],
            ['key' => 'view_employee', 'label' => 'View Employee', 'description' => 'View individual employee profiles'],
            ['key' => 'view_directory', 'label' => 'View Directory', 'description' => 'Browse the company employee directory'],
            ['key' => 'view_org_chart', 'label' => 'View Org Chart', 'description' => 'View the organisational hierarchy'],
            ['key' => 'manage_onboarding', 'label' => 'Manage Onboarding', 'description' => 'Run new-hire onboarding workflows'],
            ['key' => 'manage_offboarding', 'label' => 'Manage Offboarding', 'description' => 'Run employee offboarding workflows'],
        ],
        'Attendance' => [
            ['key' => 'view_attendance', 'label' => 'View Attendance', 'description' => 'View attendance records'],
            ['key' => 'manage_attendance', 'label' => 'Manage Attendance', 'description' => 'Edit and correct attendance records'],
            ['key' => 'approve_regularisation', 'label' => 'Approve Regularisation', 'description' => 'Approve attendance regularisation requests'],
            ['key' => 'manage_shifts', 'label' => 'Manage Shifts', 'description' => 'Configure shifts and work schedules'],
            ['key' => 'manage_biometric', 'label' => 'Manage Biometric', 'description' => 'Configure biometric devices and sync settings'],
            ['key' => 'approve_overtime', 'label' => 'Approve Overtime', 'description' => 'Review and approve overtime pre-approval requests'],
            ['key' => 'approve_wfh', 'label' => 'Approve Work From Home', 'description' => 'Review and approve work-from-home requests'],
        ],
        'Leave Management' => [
            ['key' => 'view_leave', 'label' => 'View Leave', 'description' => 'View leave requests and balances'],
            ['key' => 'apply_leave', 'label' => 'Apply Leave', 'description' => 'Submit personal leave applications'],
            ['key' => 'approve_leave', 'label' => 'Approve Leave', 'description' => 'Review and approve / reject leave requests'],
            ['key' => 'manage_leave_types', 'label' => 'Manage Leave Types', 'description' => 'Configure leave type definitions'],
            ['key' => 'manage_leave_policies', 'label' => 'Manage Leave Policies', 'description' => 'Configure leave accrual and policy rules'],
            ['key' => 'manage_leave_balances', 'label' => 'Manage Leave Balances', 'description' => 'Adjust employee leave balances'],
            ['key' => 'manage_leave_encashment', 'label' => 'Manage Leave Encashment', 'description' => 'Process leave encashment requests'],
        ],
        'Payroll' => [
            ['key' => 'view_payroll', 'label' => 'View Payroll', 'description' => 'View payroll runs and summaries'],
            ['key' => 'run_payroll', 'label' => 'Run Payroll', 'description' => 'Process payroll cycles and manage components'],
            ['key' => 'approve_payroll', 'label' => 'Approve Payroll', 'description' => 'Approve payroll runs for disbursement'],
            ['key' => 'view_payslips', 'label' => 'View Payslips', 'description' => 'Access generated payslips'],
            ['key' => 'manage_salary_components', 'label' => 'Manage Salary Components', 'description' => 'Configure salary structures and components'],
            ['key' => 'approve_finance', 'label' => 'Finance Approval', 'description' => 'Sign off payroll for disbursement'],
            ['key' => 'view_finance_profile', 'label' => 'Finance Employee View', 'description' => 'View salary, bank and statutory details for payroll processing'],
            ['key' => 'lock_payroll', 'label' => 'Lock Payroll', 'description' => 'Lock a finalized payroll so it can no longer be regenerated'],
            ['key' => 'unlock_payroll', 'label' => 'Unlock Payroll', 'description' => 'Unlock a previously locked payroll'],
            ['key' => 'delete_payslip', 'label' => 'Delete Payslip', 'description' => 'Delete an individual draft payslip'],
        ],
        'Performance' => [
            ['key' => 'view_performance', 'label' => 'View Performance', 'description' => 'View own and team performance data'],
            ['key' => 'review_performance', 'label' => 'Review Performance', 'description' => 'Access employee performance reviews and ratings'],
            ['key' => 'manage_kpi_templates', 'label' => 'Manage KPI Templates', 'description' => 'Build and edit KPI scoring frameworks'],
            ['key' => 'manage_review_cycles', 'label' => 'Manage Review Cycles', 'description' => 'Launch and configure performance review cycles'],
            ['key' => 'manage_scorecards', 'label' => 'Manage Scorecards', 'description' => 'View and manage employee KPI scorecards'],
            ['key' => 'manage_promotions', 'label' => 'Manage Promotions', 'description' => 'Review and approve promotion recommendations'],
            ['key' => 'manage_pip', 'label' => 'Manage PIP', 'description' => 'Manage performance improvement plans'],
            ['key' => 'manage_warning_letters', 'label' => 'Manage Warning Letters', 'description' => 'Issue and manage employee warning letters'],
        ],
        'Documents' => [
            ['key' => 'upload_documents', 'label' => 'Upload Documents', 'description' => 'Upload HR documents for employees'],
            ['key' => 'manage_documents', 'label' => 'Manage Documents', 'description' => 'Organise and maintain document records'],
            ['key' => 'view_documents', 'label' => 'View Documents', 'description' => 'View uploaded HR documents'],
            ['key' => 'acknowledge_documents', 'label' => 'Acknowledge Documents', 'description' => 'Acknowledge receipt of issued documents'],
        ],
        'Reports' => [
            ['key' => 'view_reports', 'label' => 'View Reports', 'description' => 'Access system reports'],
            ['key' => 'export_reports', 'label' => 'Export Reports', 'description' => 'Download attendance, OT and payroll reports'],
        ],
        'Profile' => [
            ['key' => 'edit_own_profile', 'label' => 'Edit Own Profile', 'description' => 'Change your own editable profile fields'],
            ['key' => 'request_profile_change', 'label' => 'Request Profile Change', 'description' => 'Ask HR to approve a change to a restricted field'],
            ['key' => 'approve_profile_changes', 'label' => 'Approve Profile Changes', 'description' => 'Review and decide employee profile change requests'],
        ],
        'Settings' => [
            ['key' => 'manage_roles', 'label' => 'Manage Roles', 'description' => 'Create, edit and assign roles & permissions'],
            ['key' => 'manage_settings', 'label' => 'Manage Settings', 'description' => 'Configure company-wide system settings'],
            ['key' => 'manage_company_settings', 'label' => 'Manage Company Settings', 'description' => 'Configure company profile, branding and policies'],
        ],
    ];

    /** System roles, mirroring the existing UserRole enum values (preserves real assigned users). */
    private const SYSTEM_ROLES = [
        'super_admin' => ['name' => 'Super Admin', 'description' => 'Full, unrestricted access to every module.'],
        'hr_admin' => ['name' => 'HR Admin', 'description' => 'Manages employees, settings, payroll and HR operations.'],
        'director' => ['name' => 'Director', 'description' => 'Oversees company operations, approvals and reporting.'],
        'manager' => ['name' => 'Manager', 'description' => 'Manages a team — approvals and performance reviews.'],
        'finance' => ['name' => 'Finance', 'description' => 'Runs payroll and signs off on financial approvals.'],
        'employee' => ['name' => 'Employee', 'description' => 'Standard employee self-service access.'],
    ];

    /**
     * Default permission keys per role, mirroring the behaviour the legacy
     * UserRole/RolePermissionService defaults already enforce (Super Admin
     * is granted everything automatically and is not listed here).
     *
     * @var array<string, array<int, string>>
     */
    private const ROLE_DEFAULTS = [
        'hr_admin' => [
            'view_dashboard', 'view_executive_dashboard', 'view_hr_dashboard',
            'manage_employees', 'create_employee', 'edit_employee', 'delete_employee', 'view_employee', 'view_directory', 'view_org_chart', 'manage_onboarding', 'manage_offboarding',
            'view_attendance', 'manage_attendance', 'approve_regularisation', 'manage_shifts', 'manage_biometric', 'approve_overtime', 'approve_wfh',
            'view_leave', 'apply_leave', 'approve_leave', 'manage_leave_types', 'manage_leave_policies', 'manage_leave_balances', 'manage_leave_encashment',
            'view_payroll', 'run_payroll', 'view_payslips', 'manage_salary_components', 'view_finance_profile', 'lock_payroll', 'unlock_payroll', 'delete_payslip',
            'view_performance', 'review_performance', 'manage_kpi_templates', 'manage_review_cycles', 'manage_scorecards', 'manage_promotions', 'manage_pip', 'manage_warning_letters',
            'upload_documents', 'manage_documents', 'view_documents', 'acknowledge_documents',
            'view_reports', 'export_reports',
            'manage_roles', 'manage_settings', 'manage_company_settings',
            'edit_own_profile', 'request_profile_change', 'approve_profile_changes',
        ],
        'director' => [
            'view_dashboard', 'view_executive_dashboard',
            'manage_employees', 'create_employee', 'edit_employee', 'delete_employee', 'view_employee', 'view_directory', 'view_org_chart', 'manage_onboarding', 'manage_offboarding',
            'view_attendance', 'manage_attendance', 'approve_regularisation', 'approve_overtime', 'approve_wfh',
            'view_leave', 'apply_leave', 'approve_leave',
            'view_payroll', 'approve_payroll', 'view_payslips', 'approve_finance',
            'view_performance', 'review_performance', 'manage_kpi_templates', 'manage_review_cycles', 'manage_scorecards', 'manage_promotions', 'manage_pip', 'manage_warning_letters',
            'view_documents', 'acknowledge_documents',
            'view_reports', 'export_reports',
            'edit_own_profile', 'request_profile_change',
        ],
        'manager' => [
            'view_dashboard',
            'view_employee', 'view_directory', 'view_org_chart',
            'view_attendance', 'approve_regularisation', 'approve_overtime', 'approve_wfh',
            'view_leave', 'apply_leave', 'approve_leave',
            'view_payslips',
            'view_performance', 'review_performance', 'manage_promotions', 'manage_pip',
            'view_documents', 'acknowledge_documents',
            'edit_own_profile', 'request_profile_change',
        ],
        'finance' => [
            'view_dashboard',
            'view_employee',
            'view_leave', 'apply_leave',
            'view_payroll', 'run_payroll', 'approve_payroll', 'view_payslips', 'manage_salary_components', 'approve_finance', 'view_finance_profile',
            'view_performance', 'review_performance', 'manage_promotions', 'manage_pip',
            'view_documents', 'acknowledge_documents',
            'view_reports', 'export_reports',
            'edit_own_profile', 'request_profile_change',
        ],
        'employee' => [
            'view_dashboard',
            'view_directory', 'view_org_chart',
            'view_leave', 'apply_leave',
            'view_payslips',
            'view_performance',
            'view_documents', 'acknowledge_documents',
            'edit_own_profile', 'request_profile_change',
        ],
    ];

    /** Maps legacy kebab-case role_permissions override keys to new snake_case permission keys. */
    private const LEGACY_KEY_MAP = [
        'manage-employees' => 'manage_employees',
        'approve-leave' => 'approve_leave',
        'approve-ot' => 'approve_overtime',
        'approve-wfh' => 'approve_wfh',
        'run-payroll' => 'run_payroll',
        'approve-finance' => 'approve_finance',
        'manage-settings' => 'manage_settings',
        'manage-documents' => 'manage_documents',
        'view-reports' => 'view_reports',
        'view-finance-profile' => 'view_finance_profile',
        'review-performance' => 'review_performance',
    ];

    public function run(): void
    {
        $permissions = $this->seedPermissions();
        $roles = $this->seedRoles();

        $this->seedDefaultRolePermissions($roles, $permissions);
        $this->applyLegacyOverrides($roles, $permissions);
        $this->backfillUserRoleIds($roles);
    }

    /** @return array<string, Permission> keyed by permission key */
    private function seedPermissions(): array
    {
        $permissions = [];

        foreach (self::CATALOGUE as $module => $defs) {
            foreach ($defs as $def) {
                $permissions[$def['key']] = Permission::updateOrCreate(
                    ['key' => $def['key']],
                    ['label' => $def['label'], 'module' => $module, 'description' => $def['description']],
                );
            }
        }

        return $permissions;
    }

    /** @return array<string, Role> keyed by slug */
    private function seedRoles(): array
    {
        $roles = [];

        foreach (self::SYSTEM_ROLES as $slug => $def) {
            $roles[$slug] = Role::updateOrCreate(
                ['slug' => $slug],
                ['name' => $def['name'], 'description' => $def['description'], 'is_system' => true, 'is_active' => true],
            );
        }

        return $roles;
    }

    /**
     * @param  array<string, Role>  $roles
     * @param  array<string, Permission>  $permissions
     */
    private function seedDefaultRolePermissions(array $roles, array $permissions): void
    {
        foreach (self::ROLE_DEFAULTS as $slug => $keys) {
            $role = $roles[$slug];
            $ids = collect($keys)->map(fn ($key) => $permissions[$key]->id)->all();
            $role->permissions()->syncWithoutDetaching($ids);
            $role->flushPermissionCache();
        }

        // Super Admin: every permission, always.
        $superAdmin = $roles['super_admin'];
        $superAdmin->permissions()->syncWithoutDetaching(collect($permissions)->pluck('id')->all());
        $superAdmin->flushPermissionCache();
    }

    /**
     * Re-apply explicit overrides from the legacy `role_permissions` table on
     * top of the seeded defaults (an `enabled = false` row removes the
     * permission; `enabled = true` adds it).
     *
     * @param  array<string, Role>  $roles
     * @param  array<string, Permission>  $permissions
     */
    private function applyLegacyOverrides(array $roles, array $permissions): void
    {
        foreach (RolePermission::all() as $override) {
            $newKey = self::LEGACY_KEY_MAP[$override->permission] ?? null;

            if ($newKey === null || ! isset($roles[$override->role], $permissions[$newKey])) {
                continue;
            }

            $role = $roles[$override->role];
            $permission = $permissions[$newKey];

            if ($override->enabled) {
                $role->permissions()->syncWithoutDetaching([$permission->id]);
            } else {
                $role->permissions()->detach($permission->id);
            }

            $role->flushPermissionCache();
        }
    }

    /** @param  array<string, Role>  $roles */
    private function backfillUserRoleIds(array $roles): void
    {
        foreach (User::whereNull('role_id')->get() as $user) {
            $slug = $user->role?->value;

            if ($slug !== null && isset($roles[$slug])) {
                $user->forceFill(['role_id' => $roles[$slug]->id])->save();
            }
        }
    }
}
