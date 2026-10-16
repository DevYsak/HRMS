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
 * Phase 4 (payroll, settings, theme, notifications, AI, reports, date/time;
 * 16 Oct 2026) in the QA tracker, through QaTracker so every finding keeps
 * its history: test case → finding → fix commit → retest → verified.
 * Payroll formula mismatches and RBAC policy questions are opened for a
 * decision — payroll business rules are not changed without one.
 * QA records only; idempotent (codes already present are left alone).
 */
class QaAuditPhaseFourSeeder extends Seeder
{
    private const TESTER = 'Claude Code (automated Pest suite)';

    private const RETESTED_AT = '2026-10-16 17:30:00';

    /** code => [module, title, test file › name] */
    private const CASES = [
        'QA-P4-001' => ['AI', 'My attendance and missing checkout match My Attendance', 'Phase4/AiAssistantConsistencyTest > my attendance and missing checkout come from the attendance window My Attendance uses'],
        'QA-P4-002' => ['AI', 'Next holiday, WFH status and profile completeness from canonical services', 'Phase4/AiAssistantConsistencyTest > next holiday, WFH status and profile completeness use the canonical services'],
        'QA-P4-003' => ['AI', 'Pending: own requests for the requester, approval queue for the approver, in scope', 'Phase4/AiAssistantConsistencyTest > pending questions: own requests for the requester, the approval queue for the approver, within scope'],
        'QA-P4-004' => ['AI', 'The model context holds one pending figure (the approval queue), no names', 'Phase4/AiAssistantConsistencyTest > the model context carries no second pending count, only the approval queue'],
        'QA-P4-005' => ['AI', '"Where do I view payroll" links only to pages the role can open', 'Phase4/AiAssistantConsistencyTest > "where do I view payroll" points each role at a page it can open'],
        'QA-P4-006' => ['RBAC', 'Increment Center: HR sees every proposal and the pool', 'Phase4/IncrementCenterScopeTest > HR sees every proposal and the payroll pool'],
        'QA-P4-007' => ['RBAC', 'Increment Center: a department-scoped Director sees only their department', 'Phase4/IncrementCenterScopeTest > a department-scoped Director sees only their department, never the pool'],
        'QA-P4-008' => ['RBAC', 'Increment Center: out-of-reach and cycle-wide direct calls are refused', 'Phase4/IncrementCenterScopeTest > direct calls outside the reach or on the whole cycle are refused'],
        'QA-P4-009' => ['Settings', 'Attendance Settings saves shift, OT rate and check-in requirements', 'Phase4/AttendanceSettingsSaveTest > the general fields load, save and persist'],
        'QA-P4-010' => ['Settings', 'Attendance Settings rejects bad values', 'Phase4/AttendanceSettingsSaveTest > bad values are rejected and nothing is written'],
        'QA-P4-011' => ['Cache', 'My Attendance month cache follows settings, holidays and preferences', 'Phase4/SettingsCacheTest > the My Attendance month cache key follows settings, holidays and preferences'],
        'QA-P4-012' => ['Cache', 'A queue job reads the working week saved elsewhere', 'Phase4/SettingsCacheTest > a queue job reads the working week saved by another process'],
        'QA-P4-013' => ['Notifications', 'The employee copy of every notification opens for the employee', 'Phase4/NotificationLinksTest > the employee copy of every notification opens for the employee'],
        'QA-P4-014' => ['Notifications', 'HR still lands on the HR pages', 'Phase4/NotificationLinksTest > HR still lands on the HR pages'],
        'QA-P4-015' => ['Notifications', 'A manager never gets an HR-only link', 'Phase4/NotificationLinksTest > a manager without the HR permission never gets an HR-only link'],
        'QA-P4-016' => ['Notifications', 'Leave notices reach only the HR who cover the employee', 'Phase4/LeaveNotificationScopeTest > only the HR who cover the employee are notified'],
        'QA-P4-017' => ['Notifications', 'Every leave-notice recipient gets a link they can open', 'Phase4/LeaveNotificationScopeTest > each recipient gets a link they can open'],
        'QA-P4-018' => ['Reports', 'Leave Utilization fills Days and covers the leave year', 'Phase4/LeaveReportCsvTest > leave utilisation fills Days and covers the leave year'],
        'QA-P4-019' => ['Reports', 'Leave Encashment fills Days; no never-stored Amount', 'Phase4/LeaveReportCsvTest > leave encashment fills Days and drops the never-stored Amount'],
        'QA-P4-020' => ['RBAC', 'Employee export writes pay and bank details only for view_finance_profile', 'Security/EmployeeExportScopeTest > pay, statutory IDs and bank details are exported only to view_finance_profile holders (Phase 4)'],
        'QA-P4-021' => ['Date/time', 'A 24-hour reader sees 24-hour times on My Attendance', 'Phase4/MyAttendanceTimeFormatTest > a 24-hour reader sees 24-hour times in the history and the timeline'],
        'QA-P4-022' => ['Date/time', 'A 12-hour reader sees AM / PM everywhere on My Attendance', 'Phase4/MyAttendanceTimeFormatTest > a 12-hour reader sees AM / PM everywhere, including the history table'],
        'QA-P4-023' => ['Settings', 'Settings master-data tables scroll on phones', 'Phase4/SettingsTablesScrollTest > settings master-data tables scroll horizontally on small screens'],
    ];

