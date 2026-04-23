<?php

namespace Database\Seeders;

use App\Models\AttendanceSetting;
use App\Models\Office;
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

        // 2. Create Global Attendance Settings
        AttendanceSetting::firstOrCreate([], [
            'shift_start' => '09:00:00',
            'shift_end' => '18:00:00',
            'late_grace_period' => 15, // 15 mins
            'requires_location' => true,
            'requires_qr' => false,
        ]);
    }
}
