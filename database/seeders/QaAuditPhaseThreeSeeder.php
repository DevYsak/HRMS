<?php

namespace Database\Seeders;

use App\Enums\QaFindingStatus;
use App\Enums\QaPhaseStatus;
use App\Enums\QaTestStatus;
use App\Models\QaFinding;
use App\Models\QaPhase;
use App\Models\QaTestCase;
use App\Models\QaTestResult;
use App\Models\QaTestRun;
use App\Models\User;
use App\Services\Qa\QaTracker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Phase 3 (attendance, leave, OT, WFH and performance consistency;
 * 15–16 Oct 2026) in the QA tracker, through QaTracker so every finding
 * keeps its history: test case → finding → fix commit → retest → verified.
 * P3-07 (WFH) stays open with its read-only data check for a decision.
 * QA records only; idempotent (codes already present are left alone).
 */
class QaAuditPhaseThreeSeeder extends Seeder
{
    private const TESTER = 'Claude Code (automated Pest suite)';

    private const RETESTED_AT = '2026-10-16 10:00:00';

    /** code => [module, title, test file › name] */
    private const CASES = [
        'QA-P3-001' => ['Security', 'Only Super Admin and HR Admin hold credential management; only a Super Admin hands it on', 'Security/EmployeeCredentialsTest > the permission is held by Super Admin and HR Admin only, and only a Super Admin can hand it on'],
        'QA-P3-002' => ['Security', 'Super Admin and HR Admin manage credentials', 'Security/EmployeeCredentialsTest > Super Admin and HR Admin can manage credentials'],
        'QA-P3-003' => ['Security', 'A Director cannot touch credentials in their own department; direct calls fail', 'Security/EmployeeCredentialsTest > a Director cannot touch credentials, even inside their own department — direct calls fail'],
        'QA-P3-004' => ['Security', 'Department Heads, Managers, Coordinators and Finance have no credential control', 'Security/EmployeeCredentialsTest > Department Heads and Managers have no credential control'],
        'QA-P3-005' => ['Security', 'Impersonation uses the target\'s permissions; the audit names actor and target', 'Security/EmployeeCredentialsTest > a Super Admin viewing as HR may act with HR permissions; the audit names the acting account and the target'],
        'QA-P3-006' => ['Attendance', 'Today: Team View, AI and the attendance window classify the same day alike', 'Phase3/AttendanceConsistencyTest > today: Team View, AI and the window agree Ava is late and both are in'],
        'QA-P3-007' => ['Attendance', 'Range: Employee 360, Monthly report and Compliance CSV give the same counts', 'Phase3/AttendanceConsistencyTest > the range: Employee 360, the Monthly report and the Compliance CSV give the same counts'],
        'QA-P3-008' => ['Leave', 'My Leave, AI, Employee 360 and the Leave Summary agree on the balance', 'Phase3/LeaveSummaryConsistencyTest > My Leave, AI, Employee 360 and the Leave Summary report agree on the balance'],
        'QA-P3-009' => ['Leave', 'The Leave Summary uses the leave year, never a calendar-year row', 'Phase3/LeaveSummaryConsistencyTest > the Leave Summary never reads a calendar-year row for another leave year'],
        'QA-P3-010' => ['Overtime', 'OvertimeService keeps requested, approved requested, actual worked and payable OT apart', 'Phase3/OvertimeConsistencyTest > the service keeps the four figures apart'],
        'QA-P3-011' => ['Overtime', 'Payable OT reads the same on every screen', 'Phase3/OvertimeConsistencyTest > Payable OT reads the same on the employee dashboard, My Overtime, AI, Team View and Employee 360'],
        'QA-P3-012' => ['Overtime', 'OT reports label which figure each column holds', 'Phase3/OvertimeConsistencyTest > the OT reports label which figure each column holds'],
        'QA-P3-013' => ['Dashboards', 'HR home: waiting for your decision / open in scope / needs more info are separate', 'Phase3/HrHomeConsistencyTest > P3-04: "waiting for your decision", "open in your scope" and "needs more info" are separate and labelled'],
        'QA-P3-014' => ['Dashboards', 'HR home, People Operations, Team View and AI agree on attendance today', 'Phase3/HrHomeConsistencyTest > P3-05: the HR home, People Operations, Team View and AI give the same attendance today'],
        'QA-P3-015' => ['RBAC', 'Exactly the roles that opened Attendance Reports hold view_attendance_reports', 'Phase3/AttendanceReportsPermissionTest > exactly the roles that could open Attendance Reports hold the permission; employees do not'],
        'QA-P3-016' => ['RBAC', 'Finance reads Attendance Reports company-wide with no management power', 'Phase3/AttendanceReportsPermissionTest > Finance reads company-wide but gets no attendance-management power'],
        'QA-P3-017' => ['Performance', 'One current cycle and status vocabulary across dashboard, Team View, 360 and AI', 'Phase3/PerformanceConsistencyTest > the employee dashboard, Team View, Employee 360 and AI use the same cycle and states'],
        'QA-P3-018' => ['Performance', 'Performance CSV status matches the screens', 'Phase3/PerformanceConsistencyTest > the performance CSV shows the same status as the screens, with the workflow step beside it'],
    ];

