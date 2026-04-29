<?php

namespace Database\Seeders;

use App\Models\AttendanceSetting;
use App\Models\Office;
use App\Models\ShiftSetting;
use Illuminate\Database\Seeder;

class AttendanceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Update Headquarter with location for Geo-fencing tests
        $hq = Office::where('is_headquarters', true)->first();
        if ($hq) {
            $hq->update([
                'latitude' => 40.7580, // Times Square, NY (for testing)
                'longitude' => -73.9855,
                'radius' => 500, // 500 meters
            ]);
        }

        // 2. Create Global Attendance Settings (fallback for employees with no shift)
        AttendanceSetting::firstOrCreate([], [
            'shift_start' => '10:30:00',
            'shift_end' => '19:30:00',
            'late_grace_period' => 5,
            'requires_location' => true,
            'requires_qr' => false,
        ]);

        // 3. Seed canonical shift definitions per spec §3.1
        // IT Shift — Indian office standard (10:30 AM – 7:30 PM IST)
        ShiftSetting::updateOrCreate(
            ['name' => 'IT Shift'],
            [
                'start_time' => '10:30:00',
                'end_time' => '19:30:00',
                'break_duration' => 60,
                'grace_minutes' => 5,
                'standard_hours' => 9.00,
                'ot_threshold_hours' => 9.00,
                'description' => 'Standard IT department shift: 10:30 AM – 7:30 PM IST',
            ]
        );

        // UK Sales Shift — aligned to UK business hours (1:00 PM – 10:00 PM IST)
        ShiftSetting::updateOrCreate(
            ['name' => 'UK Sales Shift'],
            [
                'start_time' => '13:00:00',
                'end_time' => '22:00:00',
                'break_duration' => 60,
                'grace_minutes' => 5,
                'standard_hours' => 9.00,
                'ot_threshold_hours' => 9.00,
                'description' => 'UK sales team shift: 1:00 PM – 10:00 PM IST',
            ]
        );
    }
}
