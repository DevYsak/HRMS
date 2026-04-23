<?php

namespace Database\Seeders;

use App\Models\PublicHoliday;
use Illuminate\Database\Seeder;

class PublicHolidaySeeder extends Seeder
{
    public function run(): void
    {
        $holidays = [
            // --- India 2026 ---
            ['date' => '2026-01-26', 'name' => 'Republic Day',                'country' => 'IN'],
            ['date' => '2026-03-25', 'name' => 'Holi',                        'country' => 'IN'],
            ['date' => '2026-04-14', 'name' => 'Dr. Ambedkar Jayanti',        'country' => 'IN'],
            ['date' => '2026-04-10', 'name' => 'Good Friday',                 'country' => 'IN'],
            ['date' => '2026-08-15', 'name' => 'Independence Day',            'country' => 'IN'],
            ['date' => '2026-10-02', 'name' => 'Gandhi Jayanti',              'country' => 'IN'],
            ['date' => '2026-10-20', 'name' => 'Dussehra',                    'country' => 'IN'],
            ['date' => '2026-11-09', 'name' => 'Diwali',                      'country' => 'IN'],
            ['date' => '2026-11-10', 'name' => 'Diwali (2nd day)',            'country' => 'IN'],
            ['date' => '2026-11-15', 'name' => 'Guru Nanak Jayanti',          'country' => 'IN'],
            ['date' => '2026-12-25', 'name' => 'Christmas Day',               'country' => 'IN'],

            // --- United Kingdom 2026 ---
            ['date' => '2026-01-01', 'name' => "New Year's Day",              'country' => 'UK'],
            ['date' => '2026-04-03', 'name' => 'Good Friday',                 'country' => 'UK'],
            ['date' => '2026-04-06', 'name' => 'Easter Monday',               'country' => 'UK'],
            ['date' => '2026-05-04', 'name' => 'Early May Bank Holiday',      'country' => 'UK'],
            ['date' => '2026-05-25', 'name' => 'Spring Bank Holiday',         'country' => 'UK'],
            ['date' => '2026-08-31', 'name' => 'Summer Bank Holiday',         'country' => 'UK'],
            ['date' => '2026-12-25', 'name' => 'Christmas Day',               'country' => 'UK'],
            ['date' => '2026-12-28', 'name' => 'Boxing Day (substitute)',      'country' => 'UK'],
        ];

        foreach ($holidays as $holiday) {
            PublicHoliday::updateOrCreate(
                ['date' => $holiday['date'], 'country' => $holiday['country']],
                ['name' => $holiday['name']]
            );
        }

        $this->command->info('Public holidays seeded (India + UK 2026).');
    }
}