    /**
     * finding => [kind, module, severity, title, description, root cause, tests, commit, commit message, files]
     *
     * @var array<string, array<int, mixed>>
     */
    private const FIXED = [
        'QA-F-040' => ['functional', 'AI', 'medium', 'AI answered own-data questions from the model or not at all; context carried a second pending count',
            'My attendance, missing checkout, next holiday, WFH status and profile completeness had no deterministic answer. "Leave pending?" from an approver reported their own requests. The model context carried pending_leave_requests / pending_ot_requests (status pending only, own requests included) beside waiting_for_my_approval — two numbers for one question. "Where do I view payroll" matched no topic.',
            'AiIntentRouter / AiContextBuilder not wired to every canonical service.',
            ['QA-P4-001', 'QA-P4-002', 'QA-P4-003', 'QA-P4-004', 'QA-P4-005'], '30caa3d', 'fix(ai): answer own-data questions from the canonical services',
            ['app/Services/Ai/AiDataTools.php', 'app/Services/Ai/AiIntentRouter.php', 'app/Services/Ai/AiHelpLinks.php', 'app/Services/Ai/AiContextBuilder.php']],
        'QA-F-041' => ['rbac', 'Performance', 'high', 'Increment Center ignored the manage_employees scope',
            'Gated only by manage_employees, it showed every proposal company-wide: a department-scoped Director saw every employee\'s current gross and the payroll pool, and could edit or override other departments\' proposals and open / generate / submit cycles.',
            'Component checked the permission, never the reach.',
            ['QA-P4-006', 'QA-P4-007', 'QA-P4-008'], 'b3ed78f', 'fix(rbac): scope the Increment Center to the manage_employees reach',
            ['app/Livewire/Performance/IncrementCenter.php', 'resources/views/livewire/performance/increment-center.blade.php']],
        'QA-F-042' => ['functional', 'Settings', 'high', 'Attendance Settings could not save shift, OT rate or check-in requirements',
            'wire:model on the Eloquent $settings threw "Can\'t set model properties directly" (legacy model binding off) and save() never wrote those six columns.',
            'Model binding on an Eloquent property under Livewire 4.',
            ['QA-P4-009', 'QA-P4-010'], '43741e0', 'fix(settings): save the fallback shift, OT rate and check-in requirements',
            ['app/Livewire/Attendance/AttendanceSettings.php', 'resources/views/livewire/attendance/attendance-settings.blade.php']],
        'QA-F-043' => ['functional', 'Cache', 'medium', 'Settings changes stayed stale in My Attendance history and queue workers',
            'The 10-minute month cache key ignored attendance settings, shifts, the employee, holidays and the reader\'s format; queue workers kept the static weekly-off memo until restart.',
            'Incomplete cache signature; static memo not flushed per job.',
            ['QA-P4-011', 'QA-P4-012'], '129816a', 'fix(cache): settings changes reach My Attendance and queue workers',
            ['app/Livewire/Attendance/AttendanceTracker.php', 'app/Providers/AppServiceProvider.php']],
        'QA-F-044' => ['functional', 'Notifications', 'high', 'Employees got notification links to HR-only pages (403)',
            'Incentive, reimbursement, warning escalation, weekly PIP review, onboarding completed / overdue and offboarding details linked every recipient — the employee included — to HR pages.',
            'Static URLs regardless of recipient.',
            ['QA-P4-013', 'QA-P4-014', 'QA-P4-015'], '4884b45', 'fix(notifications): link each recipient to a page they can open',
            ['app/Notifications/Concerns/LinksForRecipient.php', 'app/Notifications/IncentiveNotification.php', 'app/Notifications/WarningEscalatedNotification.php']],
        'QA-F-045' => ['rbac', 'Notifications', 'medium', 'Leave notices broadcast to every HR admin; team leads got 403 links',
            'New leave, pending-HR, message and escalation notices went to all HR / Super Admins (hrQueue) — scoped HR saw other departments\' names; a team lead without approve_leave got a Team Leave link that refused them.',
            'hrQueue() instead of hrCovering(); link not recipient-aware.',
            ['QA-P4-016', 'QA-P4-017'], '32017f6', 'fix(notifications): leave notices reach covering HR, with links each can open',
            ['app/Services/LeaveService.php', 'app/Console/Commands/EscalateLeaveRequests.php', 'app/Notifications/LeaveRequestNotification.php']],
        'QA-F-046' => ['functional', 'Reports', 'high', 'Leave Utilization and Leave Encashment CSVs: Days / Amount always blank',
            'Utilization read $r->total_days (column is days) and filtered by calendar year; Encashment read $r->days and $r->amount (columns are requested_days; no amount is stored).',
            'Columns that do not exist.',
            ['QA-P4-018', 'QA-P4-019'], '981f2dc', 'fix(reports): fill Days in the leave utilisation and encashment CSVs',
            ['app/Http/Controllers/ReportController.php']],
        'QA-F-047' => ['rbac', 'Employees', 'medium', 'Employee export wrote pay and bank details without view_finance_profile',
            'Export existing employees needs create_employee + manage_employees reach but wrote unmasked CTC, PAN, Aadhaar, bank account and IFSC, which the profile page hides without view_finance_profile.',
            'Export bypassed the finance-profile permission.',
            ['QA-P4-020'], 'e55babf', 'fix(rbac): export pay and bank details only with view_finance_profile',
            ['app/Exports/EmployeesExport.php', 'app/Livewire/Employees/EmployeeImport.php']],
        'QA-F-048' => ['ui', 'Attendance', 'high', 'My Attendance ignored the 12/24-hour preference',
            'Timeline, KPIs, alerts and today\'s activity hardcoded h:i A / g:i A and the history H:i, while the dashboard used the preference — a 24-hour user (every account before 11 Oct) saw both formats for one punch.',
            'Hardcoded formats instead of DateDisplay.',
            ['QA-P4-021', 'QA-P4-022'], '3001a2b', 'fix(attendance): show My Attendance times in the reader\'s 12/24-hour format',
            ['app/Services/Attendance/PunchTimeline.php', 'app/Livewire/Attendance/AttendanceTracker.php', 'app/Livewire/Concerns/ShowsTodayActivity.php']],
        'QA-F-049' => ['ui', 'Settings', 'medium', 'Settings master-data tables clipped on phones',
            'Tables inside overflow-hidden cards had no horizontal scroll; edit / delete columns were unreachable at 375px; page headers overflowed by 15px.',
            'No overflow-x wrapper; non-wrapping header.',
            ['QA-P4-023'], '50fbdcf', 'fix(settings): scroll master-data tables and wrap page headers on phones',
            ['resources/css/app.css', 'resources/views/livewire/settings/department-manager.blade.php']],
    ];

