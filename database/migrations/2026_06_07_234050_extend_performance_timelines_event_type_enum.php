<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE `performance_timelines`
            MODIFY `event_type` ENUM(
                'kpi_assigned','review_started','self_review_submitted','manager_review_submitted',
                'review_completed','scorecard_locked','warning_issued','warning_acknowledged',
                'warning_escalated',
                'pip_created','pip_milestone','pip_outcome','pip_comment',
                'promotion_recommended','promotion_approved','salary_revised','bonus_granted',
                'transfer','award','recognition'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE `performance_timelines`
            MODIFY `event_type` ENUM(
                'kpi_assigned','review_started','self_review_submitted','manager_review_submitted',
                'review_completed','scorecard_locked','warning_issued','warning_acknowledged',
                'pip_created','pip_milestone','pip_outcome','pip_comment',
                'promotion_recommended','promotion_approved','salary_revised',
                'transfer','award','recognition'
            ) NOT NULL
        ");
    }
};
