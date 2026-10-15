<?php

namespace Database\Seeders;

use App\Enums\QaFindingStatus;
use App\Enums\QaPhaseStatus;
use App\Enums\QaTestStatus;
use App\Models\QaChecklistItem;
use App\Models\QaFinding;
use App\Models\QaFixLink;
use App\Models\QaPhase;
use App\Models\QaTestCase;
use App\Models\QaTestResult;
use App\Models\QaTestRun;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * The QA & Audit tracker's starting record: the six audit phases and what
 * Phase 1 (access control, 15 Oct 2026) verified — its test cases, the
 * regression run (440 tests, 438 passed, 2 known-baseline Command Center
 * failures, 0 new regressions), the findings and the commits that fixed
 * them. Only verified results are recorded; everything else stays NOT
 * TESTED. Idempotent: existing rows (matched by code) are left as they are,
 * so it never overwrites results recorded later.
 */
class QaAuditSeeder extends Seeder
{
    private const PHASE1_COMMIT = 'a90dc231aa5d0f760b1cfe0ba633bbcac1162eeb';

    private const TESTER = 'Claude Code (automated Pest suite)';

    /** @var array<int, array{0: string, 1: string}> */
    private const PHASES = [
        1 => ['Access control & RBAC', 'Role × route sweep (8 roles × ~140 routes), navigation, dashboards, scope leakage, notification links, AI scope.'],
        2 => ['Org structure & data quality', 'Departments, heads, reporting lines, teams; read-only hrms:data-quality report.'],
        3 => ['Core modules & cross-module counts', 'Attendance, leave, overtime, WFH, performance; counts consistent across pages.'],
        4 => ['Payroll, settings, notifications, AI & reports', 'Payroll, theme/settings, notification delivery, AI, reports and date handling.'],
        5 => ['Responsive, dark mode & demo', 'Viewport × theme matrix, demo accounts per role, Playwright journeys.'],
        6 => ['Documentation & readiness report', 'Employee guide, QA master report, demo script.'],
    ];

