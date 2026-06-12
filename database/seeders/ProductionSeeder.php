<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Production-safe seeder — uses updateOrCreate throughout, never truncates.
 * Run on any environment: php artisan db:seed --class=ProductionSeeder --force
 *
 * Order matters:
 *   1. Company           → base record
 *   2. Departments       → needed by EmployeeSeeder
 *   3. ShiftSettings     → needed by EmployeeSeeder
 *   4. SalaryCycles      → needed by EmployeeSeeder
 *   5. Users             → core Conexus staff (spec §1.4)
 *   6. Employees         → links users to departments/shifts/cycles
 *   7. LeaveTypes        → CSL / MDL / Comp-Off (spec §3.3)
 *   8. PublicHolidays    → UK calendar
 *   9. DecemberMandatoryDays → MDL shutdown dates
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CompanySeeder::class,
            DepartmentSeeder::class,
            ShiftSettingSeeder::class,
            SalaryCycleSeeder::class,
            UserSeeder::class,
            EmployeeSeeder::class,
            LeaveSeeder::class,
            PublicHolidaySeeder::class,
            DecemberMandatoryDaySeeder::class,
        ]);
    }
}
