<?php

namespace App\Services\Navigation;

use App\Models\AttendanceRegularisation;
use App\Models\HolidayWorkRequest;
use App\Models\LeaveRequest;
use App\Models\OtRequest;
use App\Models\ReviewParticipant;
use App\Models\User;
use App\Models\WfhRequest;
use App\Services\AiAssistant;
use App\Services\Help\RouteAccess;
use Closure;
use Illuminate\Support\Facades\Route;

/**
 * The staff navigation, built per user from what they can actually open.
 *
 * An item shows only when (1) its route's own middleware would let the user
 * through (RouteAccess: `role:`, `can:`, `module:`) and (2) its `when` check —
 * the page's own mount-time guard, or the data scope it needs — passes. So a
 * menu link never leads to a 403, and a module the user cannot reach never
 * appears. Groups with nothing visible are dropped, which is what makes each
 * role's sidebar its own: a manager gets My Work / Team / Performance, Finance
 * gets Payroll, HR gets People / Attendance / Leave, and so on — from
 * permissions, not role names.
 *
 * Employees with nothing beyond self-service keep the admin-configurable
 * EmployeeMenu (Settings → Sidebar Menu); see isSelfServiceOnly().
 */
class Sidebar
{
    /** Display order of the groups. */
    private const ORDER = ['workspace', 'my-work', 'approvals', 'people', 'attendance', 'leave', 'payroll', 'performance', 'reports', 'inbox', 'settings'];

    /** @var array<int, array<string, mixed>>|null */
    private ?array $built = null;

    private ?User $builtFor = null;

    public function __construct(
        private readonly RouteAccess $routeAccess,
        private readonly DashboardLanding $landing,
    ) {}

    /**
     * Visible groups for the user (main navigation), each with visible items.
     *
     * @return array<int, array{key: string, heading: ?string, icon: string, items: array<int, array<string, mixed>>, expanded: bool}>
     */
    public function groups(User $user): array
    {
        return array_values(array_filter($this->build($user), fn (array $g) => $g['key'] !== 'settings'));
    }

    /** The Settings group (rendered at the bottom of the rail), or null. */
    public function settings(User $user): ?array
    {
        return collect($this->build($user))->firstWhere('key', 'settings');
    }