    /**
     * code => [phase, module, feature, role, scope, title, expected, route, severity, automated reference]
     *
     * @var array<string, array{0: int, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string, 7: ?string, 8: string, 9: string}>
     */
    private const CASES = [
        'QA-RBAC-001' => [1, 'Navigation', 'Sidebar', 'All 8 roles', 'Per role', 'Every menu link a role is shown opens without a 403 or error', 'Each visible link returns 200 for its role.', null, 'high', 'Navigation/RoleNavigationTest > every link a role is shown opens without a 403 or an error'],
        'QA-RBAC-002' => [1, 'RBAC', 'Probation', 'Director (department)', 'Department', "A department-scoped Director cannot open another department's probation page", '403 outside the department.', null, 'high', "Security/ProbationScopeTest > a department-scoped Director cannot open another department's probation page"],
        'QA-RBAC-003' => [1, 'RBAC', 'Probation', 'Director (department)', 'Department', "The probation service refuses to extend outside the actor's scope", 'Service aborts 403; probation end date unchanged.', null, 'high', "Security/ProbationScopeTest > the probation service refuses to extend outside the actor's scope"],
        'QA-RBAC-004' => [1, 'RBAC', 'Probation', 'Any', 'Self', 'Nobody extends their own probation', 'Self-decision refused.', null, 'medium', 'Security/ProbationScopeTest > nobody extends their own probation'],
        'QA-RBAC-005' => [1, 'Dashboards', 'Department View', 'Department Head', 'Department', 'A Department Head sees Team View and Department View for their department only', 'Both dashboards open; no other-department names.', 'dashboard.department', 'medium', 'Security/RolePolicyDecisionsTest > a Department Head sees Team View and Department View for their department only'],
        'QA-RBAC-006' => [1, 'RBAC', 'Employee 360', 'Department Head', 'Department', 'A Department Head opens Employee 360 in the department but does not edit employees', '360 opens in department, 403 outside; edit 403.', null, 'medium', 'Security/RolePolicyDecisionsTest > a Department Head opens Employee 360 in the department but does not edit employees'],
        'QA-RBAC-007' => [1, 'RBAC', 'Performance', 'Finance', 'Company', 'Finance has no performance management by default', 'Team performance, PIP, promotions, KPI management 403; payroll still opens.', 'performance.team', 'medium', 'Security/RolePolicyDecisionsTest > Finance has no performance management by default'],
        'QA-RBAC-008' => [1, 'Dashboards', 'Landing', 'Coordinator', 'Department', 'A Coordinator lands on Team View, with no Main dashboard and no approvals', 'Lands on Team View; Main 403; approval pages 403.', 'dashboard.manager', 'medium', 'Security/RolePolicyDecisionsTest > a Coordinator lands on Team View for their department, with no Main dashboard and no approvals'],
        'QA-RBAC-009' => [1, 'Navigation', 'Attendance', 'Coordinator', 'Department', "A Coordinator finds Live Attendance and their department's Attendance Reports in the menu", 'Menu items present; report shows no other department.', 'attendance.reports', 'medium', "Security/RolePolicyDecisionsTest > a Coordinator finds Live Attendance and their department's Attendance Reports in the menu"],
        'QA-RBAC-010' => [1, 'RBAC', 'Attendance Reports', 'Employee', 'Self', 'Attendance Reports stay closed to someone who only sees their own record', 'Employee 403; manager 200.', 'attendance.reports', 'medium', 'Security/RolePolicyDecisionsTest > Attendance Reports stay closed to someone who only sees their own record'],
        'QA-RBAC-011' => [1, 'Scope', 'Teams', 'Director (department)', 'Department', "A department-scoped Director sees only their department's teams and people", 'Other-department teams hidden; open/create/staff refused.', 'employees.teams', 'high', 'Security/TeamManagementScopeTest > the Director cannot open, create in, or staff from another department'],
        'QA-RBAC-012' => [1, 'Scope', 'Employee export', 'HR Admin (department)', 'Department', 'A department-scoped HR admin exports only their department', 'Export contains in-scope employees only.', null, 'high', 'Security/EmployeeExportScopeTest > a department-scoped HR admin exports only their department'],
        'QA-RBAC-013' => [1, 'RBAC', 'Roles', 'HR Admin', 'Company', 'HR administers the Department Head role but never the Super Admin role', 'DH role editable; SA role refused.', 'settings.roles', 'medium', 'Security/RolePolicyDecisionsTest > HR administers the Department Head role but never the Super Admin role'],
        'QA-RBAC-014' => [1, 'RBAC', 'Policy migration', 'System', 'Company', 'The policy migration updates the built-in roles and never touches per-user grants', 'Idempotent; per-user grants kept.', null, 'medium', 'Security/RolePolicyDecisionsTest > the policy migration updates the built-in roles and never touches per-user grants'],
        'QA-RBAC-015' => [1, 'Navigation', 'Command Center badge', 'Manager', 'Team', "The Command Center badge counts only requests in the approver's reach", 'Badge equals in-reach pending count.', null, 'medium', "Navigation/RoleNavigationTest > the Command Center badge counts only requests in the approver's reach"],
        'QA-NOTIF-001' => [1, 'Notifications', 'Status change', 'Employee', 'Self', "The employee's status-change notice opens their own profile", 'Link opens /profile for the employee.', null, 'medium', "NotificationDestinationsTest > the employee's status-change notice opens their own profile"],
        'QA-NOTIF-002' => [1, 'Notifications', 'Probation extended', 'Manager', 'Team', "The line manager's probation-extended notice opens the probation page they can act on", 'Link opens; out-of-team manager refused.', null, 'medium', "NotificationDestinationsTest > the line manager's probation-extended notice opens the probation page they can act on"],
        'QA-NOTIF-003' => [1, 'Notifications', 'Offboarding', 'Employee / HR', 'Self / Company', 'The offboarding-completed notice opens a real page for the employee and for HR', 'Both links open (no 403).', null, 'medium', 'NotificationDestinationsTest > the offboarding-completed notice opens a real page for the employee and for HR'],
        'QA-AI-001' => [1, 'AI', 'Attendance answers', 'Department Head', 'Department', "AI attendance answers cover only the department head's department", 'Only in-scope employees in the answer.', 'ai.assistant', 'high', "Security/AiScopeTest > attendance answers cover only the department head's department"],
        'QA-AI-002' => [1, 'AI', 'Lookup', 'Department Head', 'Department', 'Another department cannot be looked up by name', 'Out-of-scope lookup returns nothing.', 'ai.assistant', 'high', 'Security/AiScopeTest > another department cannot be looked up by name'],
        'QA-AI-003' => [1, 'AI', 'Approval queue', 'Department Head', 'Department', 'The AI approval queue counts only requests in reach', 'Count equals in-reach pending.', 'ai.assistant', 'medium', 'Security/AiScopeTest > the approval queue counts only requests in reach'],
        'QA-UI-001' => [1, 'Employees', 'Employee edit', 'HR Admin', 'Company', 'The account access menu opens above the hero card, never clipped', 'Dropdown in the top layer with every action.', null, 'medium', 'EmployeeEditLayoutTest > the account access menu is a top-layer dropdown with every action, outside the clipping hero'],
        'QA-UI-002' => [1, 'Employees', 'Employee edit', 'HR Admin', 'Company', 'The personal tab is one aligned grid', 'Phone | DOB, Gender | Emergency contact, then full-width rows.', null, 'low', 'EmployeeEditLayoutTest > the personal tab is one grid: Phone | Date of Birth, Gender | Emergency Contact, then full-width rows'],
        'QA-UI-003' => [1, 'Attendance', 'My Attendance', 'Employee', 'Self', 'My Attendance premium dashboard, filters and history render', 'Hero, KPIs, filters and history render; filters apply.', 'attendance.my', 'low', 'MyAttendanceRedesignTest'],
        'QA-BASE-001' => [1, 'Attendance', 'Command Center', 'HR Admin', 'Company', 'The command center shows pending counts per request type', 'Pending counts match per type.', 'attendance.command-center', 'medium', 'AttendanceCommandCenterTest > the command center shows pending counts per request type'],
        'QA-BASE-002' => [1, 'Attendance', 'Command Center', 'HR Admin', 'Company', 'The status filter shows decided history, not just pending', 'Decided requests listed under the status filter.', 'attendance.command-center', 'medium', 'AttendanceCommandCenterTest > the status filter shows decided history, not just pending'],
    ];

