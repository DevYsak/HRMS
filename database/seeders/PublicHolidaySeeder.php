<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PublicHolidaySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ensure the table exists before attempting operations
        if (! Schema::hasTable('public_holidays')) {
            $this->command?->info('Table public_holidays not found. Skipping PublicHolidaySeeder.');

            return;
        }

        $hasYear = Schema::hasColumn('public_holidays', 'year');
        $hasCountry = Schema::hasColumn('public_holidays', 'country');

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

            // --- United Kingdom 2025 ---
            ['date' => '2025-01-01', 'name' => "New Year's Day",              'country' => 'UK'],
            ['date' => '2025-04-18', 'name' => 'Good Friday',                 'country' => 'UK'],
            ['date' => '2025-04-21', 'name' => 'Easter Monday',               'country' => 'UK'],
            ['date' => '2025-05-05', 'name' => 'Early May Bank Holiday',      'country' => 'UK'],
            ['date' => '2025-05-26', 'name' => 'Spring Bank Holiday',         'country' => 'UK'],
            ['date' => '2025-08-25', 'name' => 'Summer Bank Holiday',         'country' => 'UK'],
            ['date' => '2025-12-25', 'name' => 'Christmas Day',               'country' => 'UK'],
            ['date' => '2025-12-26', 'name' => 'Boxing Day',                  'country' => 'UK'],
        ];

        DB::transaction(function () use ($holidays, $hasYear, $hasCountry) {
            foreach ($holidays as $h) {
                $data = ['name' => $h['name']];

                if ($hasYear) {
                    $data['year'] = (int) substr($h['date'], 0, 4);
                }

                if ($hasCountry) {
                    $data['country'] = $h['country'];
                }

                DB::table('public_holidays')->updateOrInsert(
                    ['date' => $h['date'], 'country' => $h['country'] ?? 'UK'],
                    $data
                );
            }
        });

        $this->command?->info('Public holidays seeded (India + UK 2025-2026).');
    }
}
