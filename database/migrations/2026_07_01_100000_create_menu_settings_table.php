<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin overrides for the employee sidebar's top-level items: enable/disable,
 * reorder and relabel. Read fail-open — a missing row means the item shows with
 * its coded defaults, so the sidebar never breaks if this table is empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();           // catalog key, e.g. 'attendance'
            $table->string('label')->nullable();       // optional label override
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_settings');
    }
};
