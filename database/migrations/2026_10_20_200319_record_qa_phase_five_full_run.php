<?php

use Database\Seeders\QaAuditPhaseFiveDecisionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Record the Phase 5 full suite at a stable HEAD in the QA tracker.
 * Phase 5 stays in progress: branding (QA-F-060), theme coverage
 * (QA-F-061), remaining date formats (QA-F-066) and the pages deferred for
 * another session's work (QA-F-082) are still open. QA records only.
 */
return new class extends Migration
{
    /** The full run at a50a156 (HEAD unchanged from start to end). */
    private const FULL_RUN = [
        'name' => '2026-10-20 Phase 5 — full suite', 'suite' => 'pest', 'kind' => 'full', 'environment' => 'local_test_db', 'commit' => 'a50a156',
        'total' => 3270, 'passed' => 3257, 'failed' => 12, 'skipped' => 1, 'known_baseline' => 12,
        'duration' => 10756, 'run_at' => '2026-10-20 17:03:24',
        'notes' => 'Full Pest suite at a50a156 with every Phase 5 change (round 1, the approved decisions QA-F-079 / 080 / 081, systemic UI, journey fixes); HEAD unchanged during the run. 12 failures = the known baseline QA-BASE-001…012; no new regressions. Another session held all edits and commits for the duration.',
    ];

    public function up(): void
    {
        (new QaAuditPhaseFiveDecisionsSeeder)->run(fullRun: self::FULL_RUN);
    }

    public function down(): void
    {
        // QA history is never deleted.
    }
};
