<?php

namespace Database\Seeders;

use App\Models\ShiftSetting;
use Illuminate\Database\Seeder;

class ShiftSettingSeeder extends Seeder
{
    public function run(): void
    {
        $shifts = [
            [
                'name' => 'IT Shift',
                'start_time' => '10:30:00',
                'end_time' => '19:30:00',
                'break_duration' => 60,
                'grace_minutes' => 5,
                'standard_hours' => 9.00,
                'ot_threshold_hours' => 9.00,
                'description' => 'Dev, Ops, Marketing — 10:30 AM to 7:30 PM IST',
            ],
            [
                'name' => 'UK Sales Shift',
                'start_time' => '13:00:00',
                'end_time' => '22:00:00',
                'break_duration' => 60,
                'grace_minutes' => 5,
                'standard_hours' => 9.00,
                'ot_threshold_hours' => 9.00,
                'description' => 'UK Sales / BD team — 1:00 PM to 10:00 PM IST',
            ],
        ];

        foreach ($shifts as $shift) {
            ShiftSetting::updateOrCreate(
                ['name' => $shift['name']],
                $shift
            );
        }
    }
}
