<?php

namespace App\Services\Help;

use App\Models\User;
use App\Services\Navigation\DashboardLanding;
use App\Services\Navigation\Sidebar;
use Closure;

/**
 * The role journeys of Help & Guide (/help/employee-guide).
 *
 * Every section names the routes it is about (`requires`). A section is shown
 * only when the reader can open all of them (RouteAccess — the same check the
 * sidebar uses), and a journey only when at least one of its sections is. So
 * the guide never documents a page or action the reader cannot reach. A
 * journey also has an `audience` (permissions, never a role name), so a page
 * shared by several roles (Notifications, Team Leave) does not hand an
 * employee the Manager journey.
 *
 * Steps write menu locations as {menu:route.name}; they are resolved from the
 * reader's live sidebar ("Approvals → Team Leave"), so a renamed or moved menu
 * item changes the guide with it. RoleGuidesTest fails if any referenced route
 * disappears.
 */
class RoleGuides
{
    /** Default journey when the reader has several: their main role first. */
    private const PREFERENCE = ['super-admin', 'hr-admin', 'finance', 'department-head', 'manager', 'employee'];

    public function __construct(
        private readonly RouteAccess $routeAccess,
        private readonly Sidebar $sidebar,
        private readonly EmployeeGuide $employeeGuide,
    ) {}

    /**
     * Journeys this reader has, with their visible sections.
     *
     * @return array<string, array{id: string, label: string, title: string, intro: string, sections: array<int, array<string, mixed>>}>
     */
    public function journeys(User $user): array
    {
        $journeys = [];

        foreach ($this->definitions() as $id => $journey) {
            if (isset($journey['audience']) && ! ($journey['audience'])($user)) {
                continue;
            }

            $sections = $id === 'employee'
                ? $this->employeeSections($user)
                : array_values(array_filter(array_map(fn (array $s) => $this->render($user, $s), $journey['sections'])));

            if ($sections !== []) {
                $journeys[$id] = ['id' => $id, 'label' => $journey['label'], 'title' => $journey['title'], 'intro' => $journey['intro'], 'sections' => $sections];
            }
        }

        return $journeys;
    }

    /** The journey to open first for this reader. */
    public function defaultJourney(array $journeys): ?string
    {
        foreach (self::PREFERENCE as $id) {
            if (isset($journeys[$id])) {
                return $id;
            }
        }

        return array_key_first($journeys);
    }

    /**
     * Every route any journey refers to (requires, links, menu placeholders) —
     * for the test that catches outdated links.
     *
     * @return array<int, string>
     */
    public function referencedRoutes(): array
    {
        $routes = [];

        foreach ($this->definitions() as $journey) {
            foreach ($journey['sections'] as $section) {
                $routes = [...$routes, ...($section['requires'] ?? []), ...array_column($section['links'] ?? [], 'route')];
                foreach ($section['steps'] ?? [] as $step) {
                    preg_match_all('/\{menu:([a-z0-9._-]+)\}/', $step, $m);
                    $routes = [...$routes, ...$m[1]];
                }
            }
        }

        return array_values(array_unique($routes));
    }

    /** @return array<int, array<string, mixed>> */
    private function employeeSections(User $user): array
    {
        if ($user->employee === null) {
            return [];
        }

        // An employee section with links is shown only if one of them opens.
        return array_values(array_filter(
            $this->employeeGuide->sections($user),
            fn (array $s) => $s['links'] !== [] || empty($s['requires_link']),
        ));
    }