    /**
     * Open findings: payroll rules and policy questions need a decision.
     * code => [kind, module, severity, needs decision, title, description, risk]
     *
     * @var array<string, array<int, mixed>>
     */
    private const OPEN = [
        'QA-F-050' => ['functional', 'Payroll', 'high', true, 'P4 payroll engine reads no attendance',
            'SalaryCalculationService / LwpService deduct only approved unpaid-type leave (gross/26 per day). An unapproved absence (working day, no attendance, no leave) is never deducted, and a mid-cycle joiner or leaver is paid the full month. The canonical day classification (WorkingDayResolver / AttendanceWindowService) is not used.',
            'Over-payment for absence and partial months; payroll disagrees with the attendance report.'],
        'QA-F-051' => ['functional', 'Payroll', 'high', true, 'P4 payslip PDF recomputes Working / Paid days and LWP live with its own formula',
            'payslip-body.blade.php subtracts only weekly offs, so a weekday holiday, MDL date or pre-joining day without attendance shows as LWP, unpaid leave counts as present, and its LWP differs from the LWP the engine deducted. Being live, a finalised payslip\'s figures change after later edits.',
            'Employees see days that do not match the amount paid.'],
        'QA-F-052' => ['functional', 'Payroll', 'high', true, 'P3-10 / P4 payroll-attendance report has its own payable-days formula',
            'AttendanceReportBuilder::payableDays (P + L + 0.5×HD + LV + WO + H; LOP = A + 0.5×HD) ignores MDL shutdown days (counted LOP), joining / exit dates (pre-joining days LOP), the employee\'s own holiday calendar (all calendars count), approved_leave_status (unpaid-approved leave shown paid) and sandwich rules — and it differs from the engine, which reads no attendance at all.',
            'Three payable-day answers (engine, report, PDF) for one employee-month.'],
        'QA-F-053' => ['functional', 'Payroll', 'high', true, 'P4 LWP for an overdrawn balance deducts the whole request and uses the calendar year',
            'LwpService marks every request of a type as LWP once used_days > allocated_days, and looks up leave_balances.year = cycle end calendar year — a Jan–Jun run reads the wrong leave year (1 July start), so overdrawn leave in those months is never deducted.',
            'Over- and under-deduction.'],
        'QA-F-054' => ['functional', 'Overtime', 'medium', true, 'P4 OT: zero-hour records are paid as 0 and lost; OT reports list unapproved records as payable',
            'An approved OT on a missing-checkout day (ot_hours 0) is linked and marked paid at finalise; when the real OUT arrives settleApprovedForDay skips it. The OT records report / CSV and the PDF OT hours sum every record, approved or not.',
            'Unpaid overtime; inflated OT figures.'],
        'QA-F-055' => ['rbac', 'Payroll', 'medium', true, 'Payroll approval steps decide eligibility by role name',
            'PayrollApprovalStep matches users.role === approver_type; finance-approve lets any run_payroll holder through. A Finance user whose approve_finance is revoked can still finalise; a custom role granted approve_finance never can. Step recipients are User::where(role).',
            'Approval not governed by permissions.'],
        'QA-F-056' => ['rbac', 'Payroll', 'medium', true, 'run_payroll / view_payroll are never scoped',
            'Payslip download / bulk ZIP / combined print, payroll CSVs and the bank-transfer file are company-wide for any run_payroll holder; view_payroll is declared scoped but not enforced. view_payslips, approve_payroll and manage_salary_components are never checked.',
            'A scoped or custom payroll role reaches every employee\'s pay and bank data.'],
        'QA-F-057' => ['rbac', 'Payroll', 'low', true, 'Director default view_payroll exposes payable OT amounts in Employee 360',
            'Directors hold view_payroll (department scope) by default, which shows payable OT amounts and payslip activity in Employee 360 and increment letters — contradicting "compensation is Finance / HR, not Director".',
            'Policy question: should Directors see department pay?'],
        'QA-F-058' => ['rbac', 'Settings', 'low', true, 'Starter-kit Teams: any signed-in user can create a team',
            '/settings/teams is auth-only and createTeam has no gate (the starter-kit tests assert any user may create one); the settings menu hides it unless manage_settings or a shared team. Unrelated to HR teams (employees.teams).',
            'Policy question: keep, restrict to manage_settings, or remove the starter-kit Teams.'],
        'QA-F-059' => ['functional', 'Notifications', 'medium', false, 'Queued notifications render the status at send time, not at the event',
            'LeaveRequest, RegularisationReviewed, OT, WFH and HolidayWork notifications are ShouldQueue + SerializesModels and switch on the model\'s current status in toArray(); queue after_commit is false. A request decided before the worker runs sends "approved" to approvers and twice to the employee; message-thread posts re-send the decision.',
            'Duplicate / wrong-content notifications with a queue worker.'],
        'QA-F-060' => ['ui', 'Branding', 'medium', false, 'Branding: two primary-colour sources; favicon and letter logos without existence checks',
            'PDFs read companies.primary_color (default indigo) while the UI uses UiTheme primary (orange); secondary_color is unused. head.blade favicon, General-settings favicon preview, experience / increment / warning letter logos and the Brand & Layout preview build storage URLs without checking the file exists; error pages and payslip verify have no favicon.',
            'Off-brand PDFs; broken images on a fresh environment.'],
        'QA-F-061' => ['ui', 'Theme', 'low', false, 'Many pages ignore the dynamic UI-theme variables',
            'Hand-rolled card shells (rounded-2xl / rounded-[18px] … bg-white), page widths other than max-w-[1400px] / max-w-7xl, 1,100+ text-[Npx] sizes, flux:modal and flux:badge ignore --ui-* (My Attendance, My Time Off, Attendance Reports, payroll, dashboards). Sidebar colours are hardcoded hex.',
            'Brand & Layout settings only partly apply — scheduled for the UI/UX phase.'],
        'QA-F-062' => ['ui', 'Date/time', 'low', false, 'Remaining hardcoded date / time formats',
            'About 430 display formats outside My Attendance ignore the user preference (Team Attendance, All Attendance, leave, OT, performance, payroll, notifications); mixed d M Y / M d, Y order; notifications do not use DateDisplay::for($notifiable); some created_at instants are not shifted to the reader\'s timezone.',
            'Inconsistent formats between screens.'],
        'QA-F-063' => ['functional', 'Notifications', 'medium', false, 'Notification gaps: cancel / profile requests silent, repeating reminders, role-name recipients',
            'Leave cancellation and profile / KYC change requests notify nobody; probation-expiry, onboarding-overdue and review-cycle reminders repeat daily without a ledger; payroll and PIP recipients are chosen by role name (Directors get /payroll/overview links they cannot open); the bell count is cached 30s and not cleared when read on the Notifications page.',
            'Missed or noisy notices.'],
        'QA-F-064' => ['functional', 'Settings', 'low', false, 'Settings: Coordinators page has no menu entry; validation gaps',
            'Coordinators (assign_coordinators) is reachable only from the Control Panel (manage_settings). General company fields (timezone, currency, phone, address, colours), Sidebar Menu items (label length; key not #[Locked]) and Holiday Pay enabledTypes are saved without validation.',
            'Hidden page; DB errors on over-long values.'],
    ];

