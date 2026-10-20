<?php

namespace Database\Seeders;

use App\Enums\QaFindingStatus;
use App\Enums\QaPhaseStatus;
use App\Enums\QaTestStatus;
use App\Models\QaFinding;
use App\Models\QaPhase;
use App\Models\QaTestCase;
use App\Models\QaTestResult;
use App\Models\User;
use App\Services\Qa\QaTracker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Phase 5 (UI/UX, responsive, dark mode, demo journeys) — first round.
 *
 * Baseline: 80 screens (6 roles × desktop-light / phone-dark) captured on a
 * throwaway e2e app, reviewed for layout, dark mode and cross-screen numbers.
 * Fixed findings go test case → finding → fix commit → retest → verified
 * through QaTracker; policy questions stay open with needs_decision.
 * QA records only; idempotent.
 */
class QaAuditPhaseFiveSeeder extends Seeder
{
    private const TESTER = 'Claude Code (automated Pest suite)';

    private const RETESTED_AT = '2026-10-20 12:00:00';

    private const FOUND_AT = '2026-10-19 23:00:00';

    /** The cross-screen data fix commit (profile leave, team holidays, manager label). */
    private const DATA_FIX_COMMIT = '0204185';

    private const RL = 'Phase5/ResponsiveLayoutTest > ';

    private const DC = 'Phase5/DataConsistencyTest > ';

    /** code => [module, title, test reference] */
    private const CASES = [
        'QA-P5-001' => ['Dashboards', 'KPI tiles keep their label and draw sparklines only from a real series', self::RL.'a KPI tile never cuts its label to one letter, and draws a sparkline only from a real series'],
        'QA-P5-002' => ['Dashboards', 'Executive view: no invented trend lines', self::RL.'the Executive view shows sparklines only for real six-month series, never an invented trend'],
        'QA-P5-003' => ['Payroll', 'Run Payroll filter rows wrap on a phone', self::RL.'Run Payroll filter rows wrap on a phone'],
        'QA-P5-004' => ['QA tracker', 'Open-finding rows stay readable on a phone', self::RL.'QA open-finding rows wrap instead of squeezing the title'],
        'QA-P5-005' => ['Time off', 'Hero tiles stack on phones instead of truncating', self::RL.'hero KPI tiles stack their icon on phones and never cut the label to a few letters'],
        'QA-P5-006' => ['UI', 'Empty tables keep their message in view on phones', self::RL.'empty tables keep their message in view on phones'],
        'QA-P5-007' => ['Holidays', 'Holiday month grid fits a phone', self::RL.'the holiday month grid fits a phone without a sideways scroller'],
        'QA-P5-008' => ['Reports', 'Attendance report table readable in dark mode', self::RL.'the attendance report table has dark-mode borders and header'],
        'QA-P5-009' => ['Dashboards', 'Six-month trend axes correct on the 31st', 'Phase5/TrendMonthAxisTest > six-month trend axes never repeat or skip a month on the 31st'],
        'QA-P5-010' => ['UI', '"All months" filter instead of an empty native month box', self::RL.'list month filters offer "All months" instead of an empty native month box'],
        'QA-P5-011' => ['Approvals', 'A zero pending count reads neutral', self::RL.'a zero pending count reads neutral, not as an alarm'],
        'QA-P5-012' => ['Dashboards', 'Department ranking pluralises headcount', self::RL.'department ranking pluralises headcount'],
        'QA-P5-013' => ['Profile', 'Profile leave balance = My Time Off Available Leave', self::DC.'the profile leave balance equals My Time Off Available Leave, ignoring other leave types'],
        'QA-P5-014' => ['Time off', 'Team calendar shows only the team\'s own holidays', 'TeamLeaveCalendarTest > the calendar shows the team\'s own public holidays, not another country\'s'],
        'QA-P5-015' => ['Dashboards', 'Manager missing check-outs says "today"', self::DC.'the manager dashboard says its missing check-out count is today\'s'],
    ];