    /** @param array<string, mixed> $section */
    private function render(User $user, array $section): ?array
    {
        foreach ($section['requires'] ?? [] as $route) {
            if (! $this->routeAccess->allows($user, $route)) {
                return null;
            }
        }

        /** @var Closure(User): bool|null $when */
        $when = $section['when'] ?? null;
        if ($when !== null && ! $when($user)) {
            return null;
        }

        $resolve = fn (string $text): string => preg_replace_callback(
            '/\{menu:([a-z0-9._-]+)\}/',
            fn (array $m) => $this->sidebar->menuPath($user, $m[1]) ?? $this->fallbackLabel($m[1]),
            $text,
        );

        return [
            'id' => $section['id'],
            'category' => $section['category'],
            'title' => $section['title'],
            'icon' => $section['icon'],
            'summary' => $resolve($section['summary']),
            'shots' => [],
            'steps' => array_map($resolve, $section['steps'] ?? []),
            'can' => array_map($resolve, $section['can'] ?? []),
            'next' => array_map($resolve, $section['next'] ?? []),
            'tips' => array_map(fn (array $t) => ['type' => $t['type'], 'text' => $resolve($t['text'])], $section['tips'] ?? []),
            'links' => collect($section['links'] ?? [])
                ->filter(fn (array $l) => $this->routeAccess->allows($user, $l['route']))
                ->map(fn (array $l) => $l + ['url' => route($l['route'])])
                ->values()->all(),
            'keywords' => $section['keywords'] ?? '',
        ];
    }

    /** A page outside the reader's menu (e.g. reached from a dashboard card). */
    private function fallbackLabel(string $route): string
    {
        return match ($route) {
            'dashboard' => 'Dashboard',
            'dashboard.finance' => 'Finance View',
            'dashboard.department' => 'Department View',
            'dashboard.manager' => 'Team View',
            default => 'the '.str_replace(['.', '-'], ' ', $route).' page',
        };
    }