    /**
     * finding => [existing?, kind, module, severity, title, description, root cause, tests, commit, commit message, files]
     *
     * @var array<string, array<int, mixed>>
     */
    private const FIXED = [
        'QA-F-030' => [true, null, null, null, null, null, null, ['QA-P3-001', 'QA-P3-002', 'QA-P3-003', 'QA-P3-004', 'QA-P3-005'], 'dd47f75', 'fix(rbac): separate employee credential management permission',
            ['database/migrations/2026_10_15_175953_add_manage_employee_credentials_permission.php', 'app/Policies/EmployeePolicy.php', 'app/Livewire/Employees/EmployeeEdit.php', 'app/Livewire/Employees/EmployeeCreate.php']],
        'QA-F-021' => [true, null, null, null, null, null, null, ['QA-P3-015', 'QA-P3-016'], '2d29468', 'fix(rbac): give Attendance Reports a dedicated read-only permission',
            ['database/migrations/2026_10_15_182224_add_view_attendance_reports_permission.php', 'app/Livewire/Attendance/AttendanceReports.php', 'app/Http/Controllers/ReportController.php']],
        'QA-F-032' => [false, 'functional', 'Attendance', 'high', 'P3-01 Attendance Compliance CSV counted statuses that are never stored',
            'The CSV counted status "present" and "unauthorized_absence"; rows are on_time / late, so Present was always 0 and absences never counted. The Monthly report counted holidays and approved leave as absences (weekdays − present), the Late report used the stored is_late flag while Team View used the calculator, and Monthly / Late / Absent reports left out probation and on-leave staff.',
            'Reports classified days with their own rules instead of AttendanceWindowService / AttendanceCalculator.',
            ['QA-P3-006', 'QA-P3-007'], 'bd1d2da', 'fix(attendance): classify report days through the canonical attendance rules',
            ['app/Http/Controllers/ReportController.php', 'app/Services/AttendanceReportBuilder.php', 'app/Services/Attendance/AttendanceWindowService.php']],
        'QA-F-033' => [false, 'functional', 'Leave', 'high', 'P3-02 Leave Summary double-counted carried forward and used the calendar year',
            'Opening = allocated + carried forward (allocated already includes it); closing ignored accruals, adjustments and expiry; the year was the calendar year of the range end, not the leave year. Comp-Off summary had the same year bug.',
            'The report read stored balance columns instead of LeaveBalanceCalculator and LeaveYearResolver.',
            ['QA-P3-008', 'QA-P3-009'], 'b50a8a3', 'fix(leave): build the Leave Summary from the canonical balance and leave year',
            ['app/Services/AttendanceReportBuilder.php', 'tests/Feature/HrFormatReportsTest.php']],
        'QA-F-034' => [false, 'functional', 'Overtime', 'medium', 'P3-03 / P3-08 Three unlabelled OT-hour definitions',
            'Requested, approved-requested and payable OT were all shown as "OT hours"; Team View summed the table directly.',
            'No explicit OvertimeService API; consumers queried tables.',
            ['QA-P3-010', 'QA-P3-011', 'QA-P3-012'], 'a474909', 'fix(overtime): keep the four OT figures apart and label every screen',
            ['app/Services/OvertimeService.php', 'app/Livewire/ManagerDashboard.php', 'app/Services/EmployeeDashboardService.php', 'app/Services/People/Employee360Service.php', 'app/Http/Controllers/ReportController.php']],
        'QA-F-035' => [false, 'functional', 'Dashboards', 'medium', 'P3-04 HR home "Waiting for a decision" mixed three meanings',
            'It counted every pending request in scope by status, while the Approvals badge counts only what the viewer can decide; more-info requests were not distinguished.',
            'OrganisationOverview::approvals() answered a different question than ApprovalQueueService under the same label.',
            ['QA-P3-013'], 'd29aafb', 'fix(dashboards): separate HR-home decision counts and use the canonical today status',
            ['app/Services/Dashboards/OrganisationOverview.php', 'resources/views/dashboard/hr.blade.php']],
        'QA-F-036' => [false, 'functional', 'Dashboards', 'medium', 'P3-05 HR home attendance today: a second loop over a different population',
            'OrganisationOverview::attendanceToday() re-implemented TodayStatusService and counted the viewer, so it disagreed with People Operations, Team View and AI by one.',
            'Parallel implementation of the today status.',
            ['QA-P3-014'], 'd29aafb', 'fix(dashboards): separate HR-home decision counts and use the canonical today status',
            ['app/Services/Dashboards/OrganisationOverview.php']],
        'QA-F-038' => [false, 'functional', 'Performance', 'medium', 'P3-06 Employee dashboard chose its own cycle and raw review status',
            'The dashboard took the latest active / completed / locked cycle by start date (a later locked cycle won) and showed the workflow status; the performance CSV Status column was the workflow step, not the UI state.',
            'The dashboard and CSV did not use PerformanceStatusService.',
            ['QA-P3-017', 'QA-P3-018'], 'edf0729', 'fix(performance): use PerformanceStatusService for the current cycle and review states',
            ['app/Services/EmployeeDashboardService.php', 'app/Http/Controllers/ReportController.php']],
        'QA-F-039' => [false, 'functional', 'Overtime', 'low', 'P3-09 Unused OTService alias beside OvertimeService',
            'OTService was an empty subclass of OvertimeService with no references (checked here, in tests, config and the sibling biometric / ADMS projects).',
            'Leftover alias.',
            ['QA-P3-010'], '1c820e9', 'refactor(overtime): remove the unused OTService alias',
            ['app/Services/OTService.php']],
    ];

