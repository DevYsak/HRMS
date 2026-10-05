<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR-confirmed Conexus rule: Saturday and Sunday are the weekly off.
 *
 * Only the old implicit default changes — an empty value (which fell back to
 * Sunday only) or exactly [Sunday]. Any other weekly off HR configured on
 * purpose is left as it is. No attendance, leave or payroll row is touched.
 */
return new class extends Migration
{
    private const SATURDAY_AND_SUNDAY = [6, 0];

    public function up(): void
    {
        foreach (DB::table('attendance_settings')->get(['id', 'weekly_off_days']) as $row) {
            $days = is_string($row->weekly_off_days) ? json_decode($row->weekly_off_days, true) : $row->weekly_off_days;
            $days = is_array($days) ? array_values(array_unique(array_map('intval', $days))) : [];

            if ($days === [] || $days === [0]) {
                DB::table('attendance_settings')->where('id', $row->id)
                    ->update(['weekly_off_days' => json_encode(self::SATURDAY_AND_SUNDAY), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Back to Sunday only, stated explicitly (the model's fallback is now Sat + Sun).
        DB::table('attendance_settings')
            ->where('weekly_off_days', json_encode(self::SATURDAY_AND_SUNDAY))
            ->update(['weekly_off_days' => json_encode([0]), 'updated_at' => now()]);
    }
};