    /** @return array<string, array{label: string, title: string, intro: string, audience?: Closure(User): bool, sections: array<int, array<string, mixed>>}> */
    private function definitions(): array
    {
        $deptLevel = fn (User $u): bool => DashboardLanding::isDepartmentLevel($u);
        $superAdmin = fn (User $u): bool => $u->isSuperAdmin() || $u->assignedRole?->slug === 'super_admin';

        return [
            'employee' => [
                'label' => 'Employee',
                'title' => 'Employee Guide',
                'intro' => 'Everything you can do for yourself in Pulse, step by step: from your first login to leave, attendance, payslips and reviews. Every picture is a real screen from an employee account.',
                'sections' => [],
            ],

            'manager' => [
                'label' => 'Manager',
                'title' => 'Manager Guide',
                'intro' => 'Looking after a team: today\'s attendance, leave and overtime decisions, reviews and the alerts that need you. You only ever see your own team.',
                'audience' => fn (User $u): bool => $u->canApproveLeave() || $u->hasPermission('approve_overtime'),
                'sections' => [
                    [
                        'id' => 'mgr-team-attendance', 'category' => 'Team', 'title' => 'Team attendance', 'icon' => 'clock',
                        'requires' => ['attendance.team'],
                        'summary' => 'Team Attendance shows your team\'s day as it happens, from the same status the employees see on their own page.',
                        'steps' => [
                            'Open {menu:attendance.team}.',
                            'The Live Board counts your team by today\'s status: working (in the office or from home), late, absent, missing checkout and on leave.',
                            'Someone counts as "missing checkout" only once their shift has ended and an hour has passed — never while they are still at work.',
                        ],
                        'can' => ['See who is in, late, on leave or working from home today.', 'See each person\'s first in and last out.'],
                        'tips' => [['type' => 'info', 'text' => 'Attendance corrections (regularisations) are decided by HR. Your team\'s requests show as awaiting HR.']],
                        'links' => [['route' => 'attendance.team', 'label' => 'Open Team Attendance']],
                        'keywords' => 'team attendance live board late absent working break missing checkout wfh',
                    ],
                    [
                        'id' => 'mgr-leave', 'category' => 'Approvals', 'title' => 'Approving leave', 'icon' => 'calendar-days',
                        'requires' => ['time-off.team'],
                        'summary' => 'Leave your team applies for comes to you. You decide inside your own team only, and never your own request.',
                        'steps' => [
                            'Open {menu:time-off.team}.',
                            'Open a pending request to see the dates, the leave type and the employee\'s balance.',
                            'Approve it, or reject it with a reason the employee will see.',
                        ],
                        'next' => ['The employee is notified of your decision.', 'Approved leave is taken from the employee\'s balance.'],
                        'tips' => [['type' => 'tip', 'text' => 'Pending leave also appears on your dashboard, where you can approve or reject it directly.']],
                        'links' => [['route' => 'time-off.team', 'label' => 'Open Team Leave']],
                        'keywords' => 'leave approve reject team leave request pending decision',
                    ],
                    [
                        'id' => 'mgr-overtime', 'category' => 'Approvals', 'title' => 'Approving overtime', 'icon' => 'bolt',
                        'requires' => ['overtime.manage'],
                        'summary' => 'Overtime counts only when it is approved. Requests from your team wait for you here.',
                        'steps' => [
                            'Open {menu:overtime.manage}.',
                            'Review each pending request: the date, the hours asked for and the reason.',
                            'Approve or reject it.',
                        ],
                        'next' => ['Only approved overtime is paid, through payroll.'],
                        'links' => [['route' => 'overtime.manage', 'label' => 'Open Overtime Requests']],
                        'keywords' => 'overtime ot approve reject request hours',
                    ],
                    [
                        'id' => 'mgr-regularisation', 'category' => 'Approvals', 'title' => 'Attendance corrections (regularisation)', 'icon' => 'pencil-square',
                        'requires' => ['attendance.team'],
                        'when' => fn (User $u) => ! $u->canApproveRegularisations(),
                        'summary' => 'When someone in your team asks to correct a punch or mark a half day, the request goes straight to HR, who decide it in one step.',
                        'steps' => [
                            'Open {menu:attendance.team} to see your team\'s days.',
                            'A day with a correction request shows it as awaiting HR.',
                            'Once HR approves it, the day\'s hours are recalculated automatically.',
                        ],
                        'keywords' => 'regularisation regularization correction punch half day hr',
                    ],
                    [
                        'id' => 'mgr-performance', 'category' => 'Performance', 'title' => 'Team reviews', 'icon' => 'arrow-trending-up',
                        'requires' => ['performance.team'],
                        'summary' => 'Review your team members when a review cycle is open.',
                        'steps' => ['Open {menu:performance.team} to see the reviews assigned to you.', 'Open a review, complete your part and submit it.'],
                        'links' => [['route' => 'performance.team', 'label' => 'Open Team Reviews']],
                        'keywords' => 'performance review team rating appraisal cycle',
                    ],
                    [
                        'id' => 'mgr-notifications', 'category' => 'Notifications', 'title' => 'Alerts and reminders', 'icon' => 'bell',
                        'requires' => ['notifications.index'],
                        'summary' => 'The bell at the top right and the Inbox show what needs you: new requests, escalations and reminders about your team.',
                        'steps' => ['Click the bell for the latest notifications, or open {menu:notifications.index} for all of them.', 'Switch to Reminders for upcoming document expiries, probation reviews and escalations for the people you look after.'],
                        'links' => [['route' => 'notifications.index', 'label' => 'Open Inbox']],
                        'keywords' => 'notifications inbox bell reminders escalations probation documents',
                    ],
                ],
            ],

            'department-head' => [
                'label' => 'Department Head',
                'title' => 'Department Head Guide',
                'intro' => 'Running a department: today\'s attendance, leave and overtime across it, reports and reviews — for the departments you head, and nothing beyond them.',
                'audience' => $deptLevel,
                'sections' => [
                    [
                        'id' => 'dh-dashboard', 'category' => 'Department', 'title' => 'Your department dashboard', 'icon' => 'building-office',
                        'requires' => ['dashboard.department'], 'when' => $deptLevel,
                        'summary' => 'Dashboard opens your department view: everyone in the departments you head or are responsible for, named at the top.',
                        'steps' => [
                            'Click Dashboard (or {menu:dashboard.department}).',
                            'The cards show who is present, late and absent today.',
                            'Below: today\'s attendance for each person, pending leave and overtime to decide, your department\'s overtime this month, who is on leave this week, outstanding reviews and KPI scores.',
                        ],
                        'links' => [['route' => 'dashboard.department', 'label' => 'Open Department View']],
                        'keywords' => 'department dashboard head present late absent overtime leave reviews kpi',
                    ],
                    [
                        'id' => 'dh-attendance', 'category' => 'Department', 'title' => 'Department attendance', 'icon' => 'clock',
                        'requires' => ['dashboard.department'], 'when' => $deptLevel,
                        'summary' => 'The attendance table on your department view lists everyone in your departments with today\'s status, from the same calculation every attendance screen uses.',
                        'steps' => ['Open your department view.', 'Find the attendance table: first in, last out and today\'s status per person.', 'A missing checkout is flagged only after the shift has ended plus an hour.'],
                        'keywords' => 'department attendance status missing checkout present absent late',
                    ],
                    [
                        'id' => 'dh-leave', 'category' => 'Approvals', 'title' => 'Department leave', 'icon' => 'calendar-days',
                        'requires' => ['time-off.team'],
                        'summary' => 'Leave requests from your department wait for your decision.',
                        'steps' => ['Open {menu:time-off.team}, or use the leave card on your department view.', 'Approve, or reject with a reason.'],
                        'next' => ['The employee is notified of your decision.'],
                        'links' => [['route' => 'time-off.team', 'label' => 'Open Team Leave']],
                        'keywords' => 'department leave approve reject',
                    ],
                    [
                        'id' => 'dh-reports', 'category' => 'Reports', 'title' => 'Department reports', 'icon' => 'document-chart-bar',
                        'requires' => ['attendance.reports'],
                        'when' => fn (User $u) => $u->hasPermission('view_reports') || $u->hasPermission('manage_attendance'),
                        'summary' => 'Attendance reports cover only the people in your reach — your departments, not the whole company.',
                        'steps' => ['Open {menu:attendance.reports}.', 'Choose the report and the period.', 'Download it.'],
                        'links' => [['route' => 'attendance.reports', 'label' => 'Open Attendance Reports']],
                        'keywords' => 'reports attendance department export download',
                    ],
                    [
                        'id' => 'dh-performance', 'category' => 'Performance', 'title' => 'Department reviews', 'icon' => 'arrow-trending-up',
                        'requires' => ['performance.team'], 'when' => $deptLevel,
                        'summary' => 'Review the people in your department when a review cycle is open.',
                        'steps' => ['Open {menu:performance.team} to see the reviews assigned to you.', 'Open a review, complete your part and submit it.'],
                        'links' => [['route' => 'performance.team', 'label' => 'Open Team Reviews']],
                        'keywords' => 'department performance review rating',
                    ],
                ],
            ],

            'hr-admin' => [
                'label' => 'HR Admin',
                'title' => 'HR Admin Guide',
                'intro' => 'Running people operations: employee records, departments, attendance and corrections, leave balances, onboarding and offboarding, documents, roles and reports. If your access is limited to some departments, every page shows only those.',
                'audience' => fn (User $u): bool => $u->canManageEmployees() || $u->hasPermission('manage_attendance'),
                'sections' => [
                    [
                        'id' => 'hr-employees', 'category' => 'People', 'title' => 'Employee records', 'icon' => 'users',
                        'requires' => ['employees.index'], 'when' => fn (User $u) => $u->canManageEmployees(),
                        'summary' => 'Manage Employees lists everyone in your reach, with filters and the queue of profiles missing HR details.',
                        'steps' => [
                            'Open {menu:employees.index}.',
                            'Filter by office, department, job title, status or login invitation, or search by name, email or employee ID.',
                            'Open a person to edit their record; use Add employee for a new hire.',
                            'Use the incomplete-profile filter to work through missing department, manager, shift and joining details.',
                        ],
                        'can' => ['Create, edit and archive employee records.', 'Send a login invitation once a record is ready.', 'Restore a deleted employee with their history.'],
                        'links' => [['route' => 'employees.index', 'label' => 'Open Manage Employees']],
                        'keywords' => 'employees records add employee invite archive restore incomplete profile filter',
                    ],
                    [
                        'id' => 'hr-departments', 'category' => 'People', 'title' => 'Departments and heads', 'icon' => 'building-office',
                        'requires' => ['settings.departments'],
                        'summary' => 'Departments organise people and decide what department-level roles reach.',
                        'steps' => [
                            'Open {menu:settings.departments}.',
                            'Add a department, or edit one to change its name, code, head or default overtime source.',
                            'Choosing a Head gives that person department-level reach over everyone in the department. The change is recorded in the activity log.',
                        ],
                        'links' => [['route' => 'settings.departments', 'label' => 'Open Departments']],
                        'keywords' => 'departments department head organisation ot source',
                    ],
                    [
                        'id' => 'hr-attendance', 'category' => 'Attendance', 'title' => 'Company attendance', 'icon' => 'clock',
                        'requires' => ['attendance.employees'],
                        'summary' => 'All Attendance shows every day for everyone in your reach; Command Center gathers the requests waiting for a decision.',
                        'steps' => ['Open {menu:attendance.employees} to search and filter attendance by person and date.', 'Open {menu:attendance.command-center} for pending requests of every kind.'],
                        'tips' => [['type' => 'warning', 'text' => 'Raw biometric punches are never edited. Corrections are applied on top through approved regularisations.']],
                        'links' => [['route' => 'attendance.employees', 'label' => 'Open All Attendance']],
                        'keywords' => 'attendance all employees command center biometric punches',
                    ],
                    [
                        'id' => 'hr-regularisation', 'category' => 'Attendance', 'title' => 'Deciding attendance corrections', 'icon' => 'pencil-square',
                        'requires' => ['attendance.command-center'], 'when' => fn (User $u) => $u->canApproveRegularisations(),
                        'summary' => 'Regularisation requests come straight to HR. Approving applies the correction in one step and the day\'s hours are recalculated.',
                        'steps' => ['Open {menu:attendance.command-center}.', 'Find the pending correction.', 'Approve or reject it, with a comment.'],
                        'next' => ['The employee is notified.', 'An approved correction can later be edited, deleted or reverted by HR, with a confirmation and a note in the activity log.'],
                        'links' => [['route' => 'attendance.command-center', 'label' => 'Open Command Center']],
                        'keywords' => 'regularisation regularization correction approve reject revert command center',
                    ],
                    [
                        'id' => 'hr-leave', 'category' => 'Leave', 'title' => 'Leave balances and overrides', 'icon' => 'calendar-days',
                        'requires' => ['time-off.leave-management'],
                        'summary' => 'Leave Management shows every balance in your reach for a leave type and year; each person\'s page has the corrections.',
                        'steps' => [
                            'Open {menu:time-off.leave-management}.',
                            'Pick the leave year and type, then filter or search.',
                            'Open a person to add, deduct or correct a balance, apply leave on their behalf, or override a policy rule — each needs its own permission.',
                            'High-impact changes ask you to tick a confirmation and give a reason; every change is in the ledger and the activity log.',
                        ],
                        'links' => [['route' => 'time-off.leave-management', 'label' => 'Open Leave Management']],
                        'keywords' => 'leave management balance add deduct correct override apply on behalf ledger carry forward',
                    ],
                    [
                        'id' => 'hr-onboarding', 'category' => 'Lifecycle', 'title' => 'Onboarding and offboarding', 'icon' => 'user-plus',
                        'requires' => ['employees.onboarding-manager'],
                        'summary' => 'Track joiners\' and leavers\' checklists in one place.',
                        'steps' => ['Open {menu:employees.onboarding-manager} for new joiners\' tasks.', 'Open {menu:employees.offboarding-manager} to record a last working day and work through the exit checklist.'],
                        'tips' => [['type' => 'warning', 'text' => 'Setting a last working day ends that person\'s access after the day passes.']],
                        'links' => [['route' => 'employees.onboarding-manager', 'label' => 'Open Onboarding']],
                        'keywords' => 'onboarding offboarding checklist joiner leaver exit last working day',
                    ],
                    [
                        'id' => 'hr-documents', 'category' => 'Documents', 'title' => 'Documents', 'icon' => 'document-text',
                        'requires' => ['documents.index'], 'when' => fn (User $u) => $u->canManageDocuments(),
                        'summary' => 'Upload company policies and employee documents, and track acknowledgements.',
                        'steps' => ['Open {menu:documents.index}.', 'Upload a document for everyone, a department or one person, and mark it for acknowledgement if needed.'],
                        'links' => [['route' => 'documents.index', 'label' => 'Open Documents']],
                        'keywords' => 'documents upload policy acknowledgement expiry',
                    ],
                    [
                        'id' => 'hr-roles', 'category' => 'Access', 'title' => 'Roles and permissions', 'icon' => 'shield-check',
                        'requires' => ['settings.roles'], 'when' => fn (User $u) => $u->hasPermission('manage_roles'),
                        'summary' => 'Decide what each role can do and whose records each permission reaches.',
                        'steps' => [
                            'Open {menu:settings.roles}.',
                            'Edit a role, tick its permissions, and for each permission that touches employee data choose its reach: inherit, own records, team, own department, selected departments or all departments.',
                            'Use User overrides to give one person an exception — grant, narrow or revoke a single permission — and see their effective permissions.',
                        ],
                        'tips' => [['type' => 'info', 'text' => 'You can only hand out permissions and reach you hold yourself, and never your own. Every change is recorded in the activity log.']],
                        'links' => [['route' => 'settings.roles', 'label' => 'Open Roles & Permissions']],
                        'keywords' => 'roles permissions scope department override access',
                    ],
                    [
                        'id' => 'hr-reports', 'category' => 'Reports', 'title' => 'Reports', 'icon' => 'document-chart-bar',
                        'requires' => ['attendance.reports'],
                        'summary' => 'Attendance reports on screen, and downloadable reports in the Reports menu.',
                        'steps' => ['Open {menu:attendance.reports} for attendance reports.', 'Open the Reports group in the sidebar for the downloads you have access to.'],
                        'links' => [['route' => 'attendance.reports', 'label' => 'Open Attendance Reports']],
                        'keywords' => 'reports attendance leave performance export csv',
                    ],
                ],
            ],

            'finance' => [
                'label' => 'Finance',
                'title' => 'Finance Guide',
                'intro' => 'Payroll, payables and sign-offs: what is waiting on Finance and what it will cost.',
                'audience' => fn (User $u): bool => $u->canApproveFinance() || $u->hasPermission('run_payroll'),
                'sections' => [
                    [
                        'id' => 'fin-dashboard', 'category' => 'Finance', 'title' => 'Your finance dashboard', 'icon' => 'banknotes',
                        'requires' => ['dashboard.finance'],
                        'summary' => 'The Finance view shows the month\'s payroll runs, the sign-off queue, net pay, unpaid approved overtime, incentives, reimbursements, encashments and increment cycles awaiting you.',
                        'steps' => ['Click Dashboard (or {menu:dashboard.finance}).', 'Pick the month at the top right.', 'Click a card to open its page.'],
                        'links' => [['route' => 'dashboard.finance', 'label' => 'Open Finance View']],
                        'keywords' => 'finance dashboard payroll queue ot payable incentives reimbursements encashment increments',
                    ],
                    [
                        'id' => 'fin-payroll', 'category' => 'Payroll', 'title' => 'Running payroll', 'icon' => 'calculator',
                        'requires' => ['payroll.process'],
                        'summary' => 'Payroll is run per salary cycle and month, then signed off by Finance.',
                        'steps' => ['Open {menu:payroll.process}.', 'Choose the cycle and month and generate the payslips.', 'Review them, then submit the run for finance approval.'],
                        'links' => [['route' => 'payroll.process', 'label' => 'Open Run Payroll']],
                        'keywords' => 'payroll run process cycle payslips submit',
                    ],
                    [
                        'id' => 'fin-signoff', 'category' => 'Payroll', 'title' => 'Finance sign-off', 'icon' => 'check-badge',
                        'requires' => ['payroll.finance-approve'],
                        'summary' => 'Runs submitted for approval wait for Finance. You cannot approve a run that contains your own payslip.',
                        'steps' => ['Open {menu:payroll.finance-approve}.', 'Review the run\'s totals and approve or reject it.'],
                        'next' => ['Approved payslips are finalised and employees can see them.'],
                        'links' => [['route' => 'payroll.finance-approve', 'label' => 'Open Finance Approval']],
                        'keywords' => 'finance approval sign off payroll approve reject',
                    ],
                    [
                        'id' => 'fin-overtime', 'category' => 'Payables', 'title' => 'Overtime payable', 'icon' => 'clock',
                        'requires' => ['dashboard.finance'],
                        'summary' => 'Only approved overtime is paid. The "OT payable" card shows approved overtime not yet paid, with hours and amounts, and the people with the largest amounts.',
                        'steps' => ['Open your Finance view.', 'Check the OT payable card and the largest unpaid overtime list before the run.'],
                        'tips' => [['type' => 'info', 'text' => 'Overtime is approved by managers and HR before it reaches payroll.']],
                        'keywords' => 'overtime ot payable unpaid verification amount hours',
                    ],
                    [
                        'id' => 'fin-incentives', 'category' => 'Payables', 'title' => 'Incentives', 'icon' => 'sparkles',
                        'requires' => ['payroll.incentives'],
                        'summary' => 'Incentives are added per employee and month and paid through payroll once approved.',
                        'steps' => ['Open {menu:payroll.incentives}.', 'Add or review an incentive, then approve or reject it.'],
                        'links' => [['route' => 'payroll.incentives', 'label' => 'Open Incentives']],
                        'keywords' => 'incentives bonus approve payroll',
                    ],
                    [
                        'id' => 'fin-reimbursements', 'category' => 'Payables', 'title' => 'Reimbursements', 'icon' => 'receipt-percent',
                        'requires' => ['payroll.reimbursements'],
                        'summary' => 'Approved expense claims become reimbursements paid through payroll.',
                        'steps' => ['Open {menu:payroll.reimbursements}.', 'Review pending reimbursements and approve or reject them.'],
                        'links' => [['route' => 'payroll.reimbursements', 'label' => 'Open Reimbursements']],
                        'keywords' => 'reimbursements expenses claims approve payroll',
                    ],
                    [
                        'id' => 'fin-payslips', 'category' => 'Payroll', 'title' => 'Payslips', 'icon' => 'document-text',
                        'requires' => ['payroll.process'],
                        'summary' => 'Payslips are produced by each payroll run; employees see theirs once the run is finalised.',
                        'steps' => ['Open {menu:payroll.process} and choose the run.', 'Open a payslip to check it or download it as PDF.'],
                        'links' => [['route' => 'payroll.process', 'label' => 'Open Run Payroll']],
                        'keywords' => 'payslips pdf download email finalised',
                    ],
                ],
            ],

            'super-admin' => [
                'label' => 'Super Admin',
                'title' => 'Super Admin Guide',
                'intro' => 'The whole company: access, organisation, settings, the activity log and company-wide dashboards.',
                'audience' => $superAdmin,
                'sections' => [
                    [
                        'id' => 'sa-roles', 'category' => 'Access', 'title' => 'Roles & Permissions', 'icon' => 'shield-check',
                        'requires' => ['settings.roles'], 'when' => $superAdmin,
                        'summary' => 'Only a Super Admin can grant the privileged permissions (role management, purges, unlocking payroll, impersonation) or change another Super Admin.',
                        'steps' => [
                            'Open {menu:settings.roles}.',
                            'Edit a role\'s permissions and, per permission, its reach — own, team, own department, selected departments or all departments.',
                            'A Director reaches their own department by default; give company-wide reach deliberately if needed.',
                            'Use User overrides for one-person exceptions.',
                        ],
                        'links' => [['route' => 'settings.roles', 'label' => 'Open Roles & Permissions']],
                        'keywords' => 'super admin roles permissions privileged scope director company-wide',
                    ],
                    [
                        'id' => 'sa-departments', 'category' => 'Organisation', 'title' => 'Departments', 'icon' => 'building-office',
                        'requires' => ['settings.departments'], 'when' => $superAdmin,
                        'summary' => 'Departments and their heads decide what department-level access reaches.',
                        'steps' => ['Open {menu:settings.departments}.', 'Add or edit departments and set each one\'s head.'],
                        'links' => [['route' => 'settings.departments', 'label' => 'Open Departments']],
                        'keywords' => 'departments heads organisation',
                    ],
                    [
                        'id' => 'sa-settings', 'category' => 'Settings', 'title' => 'Company settings', 'icon' => 'cog-6-tooth',
                        'requires' => ['settings.control-panel'], 'when' => $superAdmin,
                        'summary' => 'Company-wide configuration: general settings, modules, attendance, leave, payroll cycles, notifications and the sidebar menu.',
                        'steps' => ['Open {menu:settings.control-panel} for every settings area in one place.', 'Use {menu:settings.modules} to switch payroll and payslips on or off for everyone.'],
                        'links' => [['route' => 'settings.control-panel', 'label' => 'Open Control Panel']],
                        'keywords' => 'settings control panel modules general company configuration',
                    ],
                    [
                        'id' => 'sa-audit', 'category' => 'Audit', 'title' => 'Activity log', 'icon' => 'clipboard-document-list',
                        'requires' => ['settings.audit-log'], 'when' => $superAdmin,
                        'summary' => 'Every sensitive change — records, approvals, permissions, refused attempts — is in the activity log with who, when and what changed.',
                        'steps' => ['Open {menu:settings.audit-log}.', 'Filter by module, action, person or date.'],
                        'tips' => [['type' => 'info', 'text' => 'Error pages show a reference code; the same code is on the matching log lines, so a reported problem can be traced.']],
                        'links' => [['route' => 'settings.audit-log', 'label' => 'Open Activity Log']],
                        'keywords' => 'audit activity log security refused permission changes request id',
                    ],
                    [
                        'id' => 'sa-dashboards', 'category' => 'Dashboards', 'title' => 'Company-wide dashboards', 'icon' => 'chart-bar-square',
                        'requires' => ['dashboard'], 'when' => $superAdmin,
                        'summary' => 'Your Dashboard is the company overview: today\'s attendance, decisions waiting, HR alerts, payroll this month, people by department and security activity.',
                        'steps' => ['Click Dashboard.', 'Open {menu:dashboard.executive} for the executive summary.'],
                        'links' => [['route' => 'dashboard', 'label' => 'Open Dashboard']],
                        'keywords' => 'super admin dashboard company overview executive security payroll',
                    ],
                ],
            ],
        ];
    }
}