    /**
     * Found in the Phase 5 baseline and fixed: [kind, module, severity, title, description, commits, test cases].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: array<string, string>, 6: array<int, string>}>
     */
    private const FIXED = [
        'QA-F-067' => ['ui', 'Dashboards', 'high', 'Executive KPI tiles: labels cut to one letter and invented sparklines',
            'At 375px the tiles truncated labels to "P…"; every tile drew a sparkline from a made-up curve ending at the current value, including Open Approvals and Compliance which have no history.',
            ['fb1faf5' => 'fix(ui): wrap payroll toolbars, honest KPI sparklines and readable QA findings on phones'], ['QA-P5-001', 'QA-P5-002']],
        'QA-F-068' => ['ui', 'Payroll', 'medium', 'Run Payroll and Payroll Overview overflow the phone width',
            'Toolbar and header action rows did not wrap; the page scrolled sideways at 375px.',
            ['fb1faf5' => 'fix(ui): wrap payroll toolbars, honest KPI sparklines and readable QA findings on phones'], ['QA-P5-003']],
        'QA-F-069' => ['ui', 'QA tracker', 'medium', 'QA open findings unreadable on a phone',
            'Code, severity, module and title shared one non-wrapping row; titles were squeezed to a few characters.',
            ['fb1faf5' => 'fix(ui): wrap payroll toolbars, honest KPI sparklines and readable QA findings on phones'], ['QA-P5-004']],
        'QA-F-070' => ['ui', 'Time off', 'high', 'My Time Off and Command Center tiles truncated on phones',
            'Two-column icon + text tiles read "AVAILA…", "10 D…", "TOTAL…", "APPRO…".',
            ['0f3fb39' => 'fix(ui): readable phone tiles, in-view empty states, full-week holiday grid and dark report table'], ['QA-P5-005']],
        'QA-F-071' => ['ui', 'UI', 'medium', 'Empty-state messages clipped in wide tables on phones',
            'My/Team leave, My/Manage WFH, Manage OT and My Payslips centred the empty message across the full scrolling table width, so it sat off-screen.',
            ['0f3fb39' => 'fix(ui): readable phone tiles, in-view empty states, full-week holiday grid and dark report table'], ['QA-P5-006']],
        'QA-F-072' => ['ui', 'Holidays', 'high', 'Holiday Calendar showed only Sun–Wed on a phone',
            'The month grid had a 560px minimum width; Thursday–Saturday needed a sideways scroll that nothing hinted at.',
            ['0f3fb39' => 'fix(ui): readable phone tiles, in-view empty states, full-week holiday grid and dark report table'], ['QA-P5-007']],
        'QA-F-073' => ['ui', 'Reports', 'medium', 'Attendance Reports: light table header and white dividers in dark mode',
            'Card borders, table header and row dividers had no dark variants; Manage OT header actions also overflowed by 115px for HR (Export CSV).',
            ['0f3fb39' => 'fix(ui): readable phone tiles, in-view empty states, full-week holiday grid and dark report table'], ['QA-P5-008']],
        'QA-F-074' => ['functional', 'Dashboards', 'medium', 'Six-month trend charts repeat / skip a month on the 31st',
            'Executive and Payroll Overview stepped back with now()->subMonths(i); on the 31st this overflows (31 Oct − 1 month = 1 Oct), so the axis repeated one month, skipped another and queried the wrong month.',
            ['1ae44ea' => 'fix(dashboard): six-month trend axes step back from the first of the month'], ['QA-P5-009']],
        'QA-F-075' => ['ui', 'UI', 'low', 'Empty month filters render "--------- ----"; zero pending shown as an alarm',
            'Native month inputs with no value (the "all months" default) on My WFH, My OT and Expenses; the Approval Command Center pill was rose at 0; "1 employees".',
            ['17c2d02' => 'fix(ui): "All months" filter, neutral zero pending pill and plural headcount'], ['QA-P5-010', 'QA-P5-011', 'QA-P5-012']],
        'QA-F-076' => ['data_mismatch', 'Profile', 'high', 'Profile leave balance 162 days vs My Time Off 10 days',
            'The profile summed available() over every leave-balance row (Maternity / Paternity entitlements included) instead of the canonical Available Leave (CSL + Comp Off).',
            ['DATA_FIX' => 'fix(data): profile leave = Available Leave, team calendar = team holidays, manager count says today'], ['QA-P5-013']],
        'QA-F-077' => ['data_mismatch', 'Time off', 'medium', 'Team leave calendar showed another country\'s holidays',
            'Every active holiday was overlaid, so a UK team saw Gandhi Jayanti and Dussehra. Now only holidays on a team member\'s own calendar (HolidayResolver::appliesTo).',
            ['DATA_FIX' => 'fix(data): profile leave = Available Leave, team calendar = team holidays, manager count says today'], ['QA-P5-014']],
        'QA-F-078' => ['ui', 'Dashboards', 'low', 'Manager "Missing check-outs 0" read as contradicting the employee\'s 3',
            'The manager count is today\'s team state; the employee figure is the month. The label now says "today".',
            ['DATA_FIX' => 'fix(data): profile leave = Available Leave, team calendar = team holidays, manager count says today'], ['QA-P5-015']],
    ];

