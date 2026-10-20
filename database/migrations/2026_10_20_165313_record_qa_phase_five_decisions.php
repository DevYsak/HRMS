<?php

use Database\Seeders\QaAuditPhaseFiveDecisionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Record Phase 5 round 2 in the QA tracker: the approved decisions
 * (QA-F-079 / 080 / 081) with their fix commits and retests, the systemic UI
 * findings, and the eight-role demo journeys. QA records only; idempotent.
 */
return new class extends Migration
{
    /**
     * The role journeys: every sidebar link each of the eight roles sees, on
     * the throwaway e2e app. Pass 1 found 17 overflowing visits (5 pages),
     * fixed in 8105d54; pass 2 ran on the fixed build in the other themes.
     */
    private const JOURNEY_RUNS = [
        [
            'name' => '2026-10-20 Phase 5 role journeys — desktop light + phone dark, 8 roles', 'suite' => 'playwright', 'kind' => 'ui',
            'environment' => 'local', 'commit' => 'de4e16c', 'total' => 774, 'passed' => 757, 'failed' => 17, 'skipped' => 0, 'known_baseline' => 0,
            'duration' => 2626, 'run_at' => '2026-10-20 15:41:40',
            'notes' => 'Super Admin, HR Admin, Director, Department Head, Manager, Coordinator, Finance and Employee — every sidebar link at 1440px light and 375px dark (report downloads excluded). Every link opened (no 403 / 404 / 500) and no console errors. 17 visits failed on horizontal overflow at 375px: Biometric Summary, Documents, Org Chart, KPI Dashboard, KPI Templates — fixed in 8105d54 and re-measured at 0.',
        ],
        [
            'name' => '2026-10-20 Phase 5 role journeys — desktop dark + phone light', 'suite' => 'playwright', 'kind' => 'ui',
            'environment' => 'local', 'commit' => '8105d54', 'total' => 304, 'passed' => 304, 'failed' => 0, 'skipped' => 0, 'known_baseline' => 0,
            'duration' => 910, 'run_at' => '2026-10-20 16:37:15',
            'notes' => 'Super Admin, Employee and Manager (together every page) at 1440px dark and 375px light after the overflow fixes: every link 200, no overflow, no console errors. Dark contact sheet reviewed: one #0B1220 canvas, no light patches.',
        ],
    ];

    public function up(): void
    {
        $seeder = new QaAuditPhaseFiveDecisionsSeeder;
        foreach (self::JOURNEY_RUNS as $run) {
            $seeder->run($run);
        }
    }

    public function down(): void
    {
        // QA history is never deleted.
    }
};
