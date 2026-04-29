<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIX 1 — Spec §3.1 Employee Management
 * Adds the profile fields required by the spec that were missing from the initial migration:
 * phone, date_of_birth, gender, address, emergency_contact, photo
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('employee_id');
            $table->date('date_of_birth')->nullable()->after('phone');
            $table->string('gender', 20)->nullable()->after('date_of_birth');
            $table->text('address')->nullable()->after('gender');
            $table->string('emergency_contact')->nullable()->after('address');
            $table->string('photo')->nullable()->after('emergency_contact');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['phone', 'date_of_birth', 'gender', 'address', 'emergency_contact', 'photo']);
        });
    }
};