    /** Open: a policy or design decision is needed, or the files belong to work in progress elsewhere. */
    private const OPEN = [
        'QA-F-079' => ['data_mismatch', 'Attendance', 'medium', true, 'Three attendance-% definitions (dashboard 62%, My Attendance 58%, profile 48%)',
            'Dashboard: worked days ÷ elapsed working days incl. today and approved-leave days. AttendanceWindowService: present ÷ scheduled (past working days, today only once attended, leave excluded). Profile: present ÷ Mon–Fri weekdays. Decision needed: which denominator is the company\'s "attendance %"; then all three read AttendanceWindowService.',
            'Different percentages for the same person and month.'],
        'QA-F-080' => ['rbac', 'Attendance', 'medium', true, 'Department Head Team Attendance shows only direct reports',
            'Team Attendance and Team View are scoped to the reporting line; a Department Head whose department reports to a manager sees "0" there and must use Department View. Decision needed: should a Department Head\'s team pages cover the whole department?',
            'Department Heads see empty team pages in demos.'],
        'QA-F-081' => ['ui', 'Theme', 'low', true, 'Two dark page backgrounds: navy #0B1220 (layout) vs zinc-950 (71 pages)',
            'The sidebar layout body is dark:bg-[#0B1220]; 71 pages override it with dark:bg-zinc-950, 6 with dark:bg-white/5. Decision needed on the one dark canvas; overlaps the in-progress Brand & Layout / typography work, so not changed here.',
            'Pages look like different products in dark mode.'],
        'QA-F-082' => ['ui', 'UI', 'low', false, 'Leave Management table squeezed; Brand & Layout preview wordmark unreadable in dark',
            'Both pages have uncommitted changes from another session (leave-management.blade.php, UiThemeSettings) and were left untouched in this round.',
            'Cramped HR table; low-contrast preview.'],
    ];

    public function run(): void
    {
        $tracker = app(QaTracker::class);
        $actor = new User(['name' => 'Phase 5 UI/UX']);
        $phase = QaPhase::where('number', 5)->first();
        $at = Carbon::parse(self::RETESTED_AT);

        foreach (self::CASES as $code => [$module, $title, $reference]) {
            $case = QaTestCase::firstOrCreate(['code' => $code], [
                'qa_phase_id' => $phase?->id, 'module' => $module, 'feature' => 'Phase 5 UI/UX', 'title' => $title,
                'expected_result' => 'Readable at 375px and in dark mode, with the same number as every other screen.', 'severity' => 'medium',
                'automated_reference' => 'tests/Feature/'.$reference, 'status' => QaTestStatus::Pass, 'last_tested_at' => $at,
            ]);
            if ($case->wasRecentlyCreated) {
                QaTestResult::create(['qa_test_case_id' => $case->id, 'status' => QaTestStatus::Pass, 'actual_result' => 'Passed after the fix; before / after screenshots at 375px dark.',
                    'environment' => 'local_test_db', 'tested_by_name' => self::TESTER, 'tested_at' => $at, 'is_retest' => true]);
            }
        }

        foreach (self::FIXED as $code => [$kind, $module, $severity, $title, $description, $commits, $tests]) {
            $finding = QaFinding::where('code', $code)->first() ?? $tracker->openFinding([
                'code' => $code, 'qa_phase_id' => $phase?->id, 'kind' => $kind, 'module' => $module, 'severity' => $severity,
                'title' => $title, 'description' => $description, 'environment' => 'local', 'found_at' => self::FOUND_AT,
            ], $actor);
            if ($finding->status === QaFindingStatus::Verified) {
                continue;
            }
            foreach ($commits as $hash => $message) {
                $hash = $hash === 'DATA_FIX' ? self::DATA_FIX_COMMIT : $hash;
                if (! $finding->fixes()->where('commit_hash', $hash)->exists()) {
                    $tracker->linkFix($finding->fresh(), ['commit_hash' => $hash, 'commit_message' => $message, 'test_case_codes' => $tests,
                        'environment' => 'local_test_db', 'fixed_at' => $at], $actor);
                }
            }
            $tracker->changeFindingStatus($finding->fresh(), QaFindingStatus::Verified, $actor,
                'Retest '.$at->format('d M Y').': '.implode(', ', $tests).' pass; after screenshots at 375px dark show the fix.');
        }

        foreach (self::OPEN as $code => [$kind, $module, $severity, $needsDecision, $title, $description, $risk]) {
            if (! QaFinding::where('code', $code)->exists()) {
                $tracker->openFinding(['code' => $code, 'qa_phase_id' => $phase?->id, 'kind' => $kind, 'module' => $module, 'severity' => $severity,
                    'needs_decision' => $needsDecision, 'title' => $title, 'description' => $description, 'risk' => $risk,
                    'environment' => 'local', 'found_at' => self::FOUND_AT], $actor);
            }
        }

        if ($phase !== null && in_array($phase->status, [QaPhaseStatus::NotStarted, QaPhaseStatus::Blocked], true)) {
            $tracker->updatePhase($phase, ['status' => QaPhaseStatus::InProgress->value, 'started_at' => '2026-10-19',
                'notes' => 'Phase 5 round 1: 80-screen baseline (6 roles × desktop-light / phone-dark) on a throwaway e2e app; phone layout, dark-mode and cross-screen number fixes verified with tests and after screenshots. Open: attendance-% definition, Department Head team scope, one dark canvas (decisions), plus branding / theme coverage (QA-F-060 / 061) and remaining date formats (QA-F-066).'], $actor);
        }
    }
}
