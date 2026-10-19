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
 * Phase 4 policy decisions (approved 16 Oct 2026) and their fixes in the QA
 * tracker, through QaTracker so every finding keeps its history:
 * test case → finding → fix commit → retest → verified. Branding and the
 * dynamic-theme coverage stay open for the Phase 5 UI/UX work.
 * QA records only; idempotent.
 */
class QaAuditPhaseFourDecisionsSeeder extends Seeder
{
    private const TESTER = 'Claude Code (automated Pest suite)';

    private const RETESTED_AT = '2026-10-19 21:30:00';

    private const PD = 'Phase4/PayableDaysTest > ';

    private const OT = 'Phase4/OvertimeCarryAndReportsTest > ';

    private const PA = 'Phase4/PayrollAccessTest > ';

    private const SV = 'Phase4/SettingsValidationTest > ';

    private const NF = 'Phase4/NotificationFollowUpTest > ';

    /** code => [module, title, test reference] */
    private const CASES = [
        'QA-P4-024' => ['Payroll', 'Absence, unpaid leave, paid leave and pending requests are classified once', self::PD.'absence, unpaid leave, paid leave and a pending request are classified once'],
        'QA-P4-025' => ['Payroll', 'A joiner is paid only the eligible working days from joining', self::PD.'a joiner is paid only the eligible working days from the joining date'],
        'QA-P4-026' => ['Payroll', 'Only the excess over the paid-leave balance is LWP, July → June leave year', self::PD.'only the excess over the paid-leave balance is LWP, in the July → June leave year'],
        'QA-P4-027' => ['Payroll', 'No attendance recorded: absences reported, not deducted', self::PD.'with no attendance recorded at all, absences are reported but not deducted'],
        'QA-P4-028' => ['Payroll', 'Engine, payslip snapshot and Payroll Attendance report agree', self::PD.'the payroll engine, the payslip snapshot and the Payroll Attendance report agree'],
        'QA-P4-029' => ['Payroll', 'A late correction becomes one arrear in the next open run, never twice', self::PD.'a correction after the run closed becomes one arrear in the next open run, never twice'],
        'QA-P4-030' => ['Overtime', 'Zero-hour approved OT is carried, never paid at 0 and lost', self::OT.'a zero-hour approved OT is not paid at 0: it is carried to the run after its hours exist'],
        'QA-P4-031' => ['Overtime', 'A record on a draft payslip is re-measured, not left stale', self::OT.'settling a record on a draft payslip re-measures its hours instead of keeping stale ones'],
        'QA-P4-032' => ['Reports', 'The OT report lists only approved OT as payable', self::OT.'the OT report lists only approved OT as payable, with its payroll status'],
        'QA-P4-033' => ['Reports', 'Manage OT export = screen; Payroll Overview PDF = selected year', self::OT.'Manage OT exports the filtered requests on screen, and Payroll Overview exports the selected year'],
        'QA-P4-034' => ['RBAC', 'Payroll pages: company-wide payroll roles only', self::PA.'payroll pages open for company-wide payroll roles only; Directors, heads, managers and employees are refused'],
        'QA-P4-035' => ['RBAC', 'Bank transfer needs export_bank_transfer; components need manage_salary_components', self::PA.'the bank-transfer file needs export_bank_transfer; salary components need manage_salary_components'],
        'QA-P4-036' => ['RBAC', 'A department-scoped payroll grant never reaches company-wide payroll', self::PA.'a department-scoped payroll grant reaches its department only, and never company-wide payroll'],
        'QA-P4-037' => ['RBAC', 'An employee downloads only their own payslip', self::PA.'an employee downloads only their own payslip'],
        'QA-P4-038' => ['RBAC', 'Directors: no employee pay by default; explicit aggregate department summary only', self::PA.'Directors see no employee pay by default; an explicit summary grant shows only their department total'],
        'QA-P4-039' => ['RBAC', 'Executive payroll cost needs company-wide payroll access', self::PA.'company payroll cost on the Executive view needs company-wide payroll access'],
        'QA-P4-040' => ['RBAC', 'Payroll approval steps by permission, never role name', self::PA.'payroll approval steps are decided by permission with company-wide reach, never by role name'],
        'QA-P4-041' => ['Settings', 'Company settings validate every saved field and keep valid data', self::SV.'company settings reject an invalid timezone, currency, colour or over-long contact field and keep the saved values'],
        'QA-P4-042' => ['Settings', 'Sidebar menu refuses invented keys and over-long labels', self::SV.'the sidebar menu refuses invented keys and over-long labels'],
        'QA-P4-043' => ['Settings', 'Holiday pay accepts only known pay types', self::SV.'holiday pay accepts only the known pay types'],
        'QA-P4-044' => ['RBAC', 'Starter-kit account teams are Super Admin only', 'Teams/TeamTest > starter-kit account teams are Super Admin only: everyone else is refused and never sees the menu entry'],
        'QA-P4-045' => ['Settings', 'Coordinators in the Settings menu only for assign_coordinators', 'Teams/TeamTest > the Coordinators page is in the Settings menu only for people who assign coordinators'],
        'QA-P4-046' => ['Notifications', 'A queued leave notice keeps the status it was sent with', self::NF.'a queued leave notice keeps the status it was sent with'],
        'QA-P4-047' => ['Notifications', 'A comment on a decided leave is a message, not the decision again', self::NF.'a comment on a decided leave is a message, never the decision again'],
        'QA-P4-048' => ['Notifications', 'A cancellation notifies the other side', self::NF.'a cancellation notifies the other side'],
        'QA-P4-049' => ['Notifications', 'Profile change requests reach approvers; decisions reach the employee', self::NF.'a profile change request reaches its approvers and the decision reaches the employee'],
        'QA-P4-050' => ['Notifications', 'The overdue-probation digest is weekly, not daily', self::NF.'the overdue-probation digest is sent once a week, not every day'],
        'QA-P4-051' => ['Notifications', 'Reading on the Notifications page clears the bell count', self::NF.'reading on the Notifications page clears the bell count cache'],
        'QA-P4-052' => ['Date/time', 'Notifications use each recipient\'s date and time format', 'Phase4/DateTimePreferenceTest > a notification uses each recipient\'s own date and time format'],
        'QA-P4-053' => ['Date/time', 'Team Attendance follows the reader\'s 12/24-hour format', 'Phase4/DateTimePreferenceTest > Team Attendance shows times in the reader\'s 12/24-hour format'],
    ];

