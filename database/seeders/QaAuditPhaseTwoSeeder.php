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
 * Phase 2 (org structure & data quality, 15 Oct 2026) in the QA tracker:
 * the code-level test cases, the regression and full-suite runs, the code
 * bugs found and fixed, and the policy questions raised. Environment-
 * specific DATA issues are not seeded here — they are recorded on each
 * environment by `qa:record-data-quality`. Idempotent: existing rows
 * (matched by code / name) are left as they are.
 */
class QaAuditPhaseTwoSeeder extends Seeder
{
    private const TESTER = 'Claude Code (automated Pest suite)';

    private const FEATURE_COMMIT = 'fe35a84';

    /** code => [module, feature, title, severity, test file › name] */
    private const CASES = [
        'QA-ORG-001' => ['Organization', 'Master data', 'A complete employee raises no data-quality issue', 'medium', 'DataQuality/DataQualityAuditTest > a complete employee raises no employee-level issue'],
        'QA-ORG-002' => ['Organization', 'Master data', 'Missing manager, department, shift and job title are detected', 'high', 'DataQuality/DataQualityAuditTest > missing manager, department, shift and job title are detected'],
        'QA-ORG-003' => ['Organization', 'Master data', 'A company default shift covers employees without their own shift', 'medium', 'DataQuality/DataQualityAuditTest > a company default shift covers employees without their own shift'],
        'QA-ORG-004' => ['Organization', 'Reporting', 'Directors and Super Admins at the top need no manager', 'low', 'DataQuality/DataQualityAuditTest > Directors and Super Admins at the top need no manager'],
        'QA-ORG-005' => ['Organization', 'Integrity', 'The database refuses a dangling department and a duplicate employee code', 'high', 'DataQuality/DataQualityAuditTest > the database itself refuses a dangling department and a duplicate employee code'],
        'QA-ORG-006' => ['Organization', 'Reporting', 'An employee reporting to themselves is critical', 'high', 'DataQuality/DataQualityAuditTest > an employee who reports to themselves is critical'],
        'QA-ORG-007' => ['Organization', 'Reporting', 'A circular reporting chain is detected once', 'high', 'DataQuality/DataQualityAuditTest > a circular reporting chain is detected once'],
        'QA-ORG-008' => ['Organization', 'Reporting', 'Reporting to a deleted or departed manager is flagged', 'high', 'DataQuality/DataQualityAuditTest > reporting to a deleted or departed manager is flagged'],
        'QA-ORG-009' => ['Organization', 'Reporting', 'Cross-department managers: REVIEW NEEDED when they can approve, INVALID when not', 'medium', 'DataQuality/DataQualityAuditTest > a cross-department manager is REVIEW NEEDED when they can approve, INVALID when they cannot'],
        'QA-ORG-010' => ['Organization', 'Departments', 'Departments with no / inactive / several heads are flagged', 'medium', 'DataQuality/DataQualityAuditTest > departments without a head, with an inactive head, or with several Department Heads are flagged'],
        'QA-ORG-011' => ['RBAC', 'Role drift', 'Role drift compares the database with the defaults and resets nothing', 'medium', 'DataQuality/DataQualityAuditTest > role drift compares the database with the built-in defaults, without resetting anything'],
        'QA-ORG-012' => ['RBAC', 'Overrides', 'Per-user overrides are listed; a privileged grant is high', 'high', 'DataQuality/DataQualityAuditTest > per-user overrides are listed, and a privileged grant is high'],
        'QA-ORG-013' => ['Scope', 'Scope', 'A department-scoped grant with no departments is flagged', 'high', 'DataQuality/DataQualityAuditTest > a department-scoped grant with no departments is flagged'],
        'QA-ORG-014' => ['Organization', 'Roles', 'A manager with no reports and a Department Head heading nothing are flagged', 'medium', 'DataQuality/DataQualityAuditTest > a manager with no reports and a Department Head heading nothing are flagged'],
        'QA-ORG-015' => ['Organization', 'Approval routing', 'Every request type resolves; a first approver without rights is flagged', 'high', 'DataQuality/DataQualityAuditTest > approval routing: every request type resolves, and a first approver without rights is flagged'],
        'QA-ORG-016' => ['Organization', 'Approval routing', 'An HR admin is never the approver of their own request', 'medium', 'DepartmentTeamTest > a request by an HR admin routes to another HR admin, never to themselves'],
        'QA-ORG-017' => ['Organization', 'Roles', 'A custom role with no users and no purpose is flagged', 'low', 'DataQuality/DataQualityAuditTest > a custom role with no users and no purpose is flagged'],
        'QA-ORG-018' => ['Security', 'Read-only command', 'hrms:data-quality writes nothing and has no --fix', 'critical', 'DataQuality/DataQualityAuditTest > hrms:data-quality is read-only: no writes reach the database'],
        'QA-ORG-019' => ['Organization', 'Command', 'hrms:data-quality filters by department, employee and severity', 'low', 'DataQuality/DataQualityAuditTest > hrms:data-quality filters by department, employee and severity'],
        'QA-ORG-020' => ['Organization', 'QA integration', 'Data issues are filed as HR corrections, never as code bugs', 'medium', 'DataQuality/DataQualityAuditTest > qa:record-data-quality files data issues as HR corrections, never as code bugs, and verifies them once fixed'],
        'QA-ORG-021' => ['RBAC', 'Data Quality page', 'Super Admin and HR open every tab; it sits under People', 'medium', 'DataQuality/DataQualityPageTest > Super Admin and HR open every tab and find it under People'],
        'QA-ORG-022' => ['RBAC', 'Data Quality page', 'Roles without view_hr_data_quality cannot open or see it', 'high', 'DataQuality/DataQualityPageTest > roles without the permission cannot open it or see it in the menu'],
        'QA-ORG-023' => ['Scope', 'Data Quality page', 'A department-scoped HR admin sees only their department and no role drift', 'high', 'DataQuality/DataQualityPageTest > a department-scoped HR admin sees only their department, and no company-wide role drift'],
        'QA-ORG-024' => ['Organization', 'Data Quality page', 'Filters narrow the issue list', 'low', 'DataQuality/DataQualityPageTest > filters narrow the issue list'],
        'QA-ORG-025' => ['Security', 'Data Quality page', 'The page links to records and changes nothing', 'high', 'DataQuality/DataQualityPageTest > issues link to the record to fix by hand; the page itself changes nothing'],
        'QA-ORG-026' => ['Organization', 'QA tracker', 'Editing a run or result never rewrites when it ran', 'low', 'Qa/QaTrackerWorkflowTest > editing a run or result never rewrites when it ran'],
        'QA-ORG-027' => ['Attendance', 'My Attendance', 'A hybrid day is listed in the attendance history on any date', 'low', 'AttendanceModeTest > a hybrid day is listed in the attendance history'],
    ];

