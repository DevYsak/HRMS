<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who closed a leave year, and when. is_closed already existed but nothing
 * set or read it; closing is now an explicit, guarded step after which the
 * year's ledger accepts no ordinary postings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_years', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('is_closed');
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leave_years', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn('closed_at');
        });
    }
};