    public function run(): void
    {
        $tracker = app(QaTracker::class);
        $actor = new User(['name' => 'Phase 4 platform audit']);
        $phase = QaPhase::where('number', 4)->first();
        $at = Carbon::parse(self::RETESTED_AT);

        if ($phase !== null && $phase->status === QaPhaseStatus::NotStarted) {
            $tracker->updatePhase($phase, ['status' => QaPhaseStatus::InProgress->value, 'owner' => 'Claude Code + Super Admin', 'started_at' => '2026-10-16',
                'notes' => 'Payroll, settings, branding / theme, notifications, AI, reports, date/time and cache. Fixes verified; payroll formula mismatches and payroll / RBAC policy questions wait for a decision (no payroll rule changed).'], $actor);
        }

        foreach (self::CASES as $code => [$module, $title, $reference]) {
            $case = QaTestCase::firstOrCreate(['code' => $code], [
                'qa_phase_id' => $phase?->id, 'module' => $module, 'feature' => 'Platform consistency', 'title' => $title,
                'expected_result' => 'The same value / state / access through every applicable consumer.', 'severity' => 'medium',
                'automated_reference' => 'tests/Feature/'.$reference, 'status' => QaTestStatus::Pass, 'last_tested_at' => $at,
            ]);
            if ($case->wasRecentlyCreated) {
                QaTestResult::create(['qa_test_case_id' => $case->id, 'status' => QaTestStatus::Pass, 'actual_result' => 'Passed after the fix.',
                    'environment' => 'local_test_db', 'tested_by_name' => self::TESTER, 'tested_at' => $at, 'is_retest' => true]);
            }
        }

        foreach (self::FIXED as $code => [$kind, $module, $severity, $title, $description, $rootCause, $tests, $commit, $message, $files]) {
            $finding = QaFinding::where('code', $code)->first()
                ?? $tracker->openFinding(['code' => $code, 'qa_phase_id' => $phase?->id, 'kind' => $kind, 'module' => $module, 'severity' => $severity,
                    'title' => $title, 'description' => $description, 'root_cause' => $rootCause, 'environment' => 'local_test_db', 'found_at' => '2026-10-16 12:00:00'], $actor);
            if ($finding->fixes()->where('commit_hash', $commit)->exists()) {
                continue;
            }
            $tracker->linkFix($finding, ['commit_hash' => $commit, 'commit_message' => $message, 'files_changed' => $files, 'test_case_codes' => $tests,
                'environment' => 'local_test_db', 'fixed_at' => $at], $actor);
            $tracker->changeFindingStatus($finding->fresh(), QaFindingStatus::Verified, $actor,
                'Retest '.$at->format('d M Y').': '.implode(', ', $tests).' pass; affected suites green.');
        }

        QaTestRun::firstOrCreate(['name' => '2026-10-16 Phase 4 full suite'], [
            'qa_phase_id' => $phase?->id, 'suite' => 'pest', 'kind' => 'full', 'environment' => 'local_test_db', 'commit' => '32017f6',
            'total' => 3200, 'passed' => 3187, 'failed' => 12, 'skipped' => 1, 'known_baseline' => 12, 'new_regressions' => 0,
            'duration_seconds' => 3598, 'run_at' => '2026-10-16 16:22:00', 'result' => QaTestRun::resultFor(12, 0),
            'notes' => 'Full Pest suite at 32017f6 (every Phase 4 fix) plus this QA record; HEAD unchanged during the run. The 12 failures are exactly the known baseline (QA-BASE-001…012).',
        ]);

        foreach (self::OPEN as $code => [$kind, $module, $severity, $needsDecision, $title, $description, $risk]) {
            if (QaFinding::where('code', $code)->exists()) {
                continue;
            }
            $tracker->openFinding(['code' => $code, 'qa_phase_id' => $phase?->id, 'kind' => $kind, 'module' => $module, 'severity' => $severity,
                'needs_decision' => $needsDecision, 'title' => $title, 'description' => $description, 'risk' => $risk,
                'environment' => 'local', 'found_at' => '2026-10-16 12:00:00'], $actor);
        }
    }
}
