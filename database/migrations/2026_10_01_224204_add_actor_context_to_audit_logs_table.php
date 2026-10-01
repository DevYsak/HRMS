<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central audit context (Phase 1 safety): which module/category an event
     * belongs to, a stable event code, the real actor behind an impersonated
     * session, and a per-request correlation id. All nullable — every
     * existing writer keeps working and simply leaves them empty.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('module', 40)->nullable()->after('role');
            $table->string('category', 40)->nullable()->after('module');
            $table->string('event', 80)->nullable()->after('category');
            // No FK: the audit trail must survive the impersonator's deletion.
            $table->unsignedBigInteger('impersonator_id')->nullable()->after('user_id');
            $table->string('request_id', 64)->nullable()->after('user_agent');

            $table->index(['category', 'created_at']);
            $table->index('event');
            $table->index('impersonator_id');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['category', 'created_at']);
            $table->dropIndex(['event']);
            $table->dropIndex(['impersonator_id']);
            $table->dropColumn(['module', 'category', 'event', 'impersonator_id', 'request_id']);
        });
    }
};
