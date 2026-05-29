<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            CompanySeeder::class,
            OfficeSeeder::class,
            DepartmentSeeder::class,
            JobTitleSeeder::class,
            ShiftSettingSeeder::class,
            UserSeeder::class,
            EmployeeSeeder::class,

            // App feature seeders
            PublicHolidaySeeder::class,
            DecemberMandatoryDaySeeder::class,
            LeaveSeeder::class,
            AttendanceSeeder::class,
            PayrollSeeder::class,

            // Optional demo data
            DemoEmployeeSeeder::class,
            RunPayrollDemoSeeder::class,
        ]);
    }
}
