<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4 Phase E — performance-linked increment engine (spec Part 4).
 * increment_cycles: one per financial year (Conexus FY Jul–Jun);
 * increment_matrices: band → % range per cycle (editable);
 * increment_proposals: one per eligible employee with the full calibration
 * trail (annual raw score, z, band, override) and money math.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('increment_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('financial_year', 9); // e.g. 2026-27
            $table->date('effective_date');
            $table->decimal('budget_percent', 5, 2)->default(10);
            $table->json('quarter_weights')->nullable(); // [25,25,25,25] default when null
            $table->string('status', 20)->default('draft'); // draft|calibration|proposed|approved|applied
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique('financial_year');
            $table->index('status');
        });

        Schema::create('increment_matrices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('increment_cycle_id')->constrained()->cascadeOnDelete();
            $table->char('band', 1); // A–E
            $table->decimal('min_percent', 5, 2);
            $table->decimal('max_percent', 5, 2);
            $table->decimal('default_percent', 5, 2);
            $table->timestamps();

            $table->unique(['increment_cycle_id', 'band']);
        });

        Schema::create('increment_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('increment_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('annual_raw_score', 6, 2)->nullable();
            $table->unsignedTinyInteger('quarters_counted')->default(0);
            $table->decimal('calibrated_z', 8, 4)->nullable(); // null = small-dept raw mapping
            $table->char('band', 1)->nullable();               // null = insufficient data
            $table->boolean('band_overridden')->default(false);
            $table->text('override_reason')->nullable();
            $table->decimal('current_gross', 12, 2)->default(0);
            $table->decimal('proposed_percent', 5, 2)->default(0);
            $table->decimal('proposed_amount', 12, 2)->default(0);
            $table->decimal('new_gross', 12, 2)->default(0);
            $table->boolean('promotion_flag')->default(false);
            $table->string('new_designation')->nullable();
            $table->string('status', 20)->default('draft'); // draft|pending|approved|rejected
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->string('letter_path')->nullable();
            $table->timestamps();

            $table->unique(['increment_cycle_id', 'employee_id']);
            $table->index(['increment_cycle_id', 'band']);
            $table->index(['increment_cycle_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('increment_proposals');
        Schema::dropIfExists('increment_matrices');
        Schema::dropIfExists('increment_cycles');
    }
};
