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
            $table->string('code', 50)->nullable()->after('name');
            $table->enum('component_type', ['earning', 'deduction', 'employer_contribution'])->nullable()->after('code');
            $table->enum('calculation_type', ['fixed', 'percentage', 'formula'])->default('fixed')->after('component_type');
            $table->decimal('percentage_value', 5, 2)->nullable()->after('calculation_type');
            $table->enum('percentage_basis', ['basic', 'gross', 'ctc', 'component'])->nullable()->after('percentage_value');
            $table->unsignedBigInteger('percentage_of_component_id')->nullable()->after('percentage_basis');
            $table->text('formula_expression')->nullable()->after('percentage_of_component_id');
            $table->boolean('is_taxable')->default(true)->after('formula_expression');
            $table->boolean('is_pf_applicable')->default(false)->after('is_taxable');
            $table->boolean('is_esi_applicable')->default(false)->after('is_pf_applicable');
            $table->unsignedSmallInteger('display_order')->default(0)->after('is_esi_applicable');
        });

        // Backfill component_type from existing type column, generate code from name
        DB::statement("UPDATE salary_components SET component_type = type, code = UPPER(REPLACE(REPLACE(REPLACE(name, ' ', '_'), '-', '_'), '/', '_'))");

        Schema::table('salary_components', function (Blueprint $table) {
            $table->unique('code');
            $table->foreign('percentage_of_component_id')
                ->references('id')->on('salary_components')
                ->nullOnDelete();
        });
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
