<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payroll_approval_policies', function (Blueprint $table) {
            $table->id();
            // Server-renumbered on every create/delete/reorder — never user-edited
            // directly, so no unique constraint here (a "swap 2 and 3" reorder
            // would transiently collide against one).
            $table->unsignedTinyInteger('level')->default(1);
            $table->string('label', 100);
            $table->enum('approver_type', ['hr_admin', 'finance', 'director', 'super_admin', 'specific_user']);
            $table->foreignId('specific_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index('level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_approval_policies');
    }
};