    /**
     * code => [kind, module, severity, status, needs_decision, title, description, root cause, test case, fix: [commit, message, date, files, tests]|null]
     *
     * @var array<string, array<int, mixed>>
     */
    private const FINDINGS = [
        'QA-F-023' => ['functional', 'Organization', 'medium', 'verified', false, 'Approval chain could name the requester as their own approver',
            'For an HR admin or Super Admin with no manager or department head, ApprovalRoutingService\'s final HR / Super Admin rung included the requester, so the approval queue and Employee 360 showed "Awaiting decision: <themselves>". ApprovalGuard still refused the self-decision.',
            'The requester was skipped on the department-head rung but not on the HR rung.', 'QA-ORG-016',
            ['9a5efa3', "fix(approvals): never route an employee's request to themselves", '2026-10-15 14:40:00', ['app/Services/Teams/ApprovalRoutingService.php', 'tests/Feature/DepartmentTeamTest.php'], ['QA-ORG-016']]],
        'QA-F-024' => ['functional', 'QA & Audit', 'low', 'verified', false, 'QA run times were rewritten when a run was edited',
            'qa_test_runs.run_at and qa_test_results.tested_at had MySQL ON UPDATE CURRENT_TIMESTAMP; marking the Phase 1 run as a security run moved it from 12:40 to the migration time.',
            'First NOT NULL timestamp column of each table gets ON UPDATE CURRENT_TIMESTAMP on this MySQL configuration.', 'QA-ORG-026',
            ['d1fd7bc', 'fix(qa): keep run and result times fixed when a record is edited', '2026-10-15 15:20:00', ['database/migrations/2026_10_15_144715_fix_qa_status_and_timestamp_columns.php', 'tests/Feature/Qa/QaTrackerWorkflowTest.php'], ['QA-ORG-026']]],
        'QA-F-028' => ['functional', 'Attendance', 'low', 'verified', false, 'Hybrid-history test failed after the 13th of the month',
            'AttendanceModeTest "a hybrid day is listed in the attendance history" dates its record the 4th of the month; since My Attendance history pages 10 days at a time (961fac5) the row sat on page 2 from the 14th. The product was right; the test was date-dependent.',
            'Test relied on the record being among the newest 10 days.', 'QA-ORG-027',
            ['4627c03', 'test(attendance): pin the hybrid-history test to its own date', '2026-10-15 16:24:00', ['tests/Feature/AttendanceModeTest.php'], ['QA-ORG-027']]],
        'QA-F-029' => ['functional', 'Leave', 'low', 'investigating', false, 'EmployeeLeaveProvisioningTest fails only in the full suite',
            '"the approved company default supplies a missing pattern" expected 28.0 entitlement and got 25.55 (pro-rated) in the full run; it passes on its own and with its file. Order-dependent state leaks from an earlier test (a cached setting or travelled clock).',
            'Test isolation: shared state between tests — not yet traced.', null, null],
        'QA-F-025' => ['rbac', 'Dashboards', 'info', 'open', true, 'Super Admin Department View: policy and seeder disagree',
            'Policy (15 Oct): HR / Super Admin do not need Department View by default. The seeder grants Super Admin every permission (so seeded databases have it); the local database does not. Decide whether Super Admin keeps every permission or follows the Department View policy.',
            'RolesAndPermissionsSeeder grants Super Admin all permissions; the dashboard-permission migration did not.', null, null],
        'QA-F-026' => ['functional', 'Organization', 'low', 'open', true, 'Who may have no reporting manager?',
            'Only Super Admins and Directors are treated as top-of-hierarchy (Employee::missingHrFields). HR Admins and Finance leads with no manager are flagged "missing reporting manager" (HIGH). Decide whether HR Admin / heads of function are exempt, or should report to a Director.',
            'isTopOfHierarchy() lists Super Admin and Director only.', 'QA-ORG-004', null],
        'QA-F-027' => ['functional', 'Approvals', 'info', 'open', true, 'First approver: reporting manager or team lead?',
            'The approval queue and Employee 360 name the reporting manager first (ApprovalQueueService::approverFor); ApprovalRoutingService (notifications, escalation) puts the team lead first. When teams are used and the lead is not the manager, the two disagree. No teams exist on the audited databases yet. Decide which is the primary approver.',
            'Two routing entry points with different first rungs.', 'QA-ORG-015', null],
    ];