    /**
     * code => [kind, module, severity, status, title, description, root cause, test case, role, scope, route, page, viewport, theme]
     *
     * @var array<string, array<int, mixed>>
     */
    private const FINDINGS = [
        'QA-F-001' => ['rbac', 'Employees', 'high', 'verified', 'Probation page and extend had no scope check', 'Any user with approve-leave could open (and extend) the probation of an employee outside their reach.', 'mount() and ProbationEngine::extend() checked the role, not ScopeResolver coverage.', 'QA-RBAC-002', 'Director / Manager', 'Department', 'employees.probation'],
        'QA-F-002' => ['rbac', 'Dashboards', 'medium', 'verified', 'Department Head had no Department View', 'Policy decision 15 Oct: DH gets Team View + Department View, department scope only.', 'Role defaults predated the decision.', 'QA-RBAC-005', 'Department Head', 'Department', 'dashboard.department'],
        'QA-F-003' => ['rbac', 'Performance', 'medium', 'verified', 'Finance held performance management by default', 'Finance carried review_performance, manage_pip and manage_promotions.', 'Seeder defaults; policy now: explicit grants only.', 'QA-RBAC-007', 'Finance', 'Company', 'performance.team'],
        'QA-F-004' => ['rbac', 'Dashboards', 'medium', 'verified', 'Coordinator landed on the company-wide Main dashboard', 'Coordinator held view_main_dashboard; policy is Team View (department), no Main unless granted.', 'Role defaults predated the decision.', 'QA-RBAC-008', 'Coordinator', 'Department', 'dashboard.main'],
        'QA-F-005' => ['functional', 'Roles & Permissions', 'low', 'verified', 'HR could no longer edit the Department Head role after the policy change', 'Delegation ceiling refused a role carrying view_department_dashboard.', 'department_head was not a default delegable role.', 'QA-RBAC-013', 'HR Admin', 'Company', 'settings.roles'],
        'QA-F-006' => ['functional', 'Notifications', 'medium', 'verified', 'Notifications linked to pages their recipient cannot open', 'Status-change, probation and offboarding notices pointed at HR-only pages (403 for the recipient).', 'Links built for HR, sent to employees/managers.', 'QA-NOTIF-001', 'Employee / Manager', 'Self / Team', null],
        'QA-F-007' => ['rbac', 'Organization', 'high', 'verified', 'Teams page showed and managed every department for department-scoped users', 'A department-scoped Director could open, create teams in, and staff from other departments.', 'TeamManagement had no ScopeResolver filter.', 'QA-RBAC-011', 'Director (department)', 'Department', 'employees.teams'],
        'QA-F-008' => ['security', 'Employees', 'high', 'verified', "Employee export ignored the exporter's scope", 'A department-scoped HR admin downloaded every employee.', 'EmployeesExport queried all employees.', 'QA-RBAC-012', 'HR Admin (department)', 'Department', null],
        'QA-F-009' => ['security', 'AI', 'low', 'verified', 'AI Assistant scope was not pinned by tests', 'No leak found; tests added so the scope cannot regress.', 'Coverage gap.', 'QA-AI-001', 'Department Head', 'Department', 'ai.assistant'],
        'QA-F-010' => ['functional', 'Attendance', 'medium', 'verified', 'Coordinator had no Live Attendance link; Attendance Reports needed approve-leave', 'Coordinators hold view_live_attendance but saw no menu item; reports were gated on approvals.', 'Route group and sidebar gate used approve-leave.', 'QA-RBAC-009', 'Coordinator', 'Department', 'attendance.reports'],
        'QA-F-011' => ['ui', 'Employees', 'medium', 'verified', 'Employee edit: account access menu clipped by the hero card', 'The dropdown was cut off inside the overflow-hidden hero.', 'Menu rendered inside a clipping container.', 'QA-UI-001', 'HR Admin', 'Company', null, 'Employee Edit', 'All', 'Light + Dark'],
        'QA-F-012' => ['ui', 'Employees', 'low', 'verified', 'Employee edit: personal form misaligned', 'Personal tab fields did not line up in a single grid.', 'Mixed nested grids.', 'QA-UI-002', 'HR Admin', 'Company', null, 'Employee Edit', 'Desktop', 'Light + Dark'],
        'QA-F-013' => ['ui', 'Attendance', 'low', 'verified', 'My Attendance below the premium standard', 'Dashboard and filters were plain and inconsistent with the design system.', 'Pre-design-system page.', 'QA-UI-003', 'Employee', 'Self', 'attendance.my', 'My Attendance', 'All', 'Light + Dark'],
        'QA-F-014' => ['rbac', 'Employees', 'low', 'open', 'Probation confirm / HR approve still check role names', 'There is no probation permission; access is inferred from role checks (approve-leave / manage-employees) plus scope.', 'No dedicated permission key.', null, 'Manager / HR', 'Team / Company', 'employees.probation'],
        'QA-F-015' => ['security', 'Settings', 'info', 'open', 'Impersonation acts with the target user\'s full permissions', 'Login-as applies every permission of the target, including actions; it is audited. Policy decision pending (view-only vs full).', 'By design; needs a decision.', null, 'Super Admin', 'Company', null],
        'QA-F-016' => ['rbac', 'Employees', 'low', 'open', 'Director has department-scoped employee edit (D1 open)', 'Director edits employees in their department; D1 policy not finalised.', 'Pending decision.', null, 'Director', 'Department', null],
        'QA-F-017' => ['functional', 'Attendance', 'medium', 'investigating', 'Two auto-checkout commands are scheduled', 'Both hrms:auto-punch-out and hrms:auto-checkout are in the schedule; confirm they cannot double-close a session.', 'To verify.', null, 'System', 'Company', null],
        'QA-F-018' => ['functional', 'Roles & Permissions', 'low', 'open', 'Local HR Admin role drift', 'The local HR Admin role lacks view_employee_360 and view_org_chart that the seeder grants; check production with a read-only role drift report.', 'Database predates later grants.', null, 'HR Admin', 'Company', null],
        'QA-F-019' => ['functional', 'Attendance', 'low', 'open', 'Command Center: 2 known-baseline test failures', 'Pending counts per type and the status filter tests fail on staging since before Phase 1. Known baseline — not a regression.', 'Pre-existing; not yet investigated.', 'QA-BASE-001', 'HR Admin', 'Company', 'attendance.command-center'],
        'QA-F-020' => ['functional', 'Attendance', 'low', 'open', 'RegularisationPreservesOriginalTest fails when yesterday is a weekend', 'Date-dependent test flake (weekly-off yesterday).', 'Test uses a relative date.', null, 'System', 'Company', null],
        'QA-F-022' => ['functional', 'Settings', 'low', 'open', 'CompanySettingsTest still expects the old "Company Logo" label', 'tests/Feature/Settings/CompanySettingsTest "the branding upload fields render" fails: 9628986 renamed the field to "Logo (light mode)". Found in the QA tracker regression run, 15 Oct 2026.', 'Stale test assertion after the branding change.', null, 'Super Admin', 'Company', 'settings.general'],
        'QA-F-021' => ['rbac', 'Attendance', 'info', 'open', 'Finance opens Attendance Reports company-wide', 'Finance holds company-wide view_attendance (payroll needs it), so Attendance Reports open for Finance. Confirm this is intended.', 'By design; needs confirmation.', null, 'Finance', 'Company', 'attendance.reports'],
    ];

