<?php

use Database\Seeders\QaAuditPhaseFiveFinishSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Record the Phase 5 finishing round in the QA tracker: QA-F-060 / 061 /
 * 066 / 082 verified with their commits, the new test cases, and the focused
 * regression run. QA records only; idempotent.
 */
return new class extends Migration
{
    /** The focused run over the uncommitted finishing changes (HEAD ce1f910 + working tree). */
    private const FOCUSED_RUN = [
        'name' => '2026-10-26 Phase 5 finishing — focused regression (196 files)', 'commit' => 'ce1f910',
        'total' => 1913, 'passed' => 1897, 'failed' => 16, 'skipped' => 0, 'known_baseline' => 6,
        'duration' => 3192, 'run_at' => '2026-10-26 11:40:25',
        'notes' => 'Tests of every component whose view changed, plus PDF / payslip / payroll / settings / theme / Phase 5 / QA tests. 6 failures are known baseline (AttendanceCommandCenter ×2, MyTimeOffPageTest — on the other session\'s uncommitted My Time Off page —, ProcessPayslipActions, ProcessPendingFinanceAffordance ×2). The other 10 were one regression in the uncommitted change: the bundled PNG logo fallback made every PDF embed an image, and dompdf needs GD (not installed locally) — payslip / letter PDFs returned 500. Fixed before commit (fallback only when GD is loaded); the 7 affected test files re-ran green (72 passed). Committed as 6110550 and a79b951.',
    ];

    /** The 7 affected test files re-run on the committed fix (a79b951). */
    private const RECHECK_RUN = [
        'name' => '2026-10-26 Phase 5 finishing — recheck after the GD fix', 'commit' => 'a79b951',
        'total' => 72, 'passed' => 72, 'failed' => 0, 'skipped' => 0, 'known_baseline' => 0,
        'duration' => 173, 'run_at' => '2026-10-26 12:55:33',
        'notes' => 'BrandingDocuments, CombinedPayslipPrint, NotificationRoleDelivery, PayrollAuditSoftEvents, PayrollAccess, IncrementEngine and ComplianceReviewRoundTwo — every file the focused run failed on outside the known baseline — on the committed code: all green.',
    ];

    public function up(): void
    {
        $seeder = new QaAuditPhaseFiveFinishSeeder;
        $seeder->run(self::FOCUSED_RUN);
        $seeder->run(self::RECHECK_RUN);
    }

    public function down(): void
    {
        // QA history is never deleted.
    }
};
