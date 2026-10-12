<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company-wide look and feel (Settings → Brand & Layout). One row; the
 * values are validated against App\Services\Appearance\UiTheme::SCHEMA on
 * every read, so an empty or partial row simply means "current design".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ui_theme_settings', function (Blueprint $table) {
            $table->id();
            $table->json('settings')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ui_theme_settings');
    }
};
