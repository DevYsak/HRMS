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
 * Phase 5 finishing round (26 Oct 2026): branding (QA-F-060), theme coverage
 * (QA-F-061), reader date formats (QA-F-066), Leave Management + Brand &
 * Layout preview (QA-F-082) and the Attendance › Employees filters — through
 * QaTracker: test case → finding → fix commit → retest → verified.
 * Phase 5 stays in progress (no full-suite run yet; six leave views under
 * another session's work still need the card / date sweep). QA records only;
 * idempotent.
 */
class QaAuditPhaseFiveFinishSeeder extends Seeder
{
    private const TESTER = 'Claude Code (automated Pest suite)';

    private const RETESTED_AT = '2026-10-26 12:45:00';

    private const AL = 'Phase5/AttendanceLogFiltersTest > ';

    private const BD = 'Phase5/BrandingDocumentsTest > ';

    private const TC = 'Phase5/ThemeCoverageTest > ';

    /** code => [module, title, test reference] */
    private const CASES = [
        'QA-P5-032' => ['Attendance', 'Employee + date range show only that employee\'s days, with every punch', self::AL.'employee and date range together show only that employee\'s days in the range, with every punch'],
        'QA-P5-033' => ['Attendance', 'A single day takes the place of the range', self::AL.'a single day takes the place of the range'],
        'QA-P5-034' => ['Attendance', 'From after To is refused and shows no rows', self::AL.'From after To is refused with a message and shows no rows'],
        'QA-P5-035' => ['Attendance', 'A malformed URL date is ignored safely', self::AL.'a malformed date from the URL is ignored safely'],
        'QA-P5-036' => ['Attendance', 'Filters come from the URL and survive pagination', self::AL.'filters come from the URL and stay through pagination'],
        'QA-P5-037' => ['Attendance', 'Clear filters returns to this week and everyone in reach', self::AL.'Clear filters returns to this week and every employee in reach'],
        'QA-P5-038' => ['Attendance', 'Log dates follow the reader\'s date format', self::AL.'dates on the page follow the reader\'s date format'],
        'QA-P5-039' => ['Attendance', 'Punches cost one query per page, not one per row', self::AL.'the punches column costs one query for the page, not one per row'],
        'QA-P5-040' => ['Attendance', 'Filtering never changes an attendance record', self::AL.'filtering never changes an attendance record'],
        'QA-P5-041' => ['Branding', 'Documents use the Brand & Layout primary colour', self::BD.'documents use the Brand & Layout primary colour, not the company colour field'],
        'QA-P5-042' => ['Branding', 'Every PDF reads the shared colour and checked logo path', self::BD.'every PDF reads the shared brand colour and the checked logo path'],
        'QA-P5-043' => ['Branding', 'A logo is used only when its file exists', self::BD.'a logo is used only when its file exists, otherwise the bundled mark'],
        'QA-P5-044' => ['Branding', 'The page head never links a missing favicon', self::BD.'the page head never links a favicon whose file is missing'],
        'QA-P5-045' => ['Branding', 'General settings points to Brand & Layout for colours', self::BD.'General settings points to Brand & Layout for colours instead of a second picker'],
        'QA-P5-046' => ['Theme', 'Card containers use the shared ui-surface', self::TC.'card containers use the shared ui-surface, not hand-rolled shells'],
        'QA-P5-047' => ['Date/time', 'On-screen dates and times follow the reader\'s format', self::TC.'on-screen dates and times follow the reader\'s format'],
        'QA-P5-048' => ['Time off', 'Leave Management table keeps readable columns', self::TC.'the Leave Management balance table keeps readable columns'],
        'QA-P5-049' => ['Theme', 'Brand & Layout preview names the configured brand', 'Phase5/BrandPreviewTest > the preview shows the configured brand name instead of a hard-coded one'],
        'QA-P5-050' => ['Theme', 'Dark-mode overrides leave the light preview alone', 'Phase5/BrandPreviewTest > dark-mode overrides leave the light-mode preview alone'],
    ];

    /** finding => [commit => message, note, tests] */
    private const VERIFIED = [
        'QA-F-060' => [['6110550' => 'fix(branding): one brand colour for documents; logos and favicon only when the file exists (QA-F-060)'],
            'Fixed 26 Oct: PDFs read Company::brandColor() (Brand & Layout primary); logos via Company::brandLogoPath() — uploaded file only if it exists, bundled mark only when GD is loaded (dompdf needs GD for PNG); favicon only when its file exists; General settings colour pickers replaced by a Brand & Layout link.',
            ['QA-P5-041', 'QA-P5-042', 'QA-P5-043', 'QA-P5-044', 'QA-P5-045']],
        'QA-F-061' => [['a79b951' => 'style(ui): theme-driven cards, reader date formats and a readable Leave Management table (QA-F-061 / QA-F-066 / QA-F-082)'],
            'Fixed 26 Oct: 177 card containers on 52 views use .ui-surface; ThemeCoverageTest guards it. Six leave views under another session\'s uncommitted work are listed in the test and swept when it lands.',
            ['QA-P5-046']],
        'QA-F-066' => [['a79b951' => 'style(ui): theme-driven cards, reader date formats and a readable Leave Management table (QA-F-061 / QA-F-066 / QA-F-082)'],
            'Fixed 26 Oct: 147 on-screen dates / times on 68 views follow the reader\'s format; seven deliberate exceptions listed in ThemeCoverageTest; PDFs / exports fixed-format on purpose.',
            ['QA-P5-047']],
        'QA-F-082' => [['ce1f910' => 'fix(theme): Brand & Layout preview stays light and readable in dark mode (QA-F-082)',
            'a79b951' => 'style(ui): theme-driven cards, reader date formats and a readable Leave Management table (QA-F-061 / QA-F-066 / QA-F-082)'],
            'Fixed 25–26 Oct after the other session released the files: the preview is excluded from dark overrides and names the brand; the Leave Management table keeps readable columns.',
            ['QA-P5-048', 'QA-P5-049', 'QA-P5-050']],
    ];

    /** @param  array<string, mixed>|null  $run */
    public function run(?array $run = null): void
    {
        $tracker = app(QaTracker::class);
        $actor = new User(['name' => 'Phase 5 finishing']);
        $phase = QaPhase::where('number', 5)->first();
        $at = Carbon::parse(self::RETESTED_AT);

        foreach (self::CASES as $code => [$module, $title, $reference]) {
            $case = QaTestCase::firstOrCreate(['code' => $code], [
                'qa_phase_id' => $phase?->id, 'module' => $module, 'feature' => 'Phase 5 finishing', 'title' => $title,
                'expected_result' => 'Behaves as Phase 5 requires, in both themes, at desktop and phone widths.', 'severity' => 'medium',
                'automated_reference' => 'tests/Feature/'.$reference, 'status' => QaTestStatus::Pass, 'last_tested_at' => $at,
            ]);
            if ($case->wasRecentlyCreated) {
                QaTestResult::create(['qa_test_case_id' => $case->id, 'status' => QaTestStatus::Pass, 'actual_result' => 'Passed.',
                    'environment' => 'local_test_db', 'tested_by_name' => self::TESTER, 'tested_at' => $at, 'is_retest' => true]);
            }
        }

        foreach (self::VERIFIED as $code => [$commits, $note, $tests]) {
            $finding = QaFinding::where('code', $code)->first();
            if ($finding === null || $finding->status === QaFindingStatus::Verified) {
                continue;
            }
            $tracker->updateFinding($finding, ['needs_decision' => false, 'description' => $finding->description."\n\n".$note], $actor);
            foreach ($commits as $hash => $message) {
                if (! $finding->fixes()->where('commit_hash', $hash)->exists()) {
                    $tracker->linkFix($finding->fresh(), ['commit_hash' => $hash, 'commit_message' => $message, 'test_case_codes' => $tests,
                        'environment' => 'local_test_db', 'fixed_at' => $at], $actor);
                }
            }
            $tracker->changeFindingStatus($finding->fresh(), QaFindingStatus::Verified, $actor,
                'Retest '.$at->format('d M Y').': '.implode(', ', $tests).' pass; browser-checked on the throwaway e2e app.');
        }

        if ($run !== null) {
            QaTestRun::firstOrCreate(['name' => $run['name']], [
                'qa_phase_id' => $phase?->id, 'suite' => 'pest', 'kind' => 'targeted', 'environment' => 'local_test_db', 'commit' => $run['commit'],
                'total' => $run['total'], 'passed' => $run['passed'], 'failed' => $run['failed'], 'skipped' => $run['skipped'],
                'known_baseline' => $run['known_baseline'], 'new_regressions' => $run['failed'] - $run['known_baseline'],
                'duration_seconds' => $run['duration'], 'run_at' => $run['run_at'], 'notes' => $run['notes'],
                'result' => QaTestRun::resultFor($run['failed'], $run['failed'] - $run['known_baseline']),
            ]);
        }
    }
}
