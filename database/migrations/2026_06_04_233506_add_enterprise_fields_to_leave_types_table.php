<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->unique()->after('name');
            $table->boolean('allow_paid_request')->default(true)->after('is_paid');
            $table->boolean('allow_unpaid_request')->default(true)->after('allow_paid_request');
            $table->boolean('allow_hr_override')->default(true)->after('allow_unpaid_request');
            $table->boolean('hr_remark_required')->default(true)->after('allow_hr_override');
            $table->boolean('is_sandwich_applicable')->default(false)->after('hr_remark_required');
            $table->boolean('allow_half_day')->default(true)->after('is_sandwich_applicable');
            $table->boolean('is_monthly_accrual')->default(false)->after('allow_half_day');
            $table->decimal('accrual_days_per_month', 5, 2)->default(0)->after('is_monthly_accrual');
            $table->enum('gender_restriction', ['none', 'male', 'female'])->default('none')->after('accrual_days_per_month');
            $table->boolean('probation_restricted')->default(false)->after('gender_restriction');
            $table->boolean('notice_period_restricted')->default(false)->after('probation_restricted');
            $table->unsignedTinyInteger('max_consecutive_days')->nullable()->after('notice_period_restricted');
            $table->boolean('attachment_required')->default(false)->after('max_consecutive_days');
        });

        // Expand category enum to include all standard leave type categories
        DB::statement("
            ALTER TABLE `leave_types`
            MODIFY `category` ENUM(
                'annual','sick','mdl','comp_off','encashment','unpaid','other',
                'unauthorized','bereavement','paternity','wfh','lwp','custom'
            ) NOT NULL DEFAULT 'other'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE `leave_types`
            MODIFY `category` ENUM(
                'annual','sick','mdl','comp_off','encashment','unpaid','other','unauthorized'
            ) NOT NULL DEFAULT 'other'
        ");

        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn([
                'code', 'allow_paid_request', 'allow_unpaid_request', 'allow_hr_override',
                'hr_remark_required', 'is_sandwich_applicable', 'allow_half_day',
                'is_monthly_accrual', 'accrual_days_per_month', 'gender_restriction',
                'probation_restricted', 'notice_period_restricted', 'max_consecutive_days',
                'attachment_required',
            ]);
        });
    }
};
