<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // ── System config (always required) ───────────────────────────
            CompanySeeder::class,
            OfficeSeeder::class,
            DepartmentSeeder::class,
            JobTitleSeeder::class,
            ShiftSettingSeeder::class,

            // ── System admin accounts ─────────────────────────────────────
            UserSeeder::class,
            EmployeeSeeder::class,   // creates Employee records for 6 admin users only

            // ── App config / lookup data ──────────────────────────────────
            PublicHolidaySeeder::class,
            DecemberMandatoryDaySeeder::class,
            LeaveSeeder::class,
            LeaveTypeSeeder::class,

            // ── Payroll foundation (statutory rates, components, structures,
            //    cycles) — required before any payroll can be run ───────────
            StatutoryRuleSeeder::class,
            SalaryCycleSeeder::class,
            PayrollSeeder::class,
            SalaryStructureSeeder::class,

            // ── Real employee master (biometric codes are source of truth) ─
            BiometricEmployeeMasterSeeder::class,

            // ── Dynamic roles & permissions (database-driven authorization) ─
            RolesAndPermissionsSeeder::class,

            // ── Onboarding default template ───────────────────────────────
            OnboardingTemplateSeeder::class,
        ]);
    }
}