    /**
     * finding code => [commit, message, date, files, test codes, note]
     *
     * @var array<string, array<int, mixed>>
     */
    private const FIXES = [
        'QA-F-001' => ['a6cfb2726c0ae4fd4cd1b4bc9de5ae76cb331705', 'fix(rbac): scope probation confirmation to the employees a user covers', '2026-10-15 11:01:39', ['app/Livewire/Employees/ProbationConfirmation.php', 'app/Services/ProbationEngine.php', 'tests/Feature/Security/ProbationScopeTest.php'], ['QA-RBAC-002', 'QA-RBAC-003', 'QA-RBAC-004'], 'mount and extend check coversEmployee + assertNotSelf.'],
        'QA-F-002' => ['ba5e1bb79c280890ef2e3f1c39a943ff4ca09a12', 'fix(rbac): apply Phase 1 role policy decisions', '2026-10-15 11:35:31', ['app/Services/Security/PermissionScopes.php', 'database/migrations/2026_10_15_111021_apply_phase1_role_policy_decisions.php', 'database/seeders/RolesAndPermissionsSeeder.php'], ['QA-RBAC-005', 'QA-RBAC-006'], 'Migration grants built-in roles only; per-user overrides untouched.'],
        'QA-F-003' => ['ba5e1bb79c280890ef2e3f1c39a943ff4ca09a12', 'fix(rbac): apply Phase 1 role policy decisions', '2026-10-15 11:35:31', ['database/seeders/RolesAndPermissionsSeeder.php', 'database/migrations/2026_10_15_111021_apply_phase1_role_policy_decisions.php'], ['QA-RBAC-007'], 'Explicit Finance grants are kept.'],
        'QA-F-004' => ['ba5e1bb79c280890ef2e3f1c39a943ff4ca09a12', 'fix(rbac): apply Phase 1 role policy decisions', '2026-10-15 11:35:31', ['app/Services/Security/PermissionScopes.php', 'app/Livewire/ManagerDashboard.php', 'app/Livewire/DepartmentDashboard.php'], ['QA-RBAC-008'], null],
        'QA-F-005' => ['ba5e1bb79c280890ef2e3f1c39a943ff4ca09a12', 'fix(rbac): apply Phase 1 role policy decisions', '2026-10-15 11:35:31', ['app/Services/Security/RoleDelegationGuard.php', 'resources/views/livewire/settings/role-manager.blade.php'], ['QA-RBAC-013', 'QA-RBAC-014'], 'department_head added to DEFAULT_DELEGABLE_ROLES.'],
        'QA-F-006' => ['585d1d47ad5b9ecf0fd34f0c6a89e4170d978e46', 'fix(notifications): link every notification to a page its recipient can open', '2026-10-15 11:39:31', ['app/Notifications/EmployeeStatusChangedNotification.php', 'app/Notifications/OffboardingCompletedNotification.php', 'app/Notifications/ProbationConfirmedNotification.php', 'app/Notifications/ProbationExtendedNotification.php', 'routes/web.php', 'tests/Feature/NotificationDestinationsTest.php'], ['QA-NOTIF-001', 'QA-NOTIF-002', 'QA-NOTIF-003'], null],
        'QA-F-007' => ['2bd6beb67a28f442da02eb4901ebc6c7d617f4b9', 'fix(rbac): scope Teams to the departments a user manages', '2026-10-15 11:46:25', ['app/Livewire/Employees/TeamManagement.php', 'tests/Feature/Security/TeamManagementScopeTest.php'], ['QA-RBAC-011'], null],
        'QA-F-008' => ['cfc173a4b67a06b5236509a50235d98ba97ce335', "fix(rbac): scope the employee export to the exporter's reach", '2026-10-15 11:48:52', ['app/Exports/EmployeesExport.php', 'app/Livewire/Employees/EmployeeImport.php', 'tests/Feature/Security/EmployeeExportScopeTest.php'], ['QA-RBAC-012'], null],
        'QA-F-009' => ['57562101f7c674a7041b50ee7ba1e390c2d72828', "test(rbac): pin the AI Assistant to the user's scope", '2026-10-15 11:51:56', ['tests/Feature/Security/AiScopeTest.php'], ['QA-AI-001', 'QA-AI-002', 'QA-AI-003'], 'Test-only; the LLM receives aggregates only.'],
        'QA-F-010' => ['a90dc231aa5d0f760b1cfe0ba633bbcac1162eeb', 'fix(navigation): give Coordinators Live Attendance and scoped Attendance Reports', '2026-10-15 12:26:11', ['app/Http/Controllers/ReportController.php', 'app/Livewire/Attendance/AttendanceReports.php', 'app/Services/Navigation/Sidebar.php', 'app/Services/Security/PermissionScopes.php', 'routes/web.php'], ['QA-RBAC-009', 'QA-RBAC-010'], null],
        'QA-F-011' => ['671b276d21d22bb431b3198b1b9ab494570f8326', 'fix(employee): correct access menu clipping and personal form alignment', '2026-10-12 15:17:58', ['resources/views/livewire/employees/employee-edit.blade.php', 'tests/Feature/EmployeeEditLayoutTest.php'], ['QA-UI-001'], null],
        'QA-F-012' => ['671b276d21d22bb431b3198b1b9ab494570f8326', 'fix(employee): correct access menu clipping and personal form alignment', '2026-10-12 15:17:58', ['resources/views/livewire/employees/employee-edit.blade.php', 'tests/Feature/EmployeeEditLayoutTest.php'], ['QA-UI-002'], null],
        'QA-F-013' => ['961fac5c694881ae56c48ba85612c4b9c65172a9', 'style(attendance): upgrade employee attendance dashboard and filters', '2026-10-12 14:27:36', ['app/Livewire/Attendance/AttendanceTracker.php', 'resources/views/attendance/my.blade.php', 'tests/Feature/MyAttendanceRedesignTest.php'], ['QA-UI-003'], null],
    ];

