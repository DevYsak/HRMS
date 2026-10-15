<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA & Audit tracker (Super Admin): phases, test cases, test runs, an
 * append-only result history, findings, the commits that fixed them,
 * evidence files, and one checklist table for module readiness, the
 * responsive matrix and demo readiness. It records QA only — nothing here
 * touches employee, attendance, leave, payroll or permission data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_phases', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('number')->unique();
            $table->string('name');
            $table->string('status', 20)->default('not_started');
            $table->string('owner')->nullable();
            $table->date('started_at')->nullable();
            $table->date('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('qa_test_cases', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('qa_phase_id')->nullable()->constrained('qa_phases')->nullOnDelete();
            $table->string('module', 60);
            $table->string('feature')->nullable();
            $table->string('role', 60)->nullable();
            $table->string('scope', 60)->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('precondition')->nullable();
            $table->text('steps')->nullable();
            $table->text('expected_result')->nullable();
            $table->string('route_name')->nullable();
            $table->string('severity', 10)->default('medium');
            // Pest file › test name when an automated test covers it.
            $table->string('automated_reference')->nullable();
            // The latest result, kept in step with qa_test_results (which is never overwritten).
            $table->string('status', 30)->default('not_tested');
            $table->timestamp('last_tested_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['module', 'status']);
        });

        Schema::create('qa_test_runs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('qa_phase_id')->nullable()->constrained('qa_phases')->nullOnDelete();
            $table->string('suite', 20)->default('pest');
            $table->string('environment', 30);
            $table->string('commit', 40)->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('passed')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('known_baseline')->default(0);
            $table->unsignedInteger('new_regressions')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('result', 30);
            $table->text('notes')->nullable();
            $table->timestamp('run_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('qa_test_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qa_test_case_id')->constrained('qa_test_cases')->cascadeOnDelete();
            $table->foreignId('qa_test_run_id')->nullable()->constrained('qa_test_runs')->nullOnDelete();
            $table->string('status', 30);
            $table->text('actual_result')->nullable();
            $table->string('environment', 30);
            $table->string('commit', 40)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('tested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tested_by_name')->nullable();
            $table->timestamp('tested_at');
            $table->boolean('is_retest')->default(false);
            $table->timestamps();
            $table->index(['qa_test_case_id', 'tested_at']);
        });

        Schema::create('qa_findings', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('qa_phase_id')->nullable()->constrained('qa_phases')->nullOnDelete();
            $table->foreignId('qa_test_case_id')->nullable()->constrained('qa_test_cases')->nullOnDelete();
            // functional | security | rbac | ui
            $table->string('kind', 20)->default('functional');
            $table->string('module', 60);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('severity', 10);
            $table->text('risk')->nullable();
            $table->string('role_affected', 60)->nullable();
            $table->string('scope_affected', 60)->nullable();
            $table->string('route')->nullable();
            // UI/UX findings: where it shows.
            $table->string('page')->nullable();
            $table->string('viewport', 60)->nullable();
            $table->string('theme', 20)->nullable();
            $table->string('status', 20)->default('open');
            $table->text('root_cause')->nullable();
            $table->text('fix_summary')->nullable();
            $table->text('retest_result')->nullable();
            $table->string('environment', 30)->nullable();
            $table->timestamp('found_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'severity']);
        });

        Schema::create('qa_fix_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qa_finding_id')->constrained('qa_findings')->cascadeOnDelete();
            $table->string('commit_hash', 40);
            $table->string('commit_message')->nullable();
            $table->json('files_changed')->nullable();
            $table->json('test_case_codes')->nullable();
            $table->text('developer_note')->nullable();
            $table->string('environment', 30)->nullable();
            $table->timestamp('fixed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('commit_hash');
        });

        Schema::create('qa_evidence', function (Blueprint $table) {
            $table->id();
            $table->morphs('evidenceable');
            // screenshot | screenshot_before | screenshot_after | pdf | log | note
            $table->string('kind', 30);
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('qa_checklist_items', function (Blueprint $table) {
            $table->id();
            // module | responsive | demo | deployment
            $table->string('category', 20);
            // the module, page or role
            $table->string('subject', 80);
            // e.g. functional / rbac, "320:light", login_works
            $table->string('dimension', 40);
            $table->string('status', 20)->default('not_tested');
            $table->text('note')->nullable();
            $table->string('commit', 40)->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->unique(['category', 'subject', 'dimension']);
        });
    }

    public function down(): void
    {
        foreach (['qa_checklist_items', 'qa_evidence', 'qa_fix_links', 'qa_findings', 'qa_test_results', 'qa_test_runs', 'qa_test_cases', 'qa_phases'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
