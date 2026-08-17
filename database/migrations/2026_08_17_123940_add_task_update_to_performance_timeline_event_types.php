<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds 'task_update' to performance_timelines.event_type.
 *
 * OnboardingChecklist has always written a timeline entry with this event type
 * when a task is ticked, but the enum never contained it — so every completion
 * threw "Data truncated for column 'event_type'". The task row updates first,
 * so the tick landed and then the request blew up: HR saw an error on a change
 * that had in fact been saved. It is a plausible reason live onboarding sits at
 * 2 of 91 tasks complete.
 *
 * Adding an ENUM value is additive: existing rows keep their values and no data
 * is rewritten.
 */
return new class extends Migration
{
    private const VALUES = [
        'kpi_assigned', 'review_started', 'self_review_submitted', 'manager_review_submitted',
        'review_completed', 'scorecard_locked', 'warning_issued', 'warning_acknowledged',
        'warning_escalated', 'pip_created', 'pip_milestone', 'pip_outcome', 'pip_comment',
        'promotion_recommended', 'promotion_approved', 'salary_revised', 'bonus_granted',
        'transfer', 'award', 'recognition', 'ot_detected', 'ot_approved', 'ot_rejected',
        'ot_auto_approved', 'ot_payroll_processed',
    ];

    public function up(): void
    {
        $this->setEnum([...self::VALUES, 'task_update']);
    }

    /**
     * Reversible only while no row uses the new value; MySQL would silently
     * blank those rows otherwise, so they are moved to the closest existing
     * value first rather than being destroyed.
     */
    public function down(): void
    {
        DB::table('performance_timelines')->where('event_type', 'task_update')->update(['event_type' => 'recognition']);

        $this->setEnum(self::VALUES);
    }

    /** @param  array<int, string>  $values */
    private function setEnum(array $values): void
    {
        $list = implode(',', array_map(fn (string $v) => "'".$v."'", $values));

        DB::statement("ALTER TABLE `performance_timelines` MODIFY `event_type` ENUM({$list}) NOT NULL");
    }
};