    /**
     * The decided findings: [commit => message, ...], the test cases that verify them.
     *
     * @var array<string, array{0: array<string, string>, 1: array<int, string>}>
     */
    private const DECIDED = [
        'QA-F-050' => [['093e84c' => 'feat(payroll): one canonical payable-days service for engine, report and payslip'], ['QA-P4-024', 'QA-P4-025', 'QA-P4-027', 'QA-P4-028']],
        'QA-F-051' => [['093e84c' => 'feat(payroll): one canonical payable-days service for engine, report and payslip'], ['QA-P4-028', 'QA-P4-029']],
        'QA-F-052' => [['093e84c' => 'feat(payroll): one canonical payable-days service for engine, report and payslip'], ['QA-P4-024', 'QA-P4-028']],
        'QA-F-053' => [['093e84c' => 'feat(payroll): one canonical payable-days service for engine, report and payslip'], ['QA-P4-026']],
        'QA-F-054' => [['093e84c' => 'feat(payroll): one canonical payable-days service for engine, report and payslip',
            '7f1e8bc' => 'fix(overtime): carry unmeasured OT, payable means approved, exports match screens'], ['QA-P4-030', 'QA-P4-031', 'QA-P4-032']],
        'QA-F-055' => [['ac70fbf' => 'fix(rbac): permission-based, scoped payroll access; Directors get no pay by default'], ['QA-P4-040']],
        'QA-F-056' => [['ac70fbf' => 'fix(rbac): permission-based, scoped payroll access; Directors get no pay by default'], ['QA-P4-034', 'QA-P4-035', 'QA-P4-036', 'QA-P4-037']],
        'QA-F-057' => [['ac70fbf' => 'fix(rbac): permission-based, scoped payroll access; Directors get no pay by default'], ['QA-P4-038', 'QA-P4-039']],
        'QA-F-058' => [['e24c503' => 'fix(rbac): starter-kit account teams Super Admin only; Coordinators in the menu'], ['QA-P4-044']],
        'QA-F-059' => [['642e189' => 'fix(notifications): snapshot content, scope recipients and deduplicate reminders'], ['QA-P4-046', 'QA-P4-047']],
        'QA-F-062' => [['16d28a7' => 'fix(datetime): respect recipient and viewer date-time preferences'], ['QA-P4-052', 'QA-P4-053']],
        'QA-F-063' => [['642e189' => 'fix(notifications): snapshot content, scope recipients and deduplicate reminders'], ['QA-P4-048', 'QA-P4-049', 'QA-P4-050', 'QA-P4-051']],
        'QA-F-064' => [['e24c503' => 'fix(rbac): starter-kit account teams Super Admin only; Coordinators in the menu',
            'e0f7e36' => 'fix(settings): validate company, sidebar menu and holiday pay fields server-side'], ['QA-P4-041', 'QA-P4-042', 'QA-P4-043', 'QA-P4-045']],
    ];

