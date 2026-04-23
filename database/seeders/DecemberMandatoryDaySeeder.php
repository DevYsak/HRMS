<?php

namespace Database\Seeders;

use App\Models\DecemberMandatoryDay;
use Illuminate\Database\Seeder;

class DecemberMandatoryDaySeeder extends Seeder
{
    public function run(): void
    {
        // 6 mandatory December leave days per spec (26th–31st Dec)
        $years = [2025, 2026, 2027];

        foreach ($years as $year) {
            $dates = [
                "{$year}-12-26" => 'December Mandatory Leave - Day 1',
                "{$year}-12-27" => 'December Mandatory Leave - Day 2',
                "{$year}-12-28" => 'December Mandatory Leave - Day 3',
                "{$year}-12-29" => 'December Mandatory Leave - Day 4',
                "{$year}-12-30" => 'December Mandatory Leave - Day 5',
                "{$year}-12-31" => 'December Mandatory Leave - Day 6',
            ];

            foreach ($dates as $date => $description) {
                DecemberMandatoryDay::updateOrCreate(
                    ['year' => $year, 'date' => $date],
                    ['description' => $description]
                );
            }
        }

        $this->command->info('December Mandatory Days seeded for 2025–2027.');
    }
}
