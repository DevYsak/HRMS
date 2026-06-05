<?php

namespace Database\Seeders;

use App\Models\SalaryCycle;
use Illuminate\Database\Seeder;

class SalaryCycleSeeder extends Seeder
{
    public function run(): void
    {
        $cycles = [
            ['name' => 'Cycle A', 'slug' => 'cycle-a', 'start_day' => 1,  'end_day' => 0,  'pay_day' => 5,  'is_default' => true,  'is_active' => true],
            ['name' => 'Cycle B', 'slug' => 'cycle-b', 'start_day' => 21, 'end_day' => 20, 'pay_day' => 25, 'is_default' => false, 'is_active' => true],
        ];

        foreach ($cycles as $data) {
            SalaryCycle::updateOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
