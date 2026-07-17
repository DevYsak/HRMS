<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Effective-dated statutory rules (EPF, ESI, Professional Tax, Income Tax).
 *
 * These rates previously lived as PHP constants in StatutoryService, so a Finance
 * Act revision needed a code deploy, and a payslip reprinted for an earlier year
 * silently used today's rates. Rows here are versioned by effective_from/
 * effective_to, letting a payroll re-run for a closed period resolve the rates
 * that were actually in force at the time.
 *
 * `config` holds the rate shape for the rule type — see StatutoryRuleType for the
 * documented payload of each.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_rules', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);

            // State code for jurisdiction-scoped rules — Professional Tax is levied
            // per state. Null means the rule applies nationally.
            $table->string('jurisdiction', 8)->nullable();

            // Tax regime discriminator: 'new' / 'old' for income_tax, null otherwise.
            $table->string('regime', 16)->nullable();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('config');
            $table->boolean('is_active')->default(true);
            $table->string('label')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Resolution filters type + jurisdiction + regime, then orders on
            // effective_from — the index mirrors that access path.
            $table->index(['type', 'jurisdiction', 'regime', 'effective_from'], 'statutory_rules_resolution_index');
            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_rules');
    }
};
