<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two fixes to the QA tables:
 *  - "hr_correction_required" (HR Data Correction Required, for
 *    data-quality findings) is longer than the 20-character status column;
 *  - MySQL gave the first NOT NULL timestamp of qa_test_runs (run_at) and
 *    qa_test_results (tested_at) ON UPDATE CURRENT_TIMESTAMP, so editing a
 *    run rewrote when it ran. They become plain timestamps, and the Phase 1
 *    run gets its real time back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qa_findings', function (Blueprint $table) {
            $table->string('status', 30)->default('open')->change();
        });
        Schema::table('qa_test_runs', function (Blueprint $table) {
            $table->timestamp('run_at')->useCurrent()->change();
        });
        Schema::table('qa_test_results', function (Blueprint $table) {
            $table->timestamp('tested_at')->useCurrent()->change();
        });

        DB::table('qa_test_runs')->where('name', '2026-10-15 Phase 1 RBAC Regression')->update(['run_at' => '2026-10-15 12:40:00']);
    }

    public function down(): void
    {
        Schema::table('qa_findings', function (Blueprint $table) {
            $table->string('status', 20)->default('open')->change();
        });
    }
};
