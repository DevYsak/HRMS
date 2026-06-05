<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            // Central allocation — HR sets this once; all employees inherit it on initialization
            $table->decimal('annual_allocation_days', 6, 2)->nullable()->after('attachment_required');
            // System-controlled types cannot be manually adjusted by HR
            $table->boolean('is_system_controlled')->default(false)->after('annual_allocation_days');
        });
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn(['annual_allocation_days', 'is_system_controlled']);
        });
    }
};
