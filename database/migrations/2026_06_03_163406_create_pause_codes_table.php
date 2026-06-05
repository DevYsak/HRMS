<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pause_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed default pause codes
        DB::table('pause_codes')->insert([
            ['code' => 'BREAK',    'label' => 'Regular Break',      'description' => 'Scheduled tea/coffee break',         'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'LUNCH',    'label' => 'Lunch Break',         'description' => 'Midday meal break',                  'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'TRAINING', 'label' => 'Training',            'description' => 'Internal or external training session', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'MEETING',  'label' => 'Client/Team Meeting', 'description' => 'Formal meeting away from workstation', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'PERSONAL', 'label' => 'Personal',            'description' => 'Personal errand or appointment',     'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'SYSTEM',   'label' => 'System Downtime',     'description' => 'Waiting due to technical issues',    'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('pause_codes');
    }
};
