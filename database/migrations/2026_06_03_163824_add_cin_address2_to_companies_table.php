<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('address_line2')->nullable()->after('address');
            $table->string('cin')->nullable()->after('address_line2')
                ->comment('Company Identification Number for statutory documents');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['address_line2', 'cin']);
        });
    }
};
