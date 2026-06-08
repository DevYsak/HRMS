<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a generic polymorphic link so Warning Letters, PIPs, Promotion
 * Recommendations, and Performance Reviews can attach versioned,
 * acknowledgeable documents through the existing documents module
 * instead of maintaining their own file columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->nullableMorphs('documentable');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropMorphs('documentable');
        });
    }
};
