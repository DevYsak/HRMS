<?php

use App\Services\Security\PermissionScopes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permissions get a data scope (own / team / department / selected
 * departments / all / none).
 *
 *  - permissions.is_scoped marks the keys whose effect depends on whose data
 *    it is, so Roles & Permissions knows where to offer a scope.
 *  - role_permission.scope + department_ids hold the scope a role grants.
 *    NULL keeps the role's default (PermissionScopes::ROLE_DEFAULTS), which
 *    reproduces today's reach — nothing changes until an admin chooses.
 *  - user_permission_overrides lets an admin grant, narrow or revoke one
 *    permission for one person without a new role.
 *
 * Adds export_attendance, view_overtime, manage_overtime and
 * manage_notifications and grants them to the built-in roles. Additive and
 * idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->boolean('is_scoped')->default(false)->after('module');
        });

        Schema::table('role_permission', function (Blueprint $table) {
            $table->string('scope', 30)->nullable()->after('permission_id');
            $table->json('department_ids')->nullable()->after('scope');
        });

        Schema::create('user_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            // grant: holds the permission (whatever the role says) at `scope`;
            // revoke: does not hold it at all.
            $table->string('effect', 10)->default('grant');
            $table->string('scope', 30)->nullable();
            $table->json('department_ids')->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'permission_id']);
        });

        $now = now();

        foreach (PermissionScopes::NEW_PERMISSIONS as $module => $defs) {
            DB::table('permissions')->insertOrIgnore(array_map(fn (array $d) => $d + [
                'module' => $module, 'created_at' => $now, 'updated_at' => $now,
            ], $defs));
        }

        DB::table('permissions')->whereIn('key', PermissionScopes::SCOPED)->update(['is_scoped' => true]);

        foreach (PermissionScopes::NEW_ROLE_GRANTS as $slug => $keys) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');
            if ($roleId === null) {
                continue;
            }

            $ids = DB::table('permissions')->whereIn('key', $keys)->pluck('id');
            DB::table('role_permission')->insertOrIgnore($ids->map(fn ($id) => [
                'role_id' => $roleId, 'permission_id' => $id, 'created_at' => $now, 'updated_at' => $now,
            ])->all());
        }

        // Super Admin holds every key.
        if ($superAdmin = DB::table('roles')->where('slug', 'super_admin')->value('id')) {
            DB::table('role_permission')->insertOrIgnore(DB::table('permissions')->pluck('id')->map(fn ($id) => [
                'role_id' => $superAdmin, 'permission_id' => $id, 'created_at' => $now, 'updated_at' => $now,
            ])->all());
        }

        DB::table('roles')->pluck('id')->each(fn ($id) => Cache::forget("role_{$id}_permission_keys"));
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permission_overrides');

        Schema::table('role_permission', function (Blueprint $table) {
            $table->dropColumn(['scope', 'department_ids']);
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn('is_scoped');
        });

        $keys = collect(PermissionScopes::NEW_PERMISSIONS)->flatten(1)->pluck('key')->all();
        DB::table('permissions')->whereIn('key', $keys)->delete();
    }
};
