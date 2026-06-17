<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_components', function (Blueprint $table) {
            if (! Schema::hasColumn('salary_components', 'code')) {
                $table->string('code', 50)->nullable()->after('name');
            }
            if (! Schema::hasColumn('salary_components', 'component_type')) {
                $table->enum('component_type', ['earning', 'deduction', 'employer_contribution'])->nullable()->after('code');
            }
            if (! Schema::hasColumn('salary_components', 'calculation_type')) {
                $table->enum('calculation_type', ['fixed', 'percentage', 'formula'])->default('fixed')->after('component_type');
            }
            if (! Schema::hasColumn('salary_components', 'percentage_value')) {
                $table->decimal('percentage_value', 5, 2)->nullable()->after('calculation_type');
            }
            if (! Schema::hasColumn('salary_components', 'percentage_basis')) {
                $table->enum('percentage_basis', ['basic', 'gross', 'ctc', 'component'])->nullable()->after('percentage_value');
            }
            if (! Schema::hasColumn('salary_components', 'percentage_of_component_id')) {
                $table->unsignedBigInteger('percentage_of_component_id')->nullable()->after('percentage_basis');
            }
            if (! Schema::hasColumn('salary_components', 'formula_expression')) {
                $table->text('formula_expression')->nullable()->after('percentage_of_component_id');
            }
            if (! Schema::hasColumn('salary_components', 'is_taxable')) {
                $table->boolean('is_taxable')->default(true)->after('formula_expression');
            }
            if (! Schema::hasColumn('salary_components', 'is_pf_applicable')) {
                $table->boolean('is_pf_applicable')->default(false)->after('is_taxable');
            }
            if (! Schema::hasColumn('salary_components', 'is_esi_applicable')) {
                $table->boolean('is_esi_applicable')->default(false)->after('is_pf_applicable');
            }
            if (! Schema::hasColumn('salary_components', 'display_order')) {
                $table->unsignedSmallInteger('display_order')->default(0)->after('is_esi_applicable');
            }
        });

        // Backfill component_type from the legacy type column, and generate code from name.
        // Both are guarded so re-running on an already-populated table is a no-op.
        if (Schema::hasColumn('salary_components', 'type')) {
            DB::statement('UPDATE salary_components SET component_type = type WHERE component_type IS NULL AND type IS NOT NULL');
        }
        DB::statement("UPDATE salary_components SET code = UPPER(REPLACE(REPLACE(REPLACE(name, ' ', '_'), '-', '_'), '/', '_')) WHERE code IS NULL OR code = ''");

        // Unique index on code — add only if it does not already exist.
        $hasCodeUnique = ! empty(DB::select(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?
             LIMIT 1',
            ['salary_components', 'salary_components_code_unique']
        ));

        if (! $hasCodeUnique) {
            Schema::table('salary_components', function (Blueprint $table) {
                $table->unique('code');
            });
        }

        // Self-referencing foreign key — add only if it does not already exist.
        $hasFk = ! empty(DB::select(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = ?
             LIMIT 1',
            ['salary_components', 'salary_components_percentage_of_component_id_foreign', 'FOREIGN KEY']
        ));

        if (! $hasFk) {
            Schema::table('salary_components', function (Blueprint $table) {
                $table->foreign('percentage_of_component_id')
                    ->references('id')->on('salary_components')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('salary_components', function (Blueprint $table) {
            $table->dropForeign(['percentage_of_component_id']);
            $table->dropUnique(['code']);
            $table->dropColumn([
                'code', 'component_type', 'calculation_type',
                'percentage_value', 'percentage_basis', 'percentage_of_component_id',
                'formula_expression', 'is_taxable', 'is_pf_applicable',
                'is_esi_applicable', 'display_order',
            ]);
        });
    }
};