    /** Phase 4 decision notes per finding (appended to the description). */
    private const DECISION_NOTES = [
        'QA-F-050' => 'Decision 1 (16 Oct): pay actual eligible working days — joiners / leavers prorated, final unexplained absence LOP, unresolved exceptions not deducted. LOP day = earnings ÷ eligible working days (was ÷ 26).',
        'QA-F-051' => 'Decision 3: payslips snapshot their figures (payable_snapshot); the PDF reads the snapshot; late corrections become adjustments in the next open run.',
        'QA-F-052' => 'Decision 2: one PayableDaysService for engine, payslip and Payroll Attendance report / register.',
        'QA-F-053' => 'Decision 4: only the excess over the balance is LWP, July → June leave year.',
        'QA-F-054' => 'Decision 5: payable OT = approved request + genuine attendance; 0-hour records carried; unapproved OT never payable.',
        'QA-F-055' => 'Decision 6: payroll approval by permission (approve_finance / approve_payroll) with company-wide reach.',
        'QA-F-056' => 'Decision 7: view_payroll scope enforced; company-wide operations need company reach; bank transfer needs export_bank_transfer.',
        'QA-F-057' => 'Decision 8: Directors have no employee pay by default; view_department_payroll_summary is an explicit aggregate-only grant.',
        'QA-F-058' => 'Decision 9: starter-kit account teams restricted to Super Admin, labelled "Account Teams (legacy)".',
    ];

