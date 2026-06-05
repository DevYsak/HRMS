<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Probation dual-approval: manager confirms → HR co-approves
            $table->foreignId('probation_confirmed_by')->nullable()->after('probation_extension_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('probation_confirmed_at')->nullable()->after('probation_confirmed_by');
            $table->foreignId('probation_hr_approved_by')->nullable()->after('probation_confirmed_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('probation_hr_approved_at')->nullable()->after('probation_hr_approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('probation_confirmed_by');
            $table->dropColumn('probation_confirmed_at');
            $table->dropConstrainedForeignId('probation_hr_approved_by');
            $table->dropColumn('probation_hr_approved_at');
        });
    }
};
