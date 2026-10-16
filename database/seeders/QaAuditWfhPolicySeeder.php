<?php

namespace Database\Seeders;

use App\Enums\QaFindingStatus;
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
 * P3-07 WFH policy (approved 16 Oct 2026) in the QA tracker: the decision on
 * QA-F-037, its test cases, the fix commit and the retest. QA records only;
 * idempotent (codes and commits already present are left alone).
 */
class QaAuditWfhPolicySeeder extends Seeder
{
    private const TESTER = 'Claude Code (automated Pest suite)';

    private const COMMIT = 'e6c70e5';

    private const RETESTED_AT = '2026-10-16 12:40:00';

    /** code => [title, test name in Phase3/WfhConsistencyTest] */
    private const CASES = [
        'QA-P3-019' => ['Each attended day: approved plan vs recorded mode → valid / mismatch / unapproved', 'each attended day is classified by comparing the approved plan with the recorded mode'],
        'QA-P3-020' => ['Data Quality, Employee 360 and the WFH Consistency report show the same mismatches', 'Data Quality, Employee 360 and the attendance report show the same mismatches'],
        'QA-P3-021' => ['The WFH check, command and screens rewrite no attendance or request', 'the checks, the command and the screens rewrite no attendance or request'],
        'QA-P3-022' => ['Approval creates no attendance; a pending request authorises nothing; a punch approves nothing', 'approving WFH creates no attendance, and a punch never approves a request'],
        'QA-P3-023' => ['Approved WFH preselects WFH at clock-in; the recorded punch stays authoritative', 'an approved request preselects WFH, but the punch records what the employee chose'],
    ];

    public function run(): void
    {
        $finding = QaFinding::where('code', 'QA-F-037')->first();
        if ($finding === null || $finding->fixes()->where('commit_hash', self::COMMIT)->exists()) {
            return;
        }

        $tracker = app(QaTracker::class);
        $actor = new User(['name' => 'P3-07 WFH policy']);
        $phase = QaPhase::where('number', 3)->first();
        $at = Carbon::parse(self::RETESTED_AT);

        foreach (self::CASES as $code => [$title, $test]) {
            $case = QaTestCase::firstOrCreate(['code' => $code], [
                'qa_phase_id' => $phase?->id, 'module' => 'WFH', 'feature' => 'Cross-module consistency', 'title' => $title,
                'expected_result' => 'Approved WFH (plan) and the recorded mode are compared, never merged or rewritten.', 'severity' => 'medium',
                'automated_reference' => 'tests/Feature/Phase3/WfhConsistencyTest > '.$test, 'status' => QaTestStatus::Pass, 'last_tested_at' => $at,
            ]);
            if ($case->wasRecentlyCreated) {
                QaTestResult::create(['qa_test_case_id' => $case->id, 'status' => QaTestStatus::Pass, 'actual_result' => 'Passed after the fix.',
                    'environment' => 'local_test_db', 'tested_by_name' => self::TESTER, 'tested_at' => $at, 'is_retest' => true]);
            }
        }

        $tracker->updateFinding($finding, [
            'needs_decision' => false,
            'description' => $finding->description."\n\nDecision (16 Oct 2026, approved): an approved WFH request is the authorised / planned mode; attendance work_mode is the recorded mode; they stay separate and are compared. Approved + WFH = valid; approved + another mode = mismatch for review; WFH without approval = unauthorised / unlinked for review. Pending requests change nothing, approval creates no attendance, a punch approves nothing, history is never rewritten. Approved WFH is preselected at clock-in; the punch records what was chosen.",
        ], $actor);

        $tracker->linkFix($finding->fresh(), ['commit_hash' => self::COMMIT, 'commit_message' => 'feat(wfh): compare approved WFH with the recorded attendance mode',
            'files_changed' => ['app/Services/Attendance/WfhConsistencyService.php', 'app/Services/DataQuality/DataQualityAuditor.php', 'app/Livewire/Employees/DataQuality.php',
                'app/Services/People/Employee360Service.php', 'app/Services/AttendanceReportBuilder.php', 'app/Livewire/Attendance/AttendanceTracker.php'],
            'test_case_codes' => array_keys(self::CASES), 'environment' => 'local_test_db', 'fixed_at' => $at], $actor);

        $tracker->changeFindingStatus($finding->fresh(), QaFindingStatus::Verified, $actor,
            'Retest '.$at->format('d M Y').': '.implode(', ', array_keys(self::CASES)).' pass; DataQuality, QA, Phase 3, attendance, Employee 360 and WFH suites 249/249. The local data (42 unapproved WFH days, 8 employees) remains for HR review in People → Data Quality — nothing was rewritten.');
    }
}