    /**
     * True when the user can open nothing beyond their own self-service
     * pages — they get the employee menu instead of the staff workspace.
     */
    public function isSelfServiceOnly(User $user): bool
    {
        foreach ($this->build($user) as $group) {
            foreach ($group['items'] as $item) {
                if ($item['manages']) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Where a page sits in this user's sidebar, in the words on screen —
     * "Approvals → Team Leave", or just "Inbox" for a top-level item. Null
     * when the page is not in their menu (so help never points at a menu item
     * the reader does not have).
     */
    public function menuPath(User $user, string $route): ?string
    {
        $groups = $this->build($user);

        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                if ($item['route'] !== $route) {
                    continue;
                }

                // Groups with one item (and the heading-less group) render flat.
                return $group['heading'] === null || count($group['items']) === 1
                    ? $item['label']
                    : $group['heading'].' → '.$item['label'];
            }
        }

        return null;
    }

    /**
     * Every visible page link, flattened (feeds the search palette; report
     * downloads are left out).
     *
     * @return array<int, array{label: string, url: string, caption: string}>
     */
    public function links(User $user): array
    {
        return collect($this->build($user))
            ->flatMap(fn (array $g) => collect($g['items'])->where('navigate', true)->map(fn (array $i) => [
                'label' => $i['label'],
                'url' => $i['url'],
                'caption' => $g['heading'] ?? 'Navigation',
            ]))
            ->unique('url')
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function build(User $user): array
    {
        if ($this->built !== null && $this->builtFor?->is($user)) {
            return $this->built;
        }

        $groups = [];
        // "Dashboard" already opens this page, so its own link would repeat it.
        $landing = $this->landing->route($user);

        foreach ($this->catalogue() as $group) {
            $items = [];

            foreach ($group['items'] as $item) {
                if (($item['landing'] ?? false) && in_array($landing, $item['active'] ?? [$item['route']], true)) {
                    continue;
                }

                if ($item['route'] === 'dashboard' && $landing !== null) {
                    $item['active'] = ['dashboard', $landing];
                }

                if (! $this->visible($user, $item)) {
                    continue;
                }

                $badge = isset($item['badge']) ? ($item['badge'])($user) : 0;

                if (($item['hideWhenZero'] ?? false) && $badge === 0) {
                    continue;
                }

                $items[] = [
                    'label' => $item['label'],
                    'route' => $item['route'],
                    'url' => route($item['route']),
                    'icon' => $item['icon'] ?? null,
                    'active' => $item['active'] ?? [$item['route']],
                    'badge' => $badge > 0 ? ($badge > 9 ? '9+' : (string) $badge) : null,
                    'badgeColor' => $item['badgeColor'] ?? 'amber',
                    'navigate' => ! ($item['download'] ?? false),
                    'manages' => $item['manages'] ?? true,
                ];
            }

            if ($items === []) {
                continue;
            }

            $groups[] = [
                'key' => $group['key'],
                'heading' => $group['heading'],
                'icon' => $group['icon'],
                'items' => $items,
                'expanded' => $group['expanded'] ?? collect($items)->contains(fn (array $i) => request()->routeIs(...$i['active'])),
            ];
        }

        usort($groups, fn (array $a, array $b) => array_search($a['key'], self::ORDER, true) <=> array_search($b['key'], self::ORDER, true));

        $this->builtFor = $user;

        return $this->built = $groups;
    }

    /** @param  array<string, mixed>  $item */
    private function visible(User $user, array $item): bool
    {
        if (! Route::has($item['route']) || ! $this->routeAccess->allows($user, $item['route'])) {
            return false;
        }

        /** @var Closure(User): bool|null $when */
        $when = $item['when'] ?? null;

        return $when === null || $when($user);
    }

    /**
     * The full menu. `manages` = false marks self-service / company-wide
     * reference pages that everyone may have; anything else is staff work.
     *
     * @return array<int, array<string, mixed>>
     */
    private function catalogue(): array
    {
        $self = ['manages' => false];
        $companyWide = fn (User $u): bool => $u->isCompanyWideApprover();
        $hasEmployee = fn (User $u): bool => $u->employee !== null;

        return [
            [
                'key' => 'workspace', 'heading' => 'Workspace', 'icon' => 'squares-2x2', 'expanded' => true,
                'items' => [
                    ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'squares-2x2', ...$self],
                    ['label' => 'Executive View', 'route' => 'dashboard.executive', 'icon' => 'chart-bar-square', 'landing' => true,
                        'active' => ['dashboard.executive', 'dashboard.director'],
                        'when' => fn (User $u) => $u->isCompanyWideApprover()],
                    ['label' => 'Department View', 'route' => 'dashboard.department', 'icon' => 'building-office', 'landing' => true,
                        'when' => fn (User $u) => DashboardLanding::isDepartmentLevel($u)],
                    ['label' => 'Team View', 'route' => 'dashboard.manager', 'icon' => 'presentation-chart-line', 'landing' => true],
                    ['label' => 'Finance View', 'route' => 'dashboard.finance', 'icon' => 'banknotes', 'landing' => true],
                ],
            ],
            [
                'key' => 'approvals', 'heading' => 'Approvals', 'icon' => 'check-badge',
                'items' => [
                    ['label' => 'Command Center', 'route' => 'attendance.command-center', 'badge' => fn (User $u) => $this->pendingApprovals($u)],
                    ['label' => 'Team Attendance', 'route' => 'attendance.team'],
                    ['label' => 'Team Leave', 'route' => 'time-off.team'],
                    ['label' => 'Overtime Requests', 'route' => 'overtime.manage'],
                    ['label' => 'Nexflow Overtime', 'route' => 'overtime.nexflow'],
                    ['label' => 'WFH Requests', 'route' => 'wfh.manage'],
                    ['label' => 'Attendance Exceptions', 'route' => 'attendance.exceptions', ...$self,
                        'when' => fn (User $u) => $u->hasPermission('monitor_attendance_exceptions')],
                ],
            ],
            [
                'key' => 'people', 'heading' => 'People', 'icon' => 'users',
                'items' => [
                    ['label' => 'Manage Employees', 'route' => 'employees.index', 'active' => ['employees.index', 'employees.edit', 'employees.profile', 'employees.create'],
                        'when' => fn (User $u) => $u->canManageEmployees()],
                    ['label' => 'Import Employees', 'route' => 'employees.import'],
                    ['label' => 'Teams', 'route' => 'employees.teams'],
                    ['label' => 'Onboarding', 'route' => 'employees.onboarding-manager', 'active' => ['employees.onboarding-manager', 'employees.onboarding']],
                    ['label' => 'Offboarding', 'route' => 'employees.offboarding-manager', 'active' => ['employees.offboarding-manager', 'employees.offboarding']],
                    ['label' => 'Assets', 'route' => 'operations.assets'],
                    ['label' => 'Directory', 'route' => 'employees.directory', ...$self],
                    ['label' => 'Org Chart', 'route' => 'employees.org-chart', ...$self],
                ],
            ],
            [
                'key' => 'attendance', 'heading' => 'Attendance', 'icon' => 'clock',
                'items' => [
                    // Attendance administration: the routes only need approve-leave (so
                    // a manager could open them over their team), but for a manager they
                    // repeat Team Attendance — they belong to those who manage attendance.
                    ['label' => 'All Attendance', 'route' => 'attendance.employees',
                        'when' => fn (User $u) => $u->hasPermission('manage_attendance')],
                    ['label' => 'Attendance Reports', 'route' => 'attendance.reports',
                        'when' => fn (User $u) => $u->hasPermission('manage_attendance') || $u->hasPermission('view_reports')],
                    ['label' => 'Attendance Overview', 'route' => 'attendance.executive',
                        'when' => fn (User $u) => $u->hasPermission('manage_attendance')],
                    ['label' => 'Biometric Summary', 'route' => 'attendance.biometric-summary',
                        'when' => fn (User $u) => $u->hasPermission('manage_biometric')],
                    ['label' => 'Biometric Control', 'route' => 'attendance.biometric-control',
                        'when' => fn (User $u) => $u->hasPermission('manage_biometric')],
                    ['label' => 'OT Windows', 'route' => 'overtime.windows'],
                    ['label' => 'Attendance Settings', 'route' => 'attendance.settings'],
                ],
            ],
            [
                'key' => 'leave', 'heading' => 'Leave', 'icon' => 'calendar-days',
                'items' => [
                    ['label' => 'All Leave', 'route' => 'time-off.employees'],
                    ['label' => 'Leave Management', 'route' => 'time-off.leave-management',
                        'active' => ['time-off.leave-management*', 'time-off.year-rollover', 'time-off.reconciliation']],
                    ['label' => 'Encashments', 'route' => 'time-off.encashments',
                        'when' => fn (User $u) => $u->canApproveLeave() || $u->canApproveFinance()],
                    ['label' => 'Regularisation', 'route' => 'time-off.regularisation'],
                    ['label' => 'Historical Balances', 'route' => 'time-off.historical-balances'],
                    ['label' => 'Carry Forward', 'route' => 'time-off.carry-forward'],
                    ['label' => 'Bulk Leave', 'route' => 'time-off.bulk-assign'],
                    ['label' => 'Leave Policies', 'route' => 'time-off.leave-policies'],
                    ['label' => 'Leave Settings', 'route' => 'time-off.settings'],
                ],
            ],
            [
                'key' => 'payroll', 'heading' => 'Payroll', 'icon' => 'banknotes',
                'items' => [
                    ['label' => 'Overview', 'route' => 'payroll.overview'],
                    ['label' => 'Run Payroll', 'route' => 'payroll.process'],
                    ['label' => 'Finance Approval', 'route' => 'payroll.finance-approve'],
                    ['label' => 'Incentives', 'route' => 'payroll.incentives'],
                    ['label' => 'Reimbursements', 'route' => 'payroll.reimbursements'],
                    ['label' => 'Components', 'route' => 'payroll.components'],
                    ['label' => 'Salary Structures', 'route' => 'payroll.structures'],
                    ['label' => 'Historical Import', 'route' => 'payroll.historical-import'],
                    ['label' => 'Payroll Audit Trail', 'route' => 'payroll.audit-trail',
                        'when' => fn (User $u) => $u->hasPermission('view_payroll')],
                ],
            ],
            [
                'key' => 'performance', 'heading' => 'Performance', 'icon' => 'arrow-trending-up',
                'items' => [
                    ['label' => 'Team Reviews', 'route' => 'performance.team'],
                    ['label' => 'All Reviews', 'route' => 'performance.employees'],
                    ['label' => 'Review Cycles', 'route' => 'performance.cycles',
                        'when' => fn (User $u) => $u->hasPermission('manage_review_cycles')],
                    ['label' => 'Increment Center', 'route' => 'performance.increments'],
                    ['label' => 'KPI Dashboard', 'route' => 'performance.kpi-dashboard',
                        'when' => fn (User $u) => $u->hasPermission('manage_scorecards')],
                    ['label' => 'KPI Templates', 'route' => 'performance.kpi-templates',
                        'when' => fn (User $u) => $u->hasPermission('manage_kpi_templates')],
                    ['label' => 'Warning Letters', 'route' => 'performance.warnings.manage',
                        'when' => fn (User $u) => $u->hasPermission('manage_warning_letters')],
                    ['label' => 'Improvement Plans', 'route' => 'performance.pip.manage',
                        'when' => fn (User $u) => $u->hasPermission('manage_pip')],
                    ['label' => 'Manage Promotions', 'route' => 'performance.promotions.manage',
                        'when' => fn (User $u) => $u->hasPermission('manage_promotions')],
                ],
            ],
            [
                'key' => 'reports', 'heading' => 'Reports', 'icon' => 'document-chart-bar', 'expanded' => false,
                'items' => [
                    ...array_map(fn (array $r) => [...$r, 'download' => true], [
                        ['label' => 'Payroll Summary', 'route' => 'reports.payroll-summary'],
                        ['label' => 'Payroll Register', 'route' => 'reports.payroll-register'],
                        ['label' => 'Salary Register', 'route' => 'reports.salary-register'],
                        ['label' => 'Bank Transfer', 'route' => 'reports.bank-transfer'],
                        ['label' => 'PF Report', 'route' => 'reports.pf-report'],
                        ['label' => 'ESI Report', 'route' => 'reports.esi-report'],
                        ['label' => 'Professional Tax Report', 'route' => 'reports.pt-report'],
                        ['label' => 'TDS Report', 'route' => 'reports.tds-report'],
                        ['label' => 'Cost Center Report', 'route' => 'reports.cost-center-report'],
                        ['label' => 'Department Payroll Report', 'route' => 'reports.department-payroll-report'],
                        ['label' => 'Payroll Monthly Summary', 'route' => 'reports.payroll-monthly-summary'],
                        ['label' => 'Payroll Yearly Summary', 'route' => 'reports.payroll-yearly-summary'],
                        ['label' => 'Payroll Variance Report', 'route' => 'reports.payroll-variance-report'],
                        // Company-wide exports: the controller refuses scoped viewers.
                        ['label' => 'Attendance Summary', 'route' => 'reports.attendance-summary', 'when' => $companyWide],
                        ['label' => 'Leave Utilization', 'route' => 'reports.leave-utilization', 'when' => $companyWide],
                        ['label' => 'Leave Encashments', 'route' => 'reports.leave-encashment-report', 'when' => $companyWide],
                        ['label' => 'Attendance Compliance', 'route' => 'reports.attendance-compliance', 'when' => $companyWide],
                        ['label' => 'Overtime Records', 'route' => 'reports.ot-records', 'when' => $companyWide],
                        ['label' => 'Performance Summary', 'route' => 'reports.performance-summary', 'when' => $companyWide],
                        ['label' => 'KPI Summary', 'route' => 'reports.kpi-summary', 'when' => $companyWide],
                        ['label' => 'Department Performance', 'route' => 'reports.department-performance', 'when' => $companyWide],
                        ['label' => 'Promotion Pipeline', 'route' => 'reports.promotion-pipeline', 'when' => $companyWide],
                        ['label' => 'Warning Letters Report', 'route' => 'reports.warning-letter-report', 'when' => $companyWide],
                        ['label' => 'PIP Progress', 'route' => 'reports.pip-progress', 'when' => $companyWide],
                        ['label' => 'Employee Lifecycle', 'route' => 'reports.employee-lifecycle', 'when' => $companyWide],
                    ]),
                ],
            ],
            [
                'key' => 'my-work', 'heading' => 'My Work', 'icon' => 'user-circle',
                'items' => [
                    ['label' => 'My Attendance', 'route' => 'attendance.my', ...$self, 'when' => $hasEmployee],
                    ['label' => 'My Leave', 'route' => 'time-off.my', ...$self, 'when' => $hasEmployee],
                    ['label' => 'My Overtime', 'route' => 'overtime.my', ...$self, 'when' => $hasEmployee],
                    ['label' => 'My WFH', 'route' => 'wfh.my', ...$self, 'when' => $hasEmployee],
                    ['label' => 'My Payslips', 'route' => 'payroll.payslips', ...$self, 'when' => $hasEmployee],
                    ['label' => 'Expense Claims', 'route' => 'operations.expenses', ...$self],
                    ['label' => 'Holidays', 'route' => 'holidays.calendar', ...$self],
                    ['label' => 'Documents', 'route' => 'documents.index', 'active' => ['documents.*'], ...$self],
                    ['label' => 'My Performance', 'route' => 'performance.dashboard', ...$self, 'when' => $hasEmployee],
                    ['label' => 'My Review', 'route' => 'performance.my', ...$self, 'when' => $hasEmployee],
                    ['label' => 'Review Tasks', 'route' => 'performance.review-tasks', ...$self, 'hideWhenZero' => true, 'badgeColor' => 'violet',
                        'badge' => fn (User $u) => ReviewParticipant::where('reviewer_id', $u->id)->where('status', 'pending')->count()],
                    ['label' => 'My Goals & KPIs', 'route' => 'performance.goals', 'active' => ['performance.goals', 'performance.my-kpis'], ...$self, 'when' => $hasEmployee],
                ],
            ],
            [
                'key' => 'inbox', 'heading' => null, 'icon' => 'inbox',
                'items' => [
                    ['label' => 'Inbox', 'route' => 'notifications.index', 'icon' => 'inbox', 'active' => ['notifications.*'], 'badgeColor' => 'red', ...$self,
                        'badge' => fn (User $u) => $u->unreadNotifications()->count()],
                    ['label' => 'AI Assistant', 'route' => 'ai.assistant', 'icon' => 'sparkles', ...$self,
                        'when' => fn (User $u) => app(AiAssistant::class)->enabledForUser($u)],
                ],
            ],
            [
                'key' => 'settings', 'heading' => 'Settings', 'icon' => 'cog-6-tooth',
                'items' => [
                    ['label' => 'Control Panel', 'route' => 'settings.control-panel'],
                    ['label' => 'General', 'route' => 'settings.general'],
                    ['label' => 'Roles & Permissions', 'route' => 'settings.roles', 'when' => fn (User $u) => $u->hasPermission('manage_roles')],
                    ['label' => 'Departments', 'route' => 'settings.departments'],
                    ['label' => 'Employment Types', 'route' => 'settings.employment-types'],
                    ['label' => 'Work Modes', 'route' => 'settings.work-modes'],
                    ['label' => 'Salary Cycles', 'route' => 'settings.salary-cycles'],
                    ['label' => 'Payroll Approval Policy', 'route' => 'settings.payroll-approval-policy'],
                    ['label' => 'Modules', 'route' => 'settings.modules'],
                    ['label' => 'Job Titles', 'route' => 'settings.job-titles'],
                    ['label' => 'Notifications & Email', 'route' => 'settings.notifications'],
                    ['label' => 'Sidebar Menu', 'route' => 'settings.menu'],
                    ['label' => 'Activity Log', 'route' => 'settings.audit-log',
                        'when' => fn (User $u) => $u->hasPermission('view_audit_log') || $u->canManageSettings()],
                    ['label' => 'Import / Export', 'route' => 'settings.import-export',
                        'when' => fn (User $u) => $u->hasPermission('data_export') || $u->hasPermission('data_import')],
                    ['label' => 'Data Management', 'route' => 'settings.data-management', 'when' => fn (User $u) => $u->hasPermission('data_purge')],
                ],
            ],
        ];
    }

    /**
     * The Command Center's own "pending" total for this user — the same
     * statuses over the same reach — so the badge matches the page (it used
     * to count the whole company for every approver).
     */
    private function pendingApprovals(User $user): int
    {
        $ids = $user->accessibleEmployeeIds();

        return collect([AttendanceRegularisation::class, LeaveRequest::class, WfhRequest::class, OtRequest::class, HolidayWorkRequest::class])
            ->sum(fn (string $model) => $model::query()
                ->where('status', 'pending')
                ->when($ids !== null, fn ($q) => $q->whereIn('employee_id', $ids))
                ->count());
    }
}
