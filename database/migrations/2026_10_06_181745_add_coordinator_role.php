<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Coordinator role: monitors attendance exceptions (absent, late,
 * missing check-out, pending regularisations) for the employees and
 * departments HR assigns, reminds employees and escalates to the manager or
 * HR. No approval, payroll or settings powers.
 *
 * - roles: 'coordinator' (system role) with the employee self-service
 *   permissions plus monitor_attendance_exceptions and remind_employees.
 * - permissions: monitor_attendance_exceptions, remind_employees,
 *   assign_coordinators (HR Admin gets all three).
 * - coordinator_assignments: which employees / departments a coordinator
 *   monitors (HR-configured).
 * - attendance_settings: the reminder interval and the late threshold
 *   beyond grace that coordinator alerts use.
 *
 * All additive and idempotent.
 */
return new class extends Migration
{
    /** @var array<string, array{label: string, description: string}> */
    private const PERMISSIONS = [
        'monitor_attendance_exceptions' => ['label' => 'Monitor Attendance Exceptions', 'description' => 'See absent, late, missing check-out and regularisation status for assigned employees'],
        'remind_employees' => ['label' => 'Remind / Escalate Attendance', 'description' => 'Remind an employee about an attendance exception, or escalate it to their manager or HR'],
        'assign_coordinators' => ['label' => 'Assign Coordinators', 'description' => 'Choose which employees and departments each coordinator monitors'],
    ];

    private const COORDINATOR_KEYS = [
        'view_dashboard', 'view_directory', 'view_org_chart', 'view_leave', 'apply_leave', 'view_payslips',
        'view_performance', 'view_documents', 'acknowledge_documents', 'edit_own_profile', 'request_profile_change',
        'view_attendance', 'monitor_attendance_exceptions', 'remind_employees',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('coordinator_assignments')) {
            Schema::create('coordinator_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('coordinator_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('employee_id')->nullable()->constrained()->cascadeOnDelete();
                $table->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['coordinator_user_id', 'employee_id']);
                $table->unique(['coordinator_user_id', 'department_id']);
            });
        }

        Schema::table('attendance_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('attendance_settings', 'coordinator_reminder_hours')) {
                $table->unsignedSmallInteger('coordinator_reminder_hours')->default(2);
                $table->unsignedSmallInteger('coordinator_late_minutes')->default(15);
            }
        });

        $now = now();

        foreach (self::PERMISSIONS as $key => $def) {
            DB::table('permissions')->insertOrIgnore(['key' => $key, ...$def, 'module' => 'Attendance', 'created_at' => $now, 'updated_at' => $now]);
        }

        DB::table('roles')->insertOrIgnore([
            'slug' => 'coordinator', 'name' => 'Coordinator',
            'description' => 'Monitors attendance exceptions for assigned employees; reminds and escalates.',
            'is_system' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $grant = function (string $roleSlug, array $keys) use ($now): void {
            $role = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if ($role === null) {
                return;
            }
            foreach (DB::table('permissions')->whereIn('key', $keys)->pluck('id') as $permission) {
                DB::table('role_permission')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission, 'created_at' => $now, 'updated_at' => $now]);
            }
            Cache::forget("role_{$role}_permission_keys");
        };

        $grant('coordinator', self::COORDINATOR_KEYS);
        $grant('hr_admin', array_keys(self::PERMISSIONS));
    }

    public function down(): void
    {
        // The role and table stay if anyone holds the role (no orphaned user).
        $role = DB::table('roles')->where('slug', 'coordinator')->value('id');
        if ($role !== null && ! DB::table('users')->where('role_id', $role)->exists()) {
            DB::table('role_permission')->where('role_id', $role)->delete();
            DB::table('roles')->where('id', $role)->delete();
        }

        $ids = DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        Schema::dropIfExists('coordinator_assignments');

        Schema::table('attendance_settings', function (Blueprint $table) {
            if (Schema::hasColumn('attendance_settings', 'coordinator_reminder_hours')) {
                $table->dropColumn(['coordinator_reminder_hours', 'coordinator_late_minutes']);
            }
        });
    }
};
