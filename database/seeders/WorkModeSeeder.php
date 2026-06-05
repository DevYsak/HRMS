<?php

namespace Database\Seeders;

use App\Models\WorkMode;
use Illuminate\Database\Seeder;

class WorkModeSeeder extends Seeder
{
    public function run(): void
    {
        $modes = [
            ['name' => 'Office',  'slug' => 'office',  'color' => '#1DB77A', 'requires_attendance_tracking' => true,  'sort_order' => 1],
            ['name' => 'Remote',  'slug' => 'remote',  'color' => '#3B82F6', 'requires_attendance_tracking' => false, 'sort_order' => 2],
            ['name' => 'Hybrid',  'slug' => 'hybrid',  'color' => '#F59E0B', 'requires_attendance_tracking' => true,  'sort_order' => 3],
            ['name' => 'Field',   'slug' => 'field',   'color' => '#8B5CF6', 'requires_attendance_tracking' => false, 'sort_order' => 4],
        ];

        foreach ($modes as $data) {
            WorkMode::updateOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
