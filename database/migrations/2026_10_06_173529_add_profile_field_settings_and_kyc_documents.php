<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Profile completion that HR configures, and KYC documents.
 *
 * - profile_field_settings: HR marks each self-service profile field (and
 *   each KYC document type, keyed "kyc:<type>") Required, Optional or
 *   HR-only. No row = the coded default, so nothing changes until HR does.
 * - documents.category gains 'kyc'; documents.kyc_type says which proof it
 *   is. Additive: existing rows and categories are untouched.
 * - view_kyc_documents: open employees' KYC documents (HR Admin by
 *   default). Managing documents in general is not enough.
 *
 * Idempotent; down() reverses the additions (only when no KYC document
 * exists, so no row is ever orphaned).
 */
return new class extends Migration
{
    private const CATEGORIES = "'policy','contract','form','notice','other','personal','payslip','warning_letter','pip','promotion','performance_review'";

    public function up(): void
    {
        if (! Schema::hasTable('profile_field_settings')) {
            Schema::create('profile_field_settings', function (Blueprint $table) {
                $table->id();
                $table->string('field_key', 80)->unique();
                $table->string('requirement', 20); // required | optional | hr_only
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `documents` MODIFY COLUMN `category` ENUM('.self::CATEGORIES.",'kyc') NOT NULL DEFAULT 'other'");
        }

        if (! Schema::hasColumn('documents', 'kyc_type')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->string('kyc_type', 40)->nullable()->after('category');
            });
        }

        $now = now();
        DB::table('permissions')->insertOrIgnore([
            'key' => 'view_kyc_documents', 'label' => 'View KYC Documents',
            'description' => 'Open employees\' identity, address and bank proofs', 'module' => 'Documents',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $permission = DB::table('permissions')->where('key', 'view_kyc_documents')->value('id');
        $hr = DB::table('roles')->where('slug', 'hr_admin')->value('id');

        if ($permission !== null && $hr !== null) {
            DB::table('role_permission')->insertOrIgnore(['role_id' => $hr, 'permission_id' => $permission, 'created_at' => $now, 'updated_at' => $now]);
            Cache::forget("role_{$hr}_permission_keys");
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('key', 'view_kyc_documents')->value('id');
        if ($id !== null) {
            DB::table('role_permission')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }

        if (DB::table('documents')->where('category', 'kyc')->exists()) {
            return;
        }

        if (Schema::hasColumn('documents', 'kyc_type')) {
            Schema::table('documents', fn (Blueprint $table) => $table->dropColumn('kyc_type'));
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `documents` MODIFY COLUMN `category` ENUM('.self::CATEGORIES.") NOT NULL DEFAULT 'other'");
        }

        Schema::dropIfExists('profile_field_settings');
    }
};