    public function run(): void
    {
        $phases = [];
        foreach (self::PHASES as $number => [$name, $notes]) {
            $phases[$number] = QaPhase::firstOrCreate(['number' => $number], [
                'name' => $name,
                'notes' => $notes,
                'status' => $number === 1 ? QaPhaseStatus::Completed : QaPhaseStatus::NotStarted,
                'owner' => $number === 1 ? 'Claude Code + Super Admin' : null,
                'started_at' => $number === 1 ? '2026-10-15' : null,
                'completed_at' => $number === 1 ? '2026-10-15' : null,
            ]);
        }

        $testedAt = Carbon::parse('2026-10-15 12:40:00');
        $run = QaTestRun::firstOrCreate(['name' => '2026-10-15 Phase 1 RBAC Regression'], [
            'qa_phase_id' => $phases[1]->id,
            'suite' => 'pest',
            'environment' => 'local_test_db',
            'commit' => self::PHASE1_COMMIT,
            'total' => 440, 'passed' => 438, 'failed' => 2, 'skipped' => 0,
            'known_baseline' => 2, 'new_regressions' => 0,
            'duration_seconds' => 713,
            'result' => QaTestRun::RESULT_PASS_WITH_BASELINE,
            'notes' => 'Sharded affected-suite regression (35 files). Failures: AttendanceCommandCenterTest × 2 — known baseline, not regressions.',
            'run_at' => $testedAt,
        ]);

        foreach (self::CASES as $code => [$phase, $module, $feature, $role, $scope, $title, $expected, $route, $severity, $reference]) {
            $baseline = str_starts_with($code, 'QA-BASE-');
            $case = QaTestCase::firstOrCreate(['code' => $code], [
                'qa_phase_id' => $phases[$phase]->id,
                'module' => $module, 'feature' => $feature, 'role' => $role, 'scope' => $scope,
                'title' => $title, 'expected_result' => $expected, 'route_name' => $route,
                'severity' => $severity, 'automated_reference' => 'tests/Feature/'.$reference,
                'steps' => 'Automated: php artisan test --compact --filter="'.str($reference)->afterLast('> ')->before(' >').'"',
                'status' => $baseline ? QaTestStatus::KnownBaseline : QaTestStatus::Pass,
                'last_tested_at' => $testedAt,
            ]);

            if ($case->wasRecentlyCreated) {
                QaTestResult::create([
                    'qa_test_case_id' => $case->id,
                    'qa_test_run_id' => $run->id,
                    'status' => $baseline ? QaTestStatus::KnownBaseline : QaTestStatus::Pass,
                    'actual_result' => $baseline ? 'Fails on staging since before Phase 1 (pre-existing).' : 'Passed in the Phase 1 regression run.',
                    'environment' => 'local_test_db',
                    'commit' => self::PHASE1_COMMIT,
                    'note' => $baseline ? 'Known baseline — not a regression.' : null,
                    'tested_by_name' => self::TESTER,
                    'tested_at' => $testedAt,
                ]);
            }
        }

        $caseIds = QaTestCase::pluck('id', 'code');
        foreach (self::FINDINGS as $code => $f) {
            [$kind, $module, $severity, $status, $title, $description, $rootCause, $caseCode, $role, $scope, $route] = $f;
            $fix = self::FIXES[$code] ?? null;
            $finding = QaFinding::firstOrCreate(['code' => $code], [
                'qa_phase_id' => $phases[1]->id,
                'qa_test_case_id' => $caseCode ? $caseIds[$caseCode] ?? null : null,
                'kind' => $kind, 'module' => $module, 'severity' => $severity, 'status' => $status,
                'title' => $title, 'description' => $description, 'root_cause' => $rootCause,
                'role_affected' => $role, 'scope_affected' => $scope, 'route' => $route,
                'page' => $f[11] ?? null, 'viewport' => $f[12] ?? null, 'theme' => $f[13] ?? null,
                'fix_summary' => $fix ? $fix[1] : null,
                'retest_result' => $status === QaFindingStatus::Verified->value ? 'Linked tests pass in the Phase 1 regression run (a90dc23).' : null,
                'environment' => 'local_test_db',
                'found_at' => $fix ? Carbon::parse($fix[2])->subHour() : $testedAt,
                'closed_at' => $status === QaFindingStatus::Verified->value ? $testedAt : null,
            ]);

            if ($fix !== null && $finding->wasRecentlyCreated) {
                QaFixLink::create([
                    'qa_finding_id' => $finding->id,
                    'commit_hash' => $fix[0],
                    'commit_message' => $fix[1],
                    'fixed_at' => $fix[2],
                    'files_changed' => $fix[3],
                    'test_case_codes' => $fix[4],
                    'developer_note' => $fix[5],
                    'environment' => 'local_test_db',
                ]);
            }
        }

        $this->seedVerifiedChecklist($testedAt);
    }