    public function run(): void
    {
        $tracker = app(QaTracker::class);
        $actor = new User(['name' => 'Phase 3 consistency fixes']);
        $phase = QaPhase::where('number', 3)->first();
        $at = Carbon::parse(self::RETESTED_AT);

        if ($phase !== null && $phase->status === QaPhaseStatus::NotStarted) {
            $tracker->updatePhase($phase, ['status' => QaPhaseStatus::InProgress->value, 'owner' => 'Claude Code + Super Admin', 'started_at' => '2026-10-15',
                'notes' => 'Attendance, leave, OT, WFH and performance cross-module consistency. Every fix: test case → finding → commit → retest → verified. P3-07 (WFH) awaits a decision.'], $actor);
        }

        foreach (self::CASES as $code => [$module, $title, $reference]) {
            $case = QaTestCase::firstOrCreate(['code' => $code], [
                'qa_phase_id' => $phase?->id, 'module' => $module, 'feature' => 'Cross-module consistency', 'title' => $title,
                'expected_result' => 'The same value / state through every applicable consumer.', 'severity' => 'medium',
                'automated_reference' => 'tests/Feature/'.$reference, 'status' => QaTestStatus::Pass, 'last_tested_at' => $at,
            ]);
            if ($case->wasRecentlyCreated) {
                QaTestResult::create(['qa_test_case_id' => $case->id, 'status' => QaTestStatus::Pass, 'actual_result' => 'Passed after the fix.',
                    'environment' => 'local_test_db', 'tested_by_name' => self::TESTER, 'tested_at' => $at, 'is_retest' => true]);
            }
        }

        foreach (self::FIXED as $code => [$existing, $kind, $module, $severity, $title, $description, $rootCause, $tests, $commit, $message, $files]) {
            $finding = QaFinding::where('code', $code)->first();
            if ($finding === null && ! $existing) {
                $finding = $tracker->openFinding(['code' => $code, 'qa_phase_id' => $phase?->id, 'kind' => $kind, 'module' => $module, 'severity' => $severity,
                    'title' => $title, 'description' => $description, 'root_cause' => $rootCause, 'environment' => 'local_test_db', 'found_at' => '2026-10-15 18:00:00'], $actor);
            }
            if ($finding === null || $finding->fixes()->where('commit_hash', $commit)->exists()) {
                continue;
            }
            $tracker->linkFix($finding, ['commit_hash' => $commit, 'commit_message' => $message, 'files_changed' => $files, 'test_case_codes' => $tests,
                'environment' => 'local_test_db', 'fixed_at' => $at], $actor);
            $tracker->changeFindingStatus($finding->fresh(), QaFindingStatus::Verified, $actor,
                'Retest '.$at->format('d M Y').': '.implode(', ', $tests).' pass; full suite shows no new regressions.');
        }

        $this->recordRuns($phase);

        // P3-07: read-only data check; behaviour unchanged until decided.
        if (! QaFinding::where('code', 'QA-F-037')->exists()) {
            $tracker->openFinding(['code' => 'QA-F-037', 'qa_phase_id' => $phase?->id, 'kind' => 'data', 'module' => 'WFH', 'severity' => 'medium', 'needs_decision' => true,
                'title' => 'P3-07 Approved WFH requests and attendance work mode are independent',
                'description' => "Read-only comparison on the local database (15 Oct 2026): 42 attendance days recorded as work_mode = wfh across 8 employees (30 Jun – 10 Aug 2026); none is covered by an approved or pending WFH request. 3 pending WFH requests have no attendance. Employees choose their work mode when they punch in (My Attendance / dashboard punch card); the WFH request is a separate approval flow and nothing links the two.\nDecide: approved WFH drives attendance mode, or attendance mode stays independently recorded. Nothing was corrected.",
                'risk' => 'A day can count as WFH in attendance history / reports but not in WFH approvals, or the reverse.',
                'environment' => 'local', 'found_at' => '2026-10-15 19:00:00'], $actor);
        }
    }