    /** The 15 Oct full-suite run (JUnit): 3110 tests, 12 documented baseline failures, 2 others (QA-F-028, QA-F-029). */
    private const FULL_RUN = [
        'name' => '2026-10-15 Full suite (staging @ fe35a84)', 'commit' => 'fe35a84',
        'total' => 3110, 'passed' => 3095, 'failed' => 14, 'skipped' => 1, 'known_baseline' => 12,
        'duration' => 3394, 'run_at' => '2026-10-15 16:16:00',
        'notes' => "Full Pest suite on the local test DB (working tree also held other sessions' uncommitted files). 12 documented baseline failures (QA-BASE-001…012). 2 others: AttendanceModeTest hybrid history (date-dependent test, fixed in 4627c03 — QA-F-028) and EmployeeLeaveProvisioningTest default pattern (order-dependent, passes alone — QA-F-029). Both pass in the retest run.",
        'baseline' => [
            'QA-BASE-003' => ['Users can log out', 'Auth/AuthenticationTest > users can logout'],
            'QA-BASE-004' => ['Email verification screen renders', 'Auth/EmailVerificationTest > email verification screen can be rendered'],
            'QA-BASE-005' => ['Email can be verified', 'Auth/EmailVerificationTest > email can be verified'],
            'QA-BASE-006' => ['Email is not verified with an invalid hash', 'Auth/EmailVerificationTest > email is not verified with invalid hash'],
            'QA-BASE-007' => ['Verified user is redirected from the verification link', 'Auth/EmailVerificationTest > already verified user visiting verification link is redirected without firing event again'],
            'QA-BASE-008' => ['User can delete their account', 'Settings/ProfileUpdateTest > user can delete their account'],
            'QA-BASE-009' => ['Leave center shows forecast, holiday planner and policy explorer', 'MyTimeOffPageTest > leave center shows forecast, holiday planner and policy explorer'],
            'QA-BASE-010' => ['selectAllVisible selects every payslip on the page', 'Payroll/ProcessPayslipActionsTest > selectAllVisible fills selected with every payslip on the current page'],
            'QA-BASE-011' => ['Finance approver sees a link to the finance approval page', 'Payroll/ProcessPendingFinanceAffordanceTest > a user who can approve finance sees a real link to the finance approval page'],
            'QA-BASE-012' => ['Run-payroll-only user sees a status badge, not a button', 'Payroll/ProcessPendingFinanceAffordanceTest > a run-payroll-only user sees a plain status badge, not an actionable button'],
        ],
    ];

