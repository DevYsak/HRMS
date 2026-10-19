<?php

use Database\Seeders\QaAuditPhaseFourDecisionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Record the Phase 4 policy decisions and their fixes in the QA tracker
 * (test cases, fix commits, retests, verified findings) and the confirming
 * full run. QA records only; idempotent.
 */
return new class extends Migration
{
    /** The confirming full run at a stable HEAD (f3b274a, unchanged during the run). */
    private const FULL_RUN = [
        'name' => '2026-10-19 Phase 4 close — full suite', 'commit' => 'f3b274a',
        'total' => 3237, 'passed' => 3223, 'failed' => 13, 'skipped' => 1, 'known_baseline' => 13,
        'duration' => 3744, 'run_at' => '2026-10-19 21:52:00',
        'notes' => 'Full Pest suite at f3b274a with every Phase 4 decision implemented; HEAD unchanged during the run. 13 failures = the 12 known baseline (QA-BASE-001…012) + RegularisationPreservesOriginalTest, the documented date-flaky baseline that fails when yesterday was a weekly off (run on a Monday). No new regressions. The run before it (16d28a7) also had three fixture failures, fixed in f3b274a: two role tests assigning Finance (now Super Admin only) and a random factory joining date.',
    ];

    public function up(): void
    {
        (new QaAuditPhaseFourDecisionsSeeder)->run(self::FULL_RUN);
    }

    public function down(): void
    {
        // QA history is never deleted.
    }
};
