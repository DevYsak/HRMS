<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4 Part 3 — a team layer below departments: Company → Department (Head) →
 * Team (Team Lead) → Employees. Named department_teams to avoid the dormant
 * Jetstream `teams` table (user workspaces), which is a different concept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('team_lead_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('secondary_lead_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status', 20)->default('active'); // active | inactive
            $table->timestamps();

            $table->index(['department_id', 'status']);
        });

        Schema::create('department_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_team_id')->constrained('department_teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('joined_at')->nullable();
            $table->date('left_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['employee_id', 'is_active']);
            $table->index(['department_team_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_team_members');
        Schema::dropIfExists('department_teams');
    }
};
