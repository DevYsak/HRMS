<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL requires a full column redefinition to expand an ENUM.
        DB::statement("
            ALTER TABLE `performance_timelines`
            MODIFY `event_type` ENUM(
                'kpi_assigned','review_started','self_review_submitted','manager_review_submitted',
                'review_completed','scorecard_locked','warning_issued','warning_acknowledged',
                'pip_created','pip_milestone','pip_outcome','pip_comment',
                'promotion_recommended','promotion_approved','salary_revised','transfer','award','recognition'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        // Revert to original enum — rows with 'pip_comment' would be lost; safe in dev.
        DB::statement("
            ALTER TABLE `performance_timelines`
            MODIFY `event_type` ENUM(
                'kpi_assigned','review_started','self_review_submitted','manager_review_submitted',
                'review_completed','scorecard_locked','warning_issued','warning_acknowledged',
                'pip_created','pip_milestone','pip_outcome',
                'promotion_recommended','promotion_approved','salary_revised','transfer','award','recognition'
            ) NOT NULL
        ");
    }
};
