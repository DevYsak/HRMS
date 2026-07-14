<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Department/shift access scope for HR & managers. NULL/empty on both =
     * company-wide (the existing behaviour). When set, the user only sees and
     * approves attendance for employees in those departments and/or shifts —
     * so two HR admins can own different departments, or be split by shift.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('scope_departments')->nullable()->after('role_id');
            $table->json('scope_shifts')->nullable()->after('scope_departments');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['scope_departments', 'scope_shifts']);
        });
    }
};
