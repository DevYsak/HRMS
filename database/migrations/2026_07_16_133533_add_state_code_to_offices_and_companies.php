<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give Professional Tax a real jurisdiction source.
 *
 * PT is a state levy, but nothing recorded which state an employee worked in, so
 * the engine applied Maharashtra rates to everyone. Offices carry the state where
 * work is performed (the PT nexus); the company default covers employees whose
 * office has none set yet.
 *
 * `state_code` is the 2-letter code used as statutory_rules.jurisdiction (e.g. MH,
 * KA, WB).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->string('state_code', 8)->nullable()->after('city');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->string('default_state_code', 8)->nullable()->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->dropColumn('state_code');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('default_state_code');
        });
    }
};