    /**
     * The confirming full run after the Phase 3 fixes, and the re-run of the
     * files a concurrent commit disturbed while it was in flight.
     */
    private function recordRuns(?QaPhase $phase): void
    {
        QaTestRun::firstOrCreate(['name' => '2026-10-16 Phase 3 full suite'], [
            'qa_phase_id' => $phase?->id, 'suite' => 'pest', 'kind' => 'full', 'environment' => 'local_test_db', 'commit' => '1c820e9',
            'total' => 3155, 'passed' => 3126, 'failed' => 28, 'skipped' => 1, 'known_baseline' => 12, 'new_regressions' => 0,
            'duration_seconds' => 4642, 'run_at' => '2026-10-16 10:49:00', 'result' => QaTestRun::resultFor(28, 0),
            'notes' => 'Full Pest suite at 1c820e9 (after every Phase 3 fix). 12 failures are the known baseline (QA-BASE-001…012). The other 16 (LeaveAssistantTest ×2, LeaveAttachmentUploadTest ×11, MyLeaveBalancesTest ×1, UiHardeningTest ×2) were ViewExceptions "MyTimeOff::earliestLeaveDate does not exist": commit 905bae5 (leave, another workstream) changed the Time Off views at 11:39 while the suite was running. Re-run of those four files on 905bae5: 37/37 pass — not regressions.',
        ]);

        QaTestRun::firstOrCreate(['name' => '2026-10-16 Phase 3 re-run of disturbed files'], [
            'qa_phase_id' => $phase?->id, 'suite' => 'pest', 'kind' => 'targeted', 'environment' => 'local_test_db', 'commit' => '905bae5',
            'total' => 37, 'passed' => 37, 'failed' => 0, 'skipped' => 0, 'known_baseline' => 0, 'new_regressions' => 0,
            'duration_seconds' => 206, 'run_at' => '2026-10-16 12:12:00', 'result' => QaTestRun::resultFor(0, 0),
            'notes' => 'LeaveAssistantTest, LeaveAttachmentUploadTest, MyLeaveBalancesTest, UiHardeningTest — the files that failed only because their views changed mid-run.',
        ]);
    }
}
