<?php

namespace App\Services\People;

use App\Models\Employee;
use App\Models\User;
use App\Services\Ai\AiDataTools;
use App\Services\Security\PeopleScope;

/**
 * Who may open whose Employee 360° page, and which sections of it they see.
 *
 * The page itself needs View Employee 360° covering the employee (Super Admin
 * and HR: company; Director / Department Head: department; Manager: team;
 * anyone else only when granted). Each section then needs the permission its
 * own module uses, covering the same employee — so the page never shows more
 * than Team View, Command Center, Team Leave, Performance or the AI
 * assistant would.
 */
class Employee360Access
{
    public const PERMISSION = 'view_employee_360';

    public function __construct(private PeopleScope $people) {}

    /**
     * Employee ids the user may open — NULL means every employee. The user's
     * own record is included only when they hold the permission.
     *
     * @return array<int, int>|null
     */
    public function reach(User $user): ?array
    {
        if (! $user->hasPermission(self::PERMISSION)) {
            return [];
        }

        return $this->people->employeeIds($user, self::PERMISSION, includeSelf: true);
    }

    public function allows(User $user, Employee $employee): bool
    {
        if (! $user->hasPermission(self::PERMISSION)) {
            return false;
        }

        return $this->isSelf($user, $employee) || $this->people->covers($user, self::PERMISSION, $employee);
    }

    /** A closure for lists: fn (int $employeeId): bool — one scope lookup for the whole list. */
    public function checker(User $user): \Closure
    {
        $reach = $this->reach($user);

        return fn (int $employeeId): bool => $reach === null || in_array($employeeId, $reach, true);
    }

    /**
     * Sections the user sees for this employee. Each one uses the permission
     * of the module the numbers come from, in the same scope.
     *
     * @return array{attendance: bool, attendance_page: bool, leave: bool, decide_leave: bool, overtime: bool, payable_overtime: bool, performance: bool, warnings: bool, activity: bool, full_activity: bool, contact: bool, profile: bool, edit: bool, leave_detail: bool, warnings_page: bool, export: bool}
     */
    public function sections(User $user, Employee $employee): array
    {
        $self = $this->isSelf($user, $employee);
        $covers = fn (string $permission): bool => $user->hasPermission($permission)
            && ($self || $this->people->covers($user, $permission, $employee));
        $any = fn (string ...$permissions): bool => collect($permissions)->contains(fn ($p) => $covers($p));

        $sections = [
            'attendance' => $any('view_attendance', 'manage_attendance'),
            'leave' => $any('view_leave_management', 'approve_leave'),
            'decide_leave' => ! $self && $covers('approve_leave'),
            'overtime' => $any('view_overtime', 'approve_overtime', 'manage_overtime'),
            // Pay is payroll's: the approver of the hours does not see the money.
            'payable_overtime' => $self ? app(AiDataTools::class)->maySeeOwnPay($user) : $covers('view_payroll'),
            'performance' => $any('view_performance', 'review_performance'),
            'warnings' => $covers('manage_warning_letters'),
            'contact' => $self || $any('view_employee', 'manage_employees', 'edit_employee'),
            'profile' => ! $self && $user->canManageEmployees() && $covers('manage_employees'),
            'edit' => ! $self && $user->can('update', $employee),
            'leave_detail' => $covers('view_leave_management'),
            'warnings_page' => $user->canManageEmployees() && $covers('manage_warning_letters'),
            'full_activity' => $user->hasPermission('view_audit_log') || $user->canManageSettings(),
        ];

        // Links only where the target page would open for this user.
        $sections['attendance_page'] = $sections['attendance'] && $user->canApproveLeave();

        $sections['activity'] = $sections['full_activity']
            || $sections['attendance'] || $sections['leave'] || $sections['overtime']
            || $sections['performance'] || $sections['warnings'] || $sections['contact'];
        $sections['export'] = $this->allows($user, $employee);

        return $sections;
    }

    /** "UK Operations · Department scope" — whose records this user is looking across. */
    public function scopeLabel(User $user): string
    {
        $summary = $this->people->summary($user, self::PERMISSION);
        $scope = $this->people->scope($user, self::PERMISSION);

        return match ($summary['kind']) {
            'company' => 'All employees · Company scope',
            'department' => $summary['label'].' · Department scope',
            'team' => 'My team · Team scope',
            'own' => 'My record · Own scope',
            default => $scope->label(),
        };
    }

    private function isSelf(User $user, Employee $employee): bool
    {
        return (int) $employee->user_id === (int) $user->id;
    }
}