    /**
     * Only what Phase 1 actually verified: route-level RBAC across modules,
     * scope where a scope test exists, and per-role navigation and landing.
     */
    private function seedVerifiedChecklist(Carbon $at): void
    {
        $cells = [];
        foreach (['Dashboard', 'Employees', 'Organization', 'Attendance', 'Leave', 'Overtime', 'WFH', 'Performance', 'Payroll', 'Reports', 'Notifications', 'AI', 'Settings', 'Roles & Permissions'] as $module) {
            $cells[] = [QaChecklistItem::MODULE, $module, 'rbac', 'Role × route sweep, 8 roles (Phase 1).'];
        }
        foreach (['Dashboard', 'Employees', 'Organization', 'Attendance', 'Reports', 'AI'] as $module) {
            $cells[] = [QaChecklistItem::MODULE, $module, 'scope', 'Scope tests added in Phase 1.'];
        }
        foreach (['super_admin', 'hr_admin', 'director', 'department_head', 'manager', 'finance', 'coordinator', 'employee'] as $role) {
            $cells[] = [QaChecklistItem::DEMO, $role, 'navigation', 'RoleNavigationTest: every menu link opens.'];
            if ($role !== 'finance') {
                $cells[] = [QaChecklistItem::DEMO, $role, 'landing', 'RoleNavigationTest: lands on its own dashboard.'];
            }
        }

        foreach ($cells as [$category, $subject, $dimension, $note]) {
            QaChecklistItem::firstOrCreate(
                ['category' => $category, 'subject' => $subject, 'dimension' => $dimension],
                ['status' => 'pass', 'note' => $note, 'commit' => self::PHASE1_COMMIT, 'checked_at' => $at],
            );
        }
    }
}
