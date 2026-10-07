<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting a regularisation keeps the row (soft delete) with who deleted it,
 * so the request stays traceable next to its audit-log entry.
 *
 * Purely additive: two nullable columns, no data rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_regularisations', function (Blueprint $table) {
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->after('deleted_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_regularisations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropSoftDeletes();
        });
    }
};