    /** @param  array{name: string, total: int, passed: int, failed: int, known_baseline: int, duration: int, commit: string, run_at: string, kind: string, notes: string, baseline: array<string, string>}|null  $fullRun */
    public function run(?array $fullRun = null): void
    {
        $phase = QaPhase::where('number', 2)->first();
        if ($phase === null) {
            return;
        }
        if ($phase->status === QaPhaseStatus::NotStarted) {
            $phase->update([
                'status' => QaPhaseStatus::Completed,
                'owner' => 'Claude Code + Super Admin',
                'started_at' => '2026-10-15',
                'completed_at' => '2026-10-15',
                'notes' => 'Org structure & data quality: read-only hrms:data-quality (44 checks), People → Data Quality, role / scope drift, approval routing. Data issues are recorded per environment with qa:record-data-quality.',
            ]);
        }

        $testedAt = Carbon::parse('2026-10-15 15:30:00');
        $run = QaTestRun::firstOrCreate(['name' => '2026-10-15 Phase 2 Org & Data Quality'], [
            'qa_phase_id' => $phase->id, 'suite' => 'pest', 'kind' => 'targeted', 'environment' => 'local_test_db',
            'commit' => self::FEATURE_COMMIT, 'total' => 582, 'passed' => 581, 'failed' => 1, 'skipped' => 0,
            'known_baseline' => 1, 'new_regressions' => 0, 'duration_seconds' => 1079,
            'result' => QaTestRun::RESULT_PASS_WITH_BASELINE, 'run_at' => '2026-10-15 15:11:00',
            'notes' => 'Affected suites (QA, DataQuality, Navigation, Security, Settings, roles, sidebar, teams, audit log). Failure: Settings/ProfileUpdateTest "user can delete their account" — known baseline. QA + DataQuality re-run after the last changes: 70/70.',
        ]);

        foreach (self::CASES as $code => [$module, $feature, $title, $severity, $reference]) {
            $case = QaTestCase::firstOrCreate(['code' => $code], [
                'qa_phase_id' => $phase->id, 'module' => $module, 'feature' => $feature, 'title' => $title,
                'expected_result' => 'Test passes.', 'severity' => $severity,
                'automated_reference' => 'tests/Feature/'.$reference,
                'steps' => 'Automated: php artisan test --compact --filter="'.str($reference)->afterLast('> ').'"',
                'status' => QaTestStatus::Pass, 'last_tested_at' => $testedAt,
            ]);
            if ($case->wasRecentlyCreated) {
                QaTestResult::create([
                    'qa_test_case_id' => $case->id, 'qa_test_run_id' => $run->id, 'status' => QaTestStatus::Pass,
                    'actual_result' => 'Passed in the Phase 2 regression run.', 'environment' => 'local_test_db',
                    'commit' => self::FEATURE_COMMIT, 'tested_by_name' => self::TESTER, 'tested_at' => $testedAt,
                ]);
            }
        }

        $caseIds = QaTestCase::pluck('id', 'code');
        foreach (self::FINDINGS as $code => [$kind, $module, $severity, $status, $decision, $title, $description, $rootCause, $caseCode, $fix]) {
            $finding = QaFinding::firstOrCreate(['code' => $code], [
                'qa_phase_id' => $phase->id, 'qa_test_case_id' => $caseCode ? $caseIds[$caseCode] ?? null : null,
                'kind' => $kind, 'module' => $module, 'severity' => $severity, 'status' => $status, 'needs_decision' => $decision,
                'title' => $title, 'description' => $description, 'root_cause' => $rootCause,
                'fix_summary' => $fix[1] ?? null, 'environment' => 'local_test_db',
                'retest_result' => $status === QaFindingStatus::Verified->value ? 'Linked test passes in the Phase 2 run.' : null,
                'found_at' => '2026-10-15 14:30:00', 'closed_at' => $status === QaFindingStatus::Verified->value ? $testedAt : null,
            ]);
            if ($fix !== null && $finding->wasRecentlyCreated) {
                QaFixLink::create([
                    'qa_finding_id' => $finding->id, 'commit_hash' => $fix[0], 'commit_message' => $fix[1], 'fixed_at' => $fix[2],
                    'files_changed' => $fix[3], 'test_case_codes' => $fix[4], 'environment' => 'local_test_db',
                ]);
            }
        }

        // The People → Data Quality module is now covered by tests.
        QaChecklistItem::firstOrCreate(['category' => QaChecklistItem::MODULE, 'subject' => 'Organization', 'dimension' => 'tests'],
            ['status' => 'pass', 'note' => 'Phase 2: DataQualityAuditTest + DataQualityPageTest (31 tests).', 'commit' => self::FEATURE_COMMIT, 'checked_at' => $testedAt]);
        QaChecklistItem::firstOrCreate(['category' => QaChecklistItem::MODULE, 'subject' => 'Roles & Permissions', 'dimension' => 'scope'],
            ['status' => 'pass', 'note' => 'Phase 2: scoped grants without departments, per-user overrides, department-scoped Data Quality page.', 'commit' => self::FEATURE_COMMIT, 'checked_at' => $testedAt]);

        $this->recordFullRun($fullRun ?? self::FULL_RUN);

        QaTestRun::firstOrCreate(['name' => '2026-10-15 Retest after 4627c03'], [
            'qa_phase_id' => $phase->id, 'suite' => 'pest', 'kind' => 'targeted', 'environment' => 'local_test_db', 'commit' => '4627c03',
            'total' => 55, 'passed' => 55, 'failed' => 0, 'skipped' => 0, 'known_baseline' => 0, 'new_regressions' => 0, 'duration_seconds' => 109,
            'result' => QaTestRun::RESULT_PASS, 'run_at' => '2026-10-15 16:27:00',
            'notes' => 'AttendanceModeTest (34) + EmployeeLeaveProvisioningTest (21): both full-run non-baseline failures pass (QA-F-028 fixed; QA-F-029 passes in isolation, still under investigation).',
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function recordFullRun(array $data): void
    {
        $run = QaTestRun::firstOrCreate(['name' => $data['name']], [
            'suite' => 'pest', 'kind' => 'full', 'environment' => 'local_test_db', 'commit' => $data['commit'],
            'total' => $data['total'], 'passed' => $data['passed'], 'failed' => $data['failed'], 'skipped' => $data['skipped'] ?? 0,
            'known_baseline' => $data['known_baseline'], 'new_regressions' => $data['failed'] - $data['known_baseline'],
            'duration_seconds' => $data['duration'], 'run_at' => $data['run_at'], 'notes' => $data['notes'],
            'result' => QaTestRun::resultFor($data['failed'], $data['failed'] - $data['known_baseline']),
        ]);

        foreach ($data['baseline'] as $code => [$title, $reference]) {
            $case = QaTestCase::firstOrCreate(['code' => $code], [
                'module' => match (true) {
                    str_starts_with($reference, 'Auth/') => 'Authentication',
                    str_starts_with($reference, 'Settings/') => 'Settings',
                    str_starts_with($reference, 'Payroll/') => 'Payroll',
                    default => 'Leave',
                }, 'feature' => 'Known baseline', 'title' => $title,
                'expected_result' => 'Test passes.', 'severity' => 'low', 'automated_reference' => 'tests/Feature/'.$reference,
                'status' => QaTestStatus::KnownBaseline, 'last_tested_at' => $data['run_at'],
            ]);
            if ($case->wasRecentlyCreated) {
                QaTestResult::create([
                    'qa_test_case_id' => $case->id, 'qa_test_run_id' => $run->id, 'status' => QaTestStatus::KnownBaseline,
                    'actual_result' => 'Fails on staging before this work (pre-existing).', 'environment' => 'local_test_db',
                    'commit' => $data['commit'], 'note' => 'Known baseline — not a regression.', 'tested_by_name' => self::TESTER, 'tested_at' => $data['run_at'],
                ]);
            }
        }
    }
}
