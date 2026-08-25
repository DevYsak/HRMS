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
        Schema::create('payroll_approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_id')->constrained()->cascadeOnDelete();
            // Snapshotted by value from the policy at submit time — no FK back to
            // payroll_approval_policies, so editing/deleting a policy row later
            // never touches an in-flight payroll's steps.
            $table->unsignedTinyInteger('level');
            $table->string('label', 100);
            $table->enum('approver_type', ['hr_admin', 'finance', 'director', 'super_admin', 'specific_user']);
            $table->foreignId('specific_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected', 'skipped'])->default('pending');
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acted_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            // Rows are only ever bulk-inserted fresh per submission, never
            // reordered in place, so a hard unique constraint here is safe.
            $table->unique(['payroll_id', 'level']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_approval_steps');
    }
};
