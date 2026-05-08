<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\RolePermission;
use Illuminate\Support\Facades\Cache;

class RolePermissionService
{
    /** All configurable permissions with labels and descriptions. */
    public static array $definitions = [
        'manage-employees' => ['label' => 'Manage Employees', 'desc' => 'Create, edit and view all employee records', 'icon' => 'users'],
        'approve-leave' => ['label' => 'Approve Leave',    'desc' => 'Review and approve / reject leave requests', 'icon' => 'calendar-days'],
        'approve-ot' => ['label' => 'Approve Overtime', 'desc' => 'Review and approve overtime pre-approval requests', 'icon' => 'clock'],
        'run-payroll' => ['label' => 'Run Payroll',      'desc' => 'Process payroll cycles and manage components', 'icon' => 'banknotes'],
        'approve-finance' => ['label' => 'Finance Approval', 'desc' => 'Sign off payroll for disbursement', 'icon' => 'check-badge'],
        'manage-settings' => ['label' => 'Manage Settings',  'desc' => 'Configure company-wide system settings', 'icon' => 'cog-6-tooth'],
        'manage-documents' => ['label' => 'Manage Documents', 'desc' => 'Upload and manage HR documents for employees', 'icon' => 'document-text'],
        'view-reports' => ['label' => 'View Reports',     'desc' => 'Download attendance, OT and payroll reports', 'icon' => 'chart-bar'],
    ];

    /** Roles that can be configured (Super Admin is always locked). */
    public static array $configurableRoles = [
        'hr_admin' => 'HR Admin',
        'director' => 'Director',
        'manager' => 'Manager',
        'finance' => 'Finance',
        'employee' => 'Employee',
    ];

    /** Default permissions per role (mirrors current hardcoded enum logic). */
    public static array $defaults = [
        'hr_admin' => ['manage-employees', 'approve-leave', 'approve-ot', 'run-payroll', 'manage-settings', 'manage-documents', 'view-reports'],
        'director' => ['manage-employees', 'approve-leave', 'approve-ot', 'approve-finance', 'view-reports'],
        'manager' => ['approve-leave', 'approve-ot'],
        'finance' => ['run-payroll', 'approve-finance', 'view-reports'],
        'employee' => [],
    ];

    /** Check if a role has a given permission (DB first, enum fallback, SA always true). */
    public static function check(?string $role, string $permission): bool
    {
        if (! $role) {
            return false;
        }

        // Super Admin always has every permission
        if ($role === UserRole::SuperAdmin->value) {
            return true;
        }

        $map = Cache::remember("role_perms_{$role}", 300, function () use ($role) {
            return RolePermission::where('role', $role)
                ->pluck('enabled', 'permission')
                ->toArray();
        });

        // If DB has an explicit row, honour it
        if (array_key_exists($permission, $map)) {
            return (bool) $map[$permission];
        }

        // Otherwise fall back to defaults
        return in_array($permission, self::$defaults[$role] ?? [], true);
    }

    /** Flush permission cache for a role. */
    public static function flush(string $role): void
    {
        Cache::forget("role_perms_{$role}");
    }

    /** Get the full permission matrix (all roles × all permissions). */
    public static function matrix(): array
    {
        $rows = RolePermission::all()->groupBy('role');
        $matrix = [];

        foreach (self::$configurableRoles as $role => $label) {
            $dbPerms = $rows->get($role, collect())->pluck('enabled', 'permission')->toArray();
            $matrix[$role] = [];
            foreach (array_keys(self::$definitions) as $perm) {
                $matrix[$role][$perm] = array_key_exists($perm, $dbPerms)
                    ? (bool) $dbPerms[$perm]
                    : in_array($perm, self::$defaults[$role] ?? [], true);
            }
        }

        return $matrix;
    }
}
