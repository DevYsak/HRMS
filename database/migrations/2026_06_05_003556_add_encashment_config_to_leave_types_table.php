<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            // Max days an employee may encash per year (null = unlimited)
            $table->unsignedSmallInteger('max_encashable_days')->nullable()->after('allow_encashment');
            // Multiplier on daily rate for encashment payout (e.g. 1.5 for enhanced rate)
            $table->decimal('encashment_rate_multiplier', 4, 2)->default(1.00)->after('max_encashable_days');
            // Whether current-year (not just carry-forward) leaves may be encashed
            $table->boolean('allow_current_year_encashment')->default(false)->after('encashment_rate_multiplier');
        });
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn(['max_encashable_days', 'encashment_rate_multiplier', 'allow_current_year_encashment']);
        });
    }
};
