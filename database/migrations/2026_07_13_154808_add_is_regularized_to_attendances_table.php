<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // Authoritative flag: this day's attendance was set via an approved
            // regularisation. Persists regardless of the regularisation record's
            // later lifecycle, so the "Regularized" marker is always reliable.
            $table->boolean('is_regularized')->default(false)->after('status');
        });

        // Backfill: any attendance already linked to an approved regularisation.
        DB::table('attendances')
            ->whereIn('id', function ($q) {
                $q->select('attendance_id')
                    ->from('attendance_regularisations')
                    ->where('status', 'approved')
                    ->whereNotNull('attendance_id');
            })
            ->update(['is_regularized' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('is_regularized');
        });
    }
};
