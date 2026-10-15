<?php

namespace App\Console\Commands;

use App\Enums\QaEnvironment;
use App\Enums\QaFindingStatus;
use App\Enums\QaTestStatus;
use App\Models\QaFinding;
use App\Models\QaPhase;
use App\Models\QaTestCase;
use App\Models\User;
use App\Services\DataQuality\DataQualityAuditor;
use App\Services\DataQuality\DataQualityIssue;
use App\Services\Qa\QaTracker;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Record the data-quality audit of THIS database in the QA & Audit tracker.
 * Each check is a test case (QA-DQ-nnn): PASS when nothing is found, FAIL
 * with the count otherwise. A failing data check opens a finding of type
 * "Data quality" with status "HR Data Correction Required" (or Open with a
 * decision flag for policy checks) — never a code bug. A finding whose
 * issues are gone is verified. Only QA records are written; employee data
 * is read, never changed.
 */
class QaRecordDataQuality extends Command
{
    protected $signature = 'qa:record-data-quality
        {--environment=local : local | local_test_db | e2e | staging | production_read_only}
        {--phase=2 : QA phase number}
        {--user= : Email of the person recording}';

    protected $description = 'Record this database\'s data-quality checks in the QA & Audit tracker (QA records only)';

    private const SEVERITY = ['critical' => 'critical', 'high' => 'high', 'warning' => 'medium', 'info' => 'low'];

    /** Stable test / finding code for a check: its position in CHECKS (new checks are appended). */
    public static function code(string $check): string
    {
        return 'QA-DQ-'.str_pad((string) (array_search($check, array_keys(DataQualityAuditor::CHECKS), true) + 1), 3, '0', STR_PAD_LEFT);
    }

    public function handle(DataQualityAuditor $auditor, QaTracker $tracker): int
    {
        $environment = QaEnvironment::tryFrom((string) $this->option('environment'));
        if ($environment === null) {
            $this->error('Unknown --environment.');

            return self::INVALID;
        }

        $actor = $this->option('user') ? User::where('email', $this->option('user'))->first() : null;
        $system = $actor ?? new User(['name' => 'hrms:data-quality']);
        $phaseId = QaPhase::where('number', (int) $this->option('phase'))->value('id');
        $issues = $auditor->issues()->groupBy('check');
        $today = now()->format('d M Y H:i');

        $failed = $issues->keys()->count();
        $run = $tracker->recordRun([
            'name' => "Data quality — {$environment->label()} — {$today}",
            'qa_phase_id' => $phaseId,
            'suite' => 'data_quality',
            'kind' => 'targeted',
            'environment' => $environment->value,
            'total' => count(DataQualityAuditor::CHECKS),
            'passed' => count(DataQualityAuditor::CHECKS) - $failed,
            'failed' => $failed,
            'known_baseline' => $failed,   // data issues are HR corrections, not code regressions
            'notes' => "Read-only data-quality audit (hrms:data-quality). {$auditor->checkedCounts()['employees']} active employees checked.",
        ], $actor);

        $opened = $updated = $verified = 0;
        foreach (DataQualityAuditor::CHECKS as $check => $meta) {
            $code = self::code($check);
            /** @var Collection<int, DataQualityIssue> $found */
            $found = $issues->get($check, collect());

            $case = QaTestCase::where('code', $code)->first() ?? $tracker->createTestCase([
                'code' => $code, 'qa_phase_id' => $phaseId, 'module' => 'Data Quality', 'feature' => $meta['area'],
                'title' => 'No issue: '.$meta['label'], 'expected_result' => 'None found.',
                'steps' => 'php artisan hrms:data-quality (read-only)', 'severity' => self::SEVERITY[$meta['severity']],
                'automated_reference' => 'hrms:data-quality › '.$check,
            ], $system);

            $detail = $found->take(25)->map(fn (DataQualityIssue $i) => "- {$i->subjectLabel}: {$i->message}")->implode("\n")
                .($found->count() > 25 ? "\n- … and ".($found->count() - 25).' more' : '');
            $tracker->recordResult($case, $found->isEmpty() ? QaTestStatus::Pass : QaTestStatus::Fail, $system, $environment,
                $found->isEmpty() ? 'None found.' : "{$found->count()} found.", $found->isEmpty() ? null : $detail, null, $run);

            $finding = QaFinding::where('code', $code)->first();
            $description = $found->isEmpty() ? null : "{$found->count()} found on {$environment->label()} ({$today}):\n{$detail}\n\nSuggested action: {$meta['action']}";
            $openStatus = $meta['nature'] === 'policy' ? QaFindingStatus::Open : QaFindingStatus::HrCorrectionRequired;

            if ($found->isNotEmpty()) {
                if ($finding === null) {
                    $finding = $tracker->openFinding([
                        'code' => $code, 'qa_phase_id' => $phaseId, 'qa_test_case_id' => $case->id, 'kind' => 'data', 'module' => 'Data Quality',
                        'title' => $meta['label'], 'description' => $description, 'severity' => self::SEVERITY[$meta['severity']],
                        'risk' => $meta['area'].' — '.($meta['nature'] === 'policy' ? 'needs a decision' : 'HR data correction'),
                        'environment' => $environment->value, 'needs_decision' => $meta['nature'] === 'policy',
                    ], $system);
                    $opened++;
                } else {
                    $tracker->updateFinding($finding, ['description' => $description, 'environment' => $environment->value], $system);
                    $updated++;
                }
                // Move it only from states a person has not decided (Accepted Risk, Won't Fix, Investigating… stay).
                $current = $finding->fresh()->status;
                if ($current !== $openStatus && in_array($current, [QaFindingStatus::Open, QaFindingStatus::Verified, QaFindingStatus::Fixed, QaFindingStatus::RetestRequired], true)) {
                    $tracker->changeFindingStatus($finding->fresh(), $openStatus, $system, 'Detected by hrms:data-quality.');
                }
            } elseif ($finding !== null && $finding->status->isOpen()) {
                $tracker->changeFindingStatus($finding, QaFindingStatus::Verified, $system, "No longer detected by hrms:data-quality on {$environment->label()} ({$today}).");
                $verified++;
            }
        }

        $this->info("Recorded {$run->name}: ".count(DataQualityAuditor::CHECKS)." checks, {$failed} with issues. Findings opened {$opened}, updated {$updated}, verified {$verified}.");

        return self::SUCCESS;
    }
}
