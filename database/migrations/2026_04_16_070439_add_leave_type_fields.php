<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            // Category: annual, sick, mdl, comp_off, encashment, unpaid
            $table->enum('category', ['annual', 'sick', 'mdl', 'comp_off', 'encashment', 'unpaid', 'other'])
                ->default('other')
                ->after('color');
            $table->boolean('allow_carry_forward')->default(false)->after('category');
            $table->unsignedSmallInteger('carry_forward_limit')->default(0)->after('allow_carry_forward');
            $table->boolean('allow_encashment')->default(false)->after('carry_forward_limit');
        });

        Schema::table('leave_balances', function (Blueprint $table) {
            $table->decimal('carried_forward_days', 8, 2)->default(0)->after('used_days');
            $table->decimal('encashed_days', 8, 2)->default(0)->after('carried_forward_days');
            $table->unsignedSmallInteger('year')->default(now()->year)->after('encashed_days');
        });
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn(['category', 'allow_carry_forward', 'carry_forward_limit', 'allow_encashment']);
        });
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->dropColumn(['carried_forward_days', 'encashed_days', 'year']);
        });
    }
};
