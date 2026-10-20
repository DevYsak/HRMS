<?php

namespace Database\Seeders;

use App\Enums\QaFindingStatus;
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
 * Phase 5 round 2: the policy decisions approved 20 Oct 2026 (QA-F-079
 * attendance %, QA-F-080 team scope, QA-F-081 dark canvas), the systemic UI
 * work and the eight-role demo journeys — through QaTracker so every finding
 * keeps its history: test case → finding → fix commit → retest → verified.
 * QA-F-082 stays open (another session's uncommitted work). QA records only;
 * idempotent.
 */
class QaAuditPhaseFiveDecisionsSeeder extends Seeder
{
    private const TESTER = 'Claude Code (automated Pest suite)';

    private const RETESTED_AT = '2026-10-20 18:00:00';

    private const FOUND_AT = '2026-10-20 12:30:00';

    private const AR = 'Phase5/AttendanceRateTest > ';

    private const TS = 'Phase5/TeamScopeTest > ';

    private const DC = 'Phase5/DarkCanvasTest > ';

    private const SU = 'Phase5/SystemicUiTest > ';

    /** code => [module, title, test reference] */
    private const CASES = [
        'QA-P5-016' => ['Attendance', 'Attendance % follows every rule of the approved definition', self::AR.'every rule of the definition, in one window'],
        'QA-P5-017' => ['Attendance', 'Approved paid leave never lowers attendance %', self::AR.'approved paid leave never lowers the rate'],
        'QA-P5-018' => ['Attendance', 'Today counts once attended; future and pre-joining days never count', self::AR.'today counts once attended; future days and days before joining never count'],
        'QA-P5-019' => ['Attendance', 'A group rate weighs every eligible day once', self::AR.'a group rate weighs every eligible day once'],
        'QA-P5-020' => ['Attendance', 'No attendance recorded → no rate, never 0%', self::AR.'with no attendance recorded at all there is no rate, not 0%'],
        'QA-P5-021' => ['Attendance', 'Every screen shows the same attendance % for the same person and month', self::AR.'every screen shows the same attendance % for the same person and month'],
        'QA-P5-022' => ['RBAC', 'Department Head Team Attendance covers the headed department', self::TS.'a Department Head\'s Team Attendance covers the whole headed department, with a department scope badge'],
        'QA-P5-023' => ['RBAC', 'Manager Team Attendance stays on their team', self::TS.'a Manager\'s Team Attendance stays on their team'],
        'QA-P5-024' => ['RBAC', 'Team View follows its own permission\'s scope', self::TS.'Team View follows its own permission\'s scope, never wider'],
        'QA-P5-025' => ['Theme', 'One dark canvas from a global variable', self::DC.'the dark canvas is one global variable, #0B1220'],
        'QA-P5-026' => ['Theme', 'No page sets its own dark background', self::DC.'no page sets its own dark background other than the canvas colour'],
        'QA-P5-027' => ['Theme', 'Brand & Layout dark background defaults to the canvas', self::DC.'Brand & Layout defaults to the canvas and follows it from an old saved default'],
        'QA-P5-028' => ['UI', 'Light colours without a dark partner fall back to dark tokens', self::SU.'a light background or border without a dark partner falls back to the dark tokens'],
        'QA-P5-029' => ['UI', 'Wide tables hint at more; slow updates show a busy bar', self::SU.'wide tables get a scroll hint and slow updates a busy bar'],
        'QA-P5-030' => ['UI', 'Plain page titles share one style and are real h1 headings', self::SU.'plain page titles share one style and are real h1 headings'],
        'QA-P5-031' => ['UI', 'Pages found overflowing by the role journeys wrap or stack', self::SU.'pages the role journeys found overflowing on phones wrap or stack'],
    ];

    /** The approved decisions: finding => [commit => message, decision note, test cases]. */
    private const DECIDED = [
        'QA-F-079' => [['cd960dc' => 'feat(attendance): one attendance percentage everywhere (QA-F-079)'],
            'Decision (20 Oct): attendance % = attended eligible days ÷ eligible working days. Paid leave, weekly offs, holidays, outside employment, future days, today before attended and still-explainable absences are not eligible; unpaid leave and finalised absence stay; half day ½. One AttendanceRateService feeds dashboard, My Attendance, profile, Employee 360, reports, Executive views, KPI engine and AI.',
            ['QA-P5-016', 'QA-P5-017', 'QA-P5-018', 'QA-P5-019', 'QA-P5-020', 'QA-P5-021']],
        'QA-F-080' => [['915845e' => 'feat(scope): team pages cover the permission\'s scope; badge names it (QA-F-080)', '8105d54' => 'fix(ui): five pages the role journeys found overflowing on phones; terser scope badge'],
            'Decision (20 Oct): team pages use PeopleScope for the page permission — Manager team, Department Head headed department, Director authorised department, Coordinator configured scope, HR / SA configured scope; badge "UK Operations · Department scope". No role renamed, no company access added.',
            ['QA-P5-022', 'QA-P5-023', 'QA-P5-024']],
        'QA-F-081' => [['467fb5d' => 'style(theme): one dark canvas #0B1220 from a global variable (QA-F-081)'],
            'Decision (20 Oct): one dark canvas #0B1220 from --ui-app-bg-dark; per-page dark backgrounds removed (82 pages); Brand & Layout default follows it. Reconciled with the other session\'s uncommitted typography work (its six pages already used #0B1220; untouched).',
            ['QA-P5-025', 'QA-P5-026', 'QA-P5-027']],
    ];

    /** Found in round 2 and fixed: [kind, module, severity, title, description, commits, tests]. */
    private const FIXED = [
        'QA-F-083' => ['functional', 'Attendance', 'medium', 'Per-day approved-leave query while classifying a window',
            'AttendanceCalculator::forDay() classified each attended day with its own leave query; routing the dashboard through the canonical classifier tripped the query-count guard. WorkingDayResolver::primeApprovedLeaveRange now answers a whole window from one query.',
            ['cd960dc' => 'feat(attendance): one attendance percentage everywhere (QA-F-079)'], ['QA-P5-021']],
        'QA-F-084' => ['ui', 'Theme', 'medium', 'Light backgrounds and borders glowing in dark mode',
            '~250 light backgrounds / borders / dividers on 92 views had no dark: partner (worst: All Attendance, Executive Attendance, Manage Holidays, Biometric Control). A global fallback maps them to the dark tokens; elements with their own dark colour are untouched.',
            ['7b01f18' => 'fix(ui): dark-mode fallbacks, table scroll hints, busy bar and one page-title style'], ['QA-P5-028']],
        'QA-F-085' => ['ui', 'UI', 'low', 'Wide tables gave no hint that more columns exist; slow updates gave no feedback',
            'Horizontally scrolling tables now fade the edge with more content; a Livewire update slower than 300 ms shows a thin busy bar with aria-busy.',
            ['7b01f18' => 'fix(ui): dark-mode fallbacks, table scroll hints, busy bar and one page-title style'], ['QA-P5-029']],
        'QA-F-086' => ['ui', 'UI', 'low', 'Four page-title styles',
            'Plain page titles rendered at 24px with weights 500 / 700 / 800 / 900 through three markup patterns, some not h1. 34 pages now use .pulse-page-title as an h1; hero banners keep their own style.',
            ['7b01f18' => 'fix(ui): dark-mode fallbacks, table scroll hints, busy bar and one page-title style'], ['QA-P5-030']],
        'QA-F-087' => ['ui', 'UI', 'medium', 'Five pages scrolled sideways on phones (role journeys)',
            'Biometric Summary, Documents, Org Chart, KPI Dashboard and KPI Templates overflowed 19–79px at 375px.',
            ['8105d54' => 'fix(ui): five pages the role journeys found overflowing on phones; terser scope badge'], ['QA-P5-031']],
    ];

    /**
     * @param  array<string, mixed>|null  $journeyRun
     * @param  array<string, mixed>|null  $fullRun
     */
    public function run(?array $journeyRun = null, ?array $fullRun = null): void
    {
        $tracker = app(QaTracker::class);
        $actor = new User(['name' => 'Phase 5 decisions']);
        $phase = QaPhase::where('number', 5)->first();
        $at = Carbon::parse(self::RETESTED_AT);

        foreach (self::CASES as $code => [$module, $title, $reference]) {
            $case = QaTestCase::firstOrCreate(['code' => $code], [
                'qa_phase_id' => $phase?->id, 'module' => $module, 'feature' => 'Phase 5 decisions & systemic UI', 'title' => $title,
                'expected_result' => 'Behaves as the approved Phase 5 policy states, on every screen, in both themes and at 375px.', 'severity' => 'medium',
                'automated_reference' => 'tests/Feature/'.$reference, 'status' => QaTestStatus::Pass, 'last_tested_at' => $at,
            ]);
            if ($case->wasRecentlyCreated) {
                QaTestResult::create(['qa_test_case_id' => $case->id, 'status' => QaTestStatus::Pass, 'actual_result' => 'Passed after the fix.',
                    'environment' => 'local_test_db', 'tested_by_name' => self::TESTER, 'tested_at' => $at, 'is_retest' => true]);
            }
        }

        foreach (self::DECIDED as $code => [$commits, $note, $tests]) {
            $finding = QaFinding::where('code', $code)->first();
            if ($finding === null || $finding->status === QaFindingStatus::Verified) {
                continue;
            }
            if ($finding->needs_decision) {
                $tracker->updateFinding($finding, ['needs_decision' => false, 'description' => $finding->description."\n\n".$note], $actor);
            }
            $this->fixAndVerify($tracker, $finding, $commits, $tests, $at, $actor);
        }

        foreach (self::FIXED as $code => [$kind, $module, $severity, $title, $description, $commits, $tests]) {
            $finding = QaFinding::where('code', $code)->first() ?? $tracker->openFinding([
                'code' => $code, 'qa_phase_id' => $phase?->id, 'kind' => $kind, 'module' => $module, 'severity' => $severity,
                'title' => $title, 'description' => $description, 'environment' => 'local', 'found_at' => self::FOUND_AT,
            ], $actor);
            if ($finding->status !== QaFindingStatus::Verified) {
                $this->fixAndVerify($tracker, $finding, $commits, $tests, $at, $actor);
            }
        }

        foreach (array_filter([$journeyRun, $fullRun]) as $run) {
            QaTestRun::firstOrCreate(['name' => $run['name']], [
                'qa_phase_id' => $phase?->id, 'suite' => $run['suite'], 'kind' => $run['kind'], 'environment' => $run['environment'], 'commit' => $run['commit'],
                'total' => $run['total'], 'passed' => $run['passed'], 'failed' => $run['failed'], 'skipped' => $run['skipped'],
                'known_baseline' => $run['known_baseline'], 'new_regressions' => $run['failed'] - $run['known_baseline'],
                'duration_seconds' => $run['duration'], 'run_at' => $run['run_at'], 'notes' => $run['notes'],
                'result' => QaTestRun::resultFor($run['failed'], $run['failed'] - $run['known_baseline']),
            ]);
        }
    }

    /**
     * @param  array<string, string>  $commits
     * @param  array<int, string>  $tests
     */
    private function fixAndVerify(QaTracker $tracker, QaFinding $finding, array $commits, array $tests, Carbon $at, User $actor): void
    {
        foreach ($commits as $hash => $message) {
            if (! $finding->fixes()->where('commit_hash', $hash)->exists()) {
                $tracker->linkFix($finding->fresh(), ['commit_hash' => $hash, 'commit_message' => $message, 'test_case_codes' => $tests,
                    'environment' => 'local_test_db', 'fixed_at' => $at], $actor);
            }
        }
        $tracker->changeFindingStatus($finding->fresh(), QaFindingStatus::Verified, $actor,
            'Retest '.$at->format('d M Y').': '.implode(', ', $tests).' pass; browser-checked on the throwaway e2e app.');
    }
}
