<?php

use App\Enums\QaFindingStatus;
use App\Models\QaFinding;
use App\Models\QaFixLink;
use App\Models\QaTestRun;
use Database\Seeders\QaAuditSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA dashboard "Last verified build" and "Open blockers":
 *  - qa_test_runs.kind: full | security | ui | targeted | manual, so the
 *    latest full / security / UI run can be shown;
 *  - qa_findings.needs_decision: the finding waits on a policy decision,
 *    not on code.
 * Records the Phase 1 run as a security (RBAC) run, flags the open Phase 1
 * policy questions, and closes QA-F-022 with its fix d90ee89. QA records only.
 */
return new class extends Migration
{
    private const POLICY_FINDINGS = ['QA-F-014', 'QA-F-015', 'QA-F-016', 'QA-F-021'];

    public function up(): void
    {
        Schema::table('qa_test_runs', function (Blueprint $table) {
            $table->string('kind', 20)->default('targeted')->after('suite');
        });
        Schema::table('qa_findings', function (Blueprint $table) {
            $table->boolean('needs_decision')->default(false)->after('status');
        });

        // Databases seeded before QA-F-022 existed get it now (idempotent).
        (new QaAuditSeeder)->run();

        QaTestRun::where('name', '2026-10-15 Phase 1 RBAC Regression')->update(['kind' => 'security']);
        QaFinding::whereIn('code', self::POLICY_FINDINGS)->update(['needs_decision' => true]);

        $finding = QaFinding::where('code', 'QA-F-022')->first();
        if ($finding !== null && ! $finding->fixes()->where('commit_hash', 'like', 'd90ee89%')->exists()) {
            QaFixLink::create([
                'qa_finding_id' => $finding->id,
                'commit_hash' => 'd90ee89',
                'commit_message' => 'test(settings): align company branding test with new logo labels',
                'files_changed' => ['tests/Feature/Settings/CompanySettingsTest.php'],
                'test_case_codes' => [],
                'developer_note' => 'Test now checks "Logo (light mode)" and "Logo (dark mode)"; CompanySettingsTest 8/8 pass.',
                'environment' => 'local_test_db',
                'fixed_at' => '2026-10-15 14:05:00',
            ]);
            $finding->update([
                'status' => QaFindingStatus::Verified,
                'fix_summary' => 'test(settings): align company branding test with new logo labels',
                'retest_result' => 'CompanySettingsTest: 8 passed (local test DB, d90ee89).',
                'closed_at' => '2026-10-15 14:05:00',
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('qa_test_runs', fn (Blueprint $table) => $table->dropColumn('kind'));
        Schema::table('qa_findings', fn (Blueprint $table) => $table->dropColumn('needs_decision'));
    }
};