    public function run(?array $fullRun = null): void
    {
        $tracker = app(QaTracker::class);
        $actor = new User(['name' => 'Phase 4 decisions']);
        $phase = QaPhase::where('number', 4)->first();
        $at = Carbon::parse(self::RETESTED_AT);

        foreach (self::CASES as $code => [$module, $title, $reference]) {
            $case = QaTestCase::firstOrCreate(['code' => $code], [
                'qa_phase_id' => $phase?->id, 'module' => $module, 'feature' => 'Phase 4 decisions', 'title' => $title,
                'expected_result' => 'Behaves as the approved Phase 4 policy states, through every consumer.', 'severity' => 'medium',
                'automated_reference' => 'tests/Feature/'.$reference, 'status' => QaTestStatus::Pass, 'last_tested_at' => $at,
            ]);
            if ($case->wasRecentlyCreated) {
                QaTestResult::create(['qa_test_case_id' => $case->id, 'status' => QaTestStatus::Pass, 'actual_result' => 'Passed after the fix.',
                    'environment' => 'local_test_db', 'tested_by_name' => self::TESTER, 'tested_at' => $at, 'is_retest' => true]);
            }
        }

        // Decision 13: two exports that did not match their screen.
        if (! QaFinding::where('code', 'QA-F-065')->exists()) {
            $tracker->openFinding(['code' => 'QA-F-065', 'qa_phase_id' => $phase?->id, 'kind' => 'functional', 'module' => 'Reports', 'severity' => 'medium',
                'title' => 'Manage OT CSV and Payroll Overview PDF did not export what the screen showed',
                'description' => 'Manage OT "Export CSV" downloaded this month\'s payable OT records instead of the filtered requests on screen; Payroll Overview "Export PDF" ignored the selected year.',
                'root_cause' => 'Export links built independently of the screen\'s filters.', 'environment' => 'local_test_db', 'found_at' => '2026-10-16 12:00:00'], $actor);
        }

        foreach (self::DECIDED + ['QA-F-065' => [['7f1e8bc' => 'fix(overtime): carry unmeasured OT, payable means approved, exports match screens'], ['QA-P4-033']]] as $code => [$commits, $tests]) {
            $finding = QaFinding::where('code', $code)->first();
            if ($finding === null || $finding->status === QaFindingStatus::Verified) {
                continue;
            }
            if (isset(self::DECISION_NOTES[$code]) && $finding->needs_decision) {
                $tracker->updateFinding($finding, ['needs_decision' => false,
                    'description' => $finding->description."\n\n".self::DECISION_NOTES[$code]], $actor);
            }
            foreach ($commits as $hash => $message) {
                if (! $finding->fixes()->where('commit_hash', $hash)->exists()) {
                    $tracker->linkFix($finding->fresh(), ['commit_hash' => $hash, 'commit_message' => $message, 'test_case_codes' => $tests,
                        'environment' => 'local_test_db', 'fixed_at' => $at], $actor);
                }
            }
            $tracker->changeFindingStatus($finding->fresh(), QaFindingStatus::Verified, $actor,
                'Retest '.$at->format('d M Y').': '.implode(', ', $tests).' pass; affected suites green.');
        }

        // The rest of the date / time work outside the prioritised pages.
        if (! QaFinding::where('code', 'QA-F-066')->exists()) {
            $tracker->openFinding(['code' => 'QA-F-066', 'qa_phase_id' => $phase?->id, 'kind' => 'ui', 'module' => 'Date/time', 'severity' => 'low',
                'title' => 'Remaining hardcoded date / time formats outside the prioritised pages',
                'description' => 'Notifications and the interactive attendance, OT, payroll, time-off, performance and settings pages follow the user\'s preference (16d28a7). Still fixed: dashboards widgets, onboarding / employee edit timelines, some reports\' screen rows, and instants (created_at) not shifted to the reader\'s timezone. PDFs, letters and CSV exports are fixed-format on purpose.',
                'risk' => 'Mixed formats on lower-traffic screens.', 'environment' => 'local', 'found_at' => '2026-10-19 21:00:00'], $actor);
        }

        if ($fullRun !== null) {
            QaTestRun::firstOrCreate(['name' => $fullRun['name']], [
                'qa_phase_id' => $phase?->id, 'suite' => 'pest', 'kind' => 'full', 'environment' => 'local_test_db', 'commit' => $fullRun['commit'],
                'total' => $fullRun['total'], 'passed' => $fullRun['passed'], 'failed' => $fullRun['failed'], 'skipped' => $fullRun['skipped'],
                'known_baseline' => $fullRun['known_baseline'], 'new_regressions' => $fullRun['failed'] - $fullRun['known_baseline'],
                'duration_seconds' => $fullRun['duration'], 'run_at' => $fullRun['run_at'], 'notes' => $fullRun['notes'],
                'result' => QaTestRun::resultFor($fullRun['failed'], $fullRun['failed'] - $fullRun['known_baseline']),
            ]);

            if ($phase !== null && $fullRun['failed'] === $fullRun['known_baseline'] && $phase->status !== QaPhaseStatus::Completed) {
                $tracker->updatePhase($phase, ['status' => QaPhaseStatus::Completed->value, 'completed_at' => '2026-10-19',
                    'notes' => 'Phase 4 closed: canonical payable days (engine = payslip = report), LWP excess + July–June year, OT carry-forward, permission-based and scoped payroll, Director pay narrowed, starter-kit Teams restricted, notification and report follow-ups, date/time for notifications and interactive pages. Full suite at a stable HEAD: only the 12 known baseline failures. Branding and theme coverage (QA-F-060 / 061) and remaining date formats (QA-F-066) move to Phase 5.'], $actor);
            }
        }
    }
}
