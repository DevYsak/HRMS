<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\Role;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceDayRebuilder;
use App\Services\Leave\ConexusLeavePolicyService;
use App\Services\Leave\LeaveRegisterReconciliationService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DecemberMandatoryDaySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\EmploymentTypeSeeder;
use Database\Seeders\JobTitleSeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\OfficeSeeder;
use Database\Seeders\PublicHolidaySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SalaryCycleSeeder;
use Database\Seeders\SalaryStructureSeeder;
use Database\Seeders\ShiftSettingSeeder;
use Database\Seeders\StatutoryRuleSeeder;
use Database\Seeders\WorkModeSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the fictional accounts the Playwright role journeys log in as
 * (tests/Playwright/e2e). Every name and value is invented.
 *
 * Two departments (E2E Alpha, E2E Beta), one account per role, a reporting
 * line (Manager → Employee A), a Department Head of Alpha, a CSL balance for
 * Employee A, and yesterday's open check-in for the missing-checkout journey.
 *
 * Guarded twice: only in local/testing, and only against a database whose name
 * ends in "_e2e" — it can never touch a real or development database.
 */
#[Signature('e2e:seed-roles {--password=E2eDemo#2026 : Password for every E2E account} {--remove : Delete the E2E accounts and departments} {--with-lookups : First seed the lookup data (roles, leave types, shifts, holidays) into an empty E2E database} {--manifest= : Write the accounts and ids as JSON to this path}')]
#[Description('Seed the fictional role accounts for the Playwright E2E journeys (local/testing, *_e2e database only)')]
class E2eSeedRoles extends Command
{
    /** role slug => [email local part, display name, department key] */
    public const ACCOUNTS = [
        'super_admin' => ['e2e.superadmin', 'Sam Superadmin', 'alpha'],
        'hr_admin' => ['e2e.hr', 'Hana Hradmin', 'alpha'],
        'department_head' => ['e2e.depthead', 'Dev Depthead', 'alpha'],
        'manager' => ['e2e.manager', 'Mona Manager', 'alpha'],
        'finance' => ['e2e.finance', 'Fin Finance', 'alpha'],
        'employee' => ['e2e.employee', 'Ari Alpha', 'alpha'],
    ];

    /** An employee in the other department, for isolation checks. */
    public const OTHER_EMPLOYEE = ['e2e.beta', 'Bea Beta', 'beta'];

    /**
     * Lookup data a fresh E2E database needs — never the seeders that load
     * real people (UserSeeder, EmployeeSeeder, BiometricEmployeeMasterSeeder)
     * or demo records.
     */
    public const LOOKUP_SEEDERS = [
        CompanySeeder::class, OfficeSeeder::class, DepartmentSeeder::class, JobTitleSeeder::class,
        ShiftSettingSeeder::class, WorkModeSeeder::class, EmploymentTypeSeeder::class,
        PublicHolidaySeeder::class, DecemberMandatoryDaySeeder::class, LeaveTypeSeeder::class,
        StatutoryRuleSeeder::class, SalaryCycleSeeder::class, SalaryStructureSeeder::class,
        RolesAndPermissionsSeeder::class,
    ];

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Refused: E2E accounts are created only in a local or testing environment.');

            return self::FAILURE;
        }

        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (! str_ends_with($database, '_e2e')) {
            $this->error("Refused: database '{$database}' is not an E2E database (its name must end in _e2e).");

            return self::FAILURE;
        }

        config(['mail.default' => 'array', 'queue.default' => 'sync']);

        $this->remove();

        if ($this->option('remove')) {
            $this->info('E2E accounts removed.');

            return self::SUCCESS;
        }

        if ($this->option('with-lookups')) {
            foreach (self::LOOKUP_SEEDERS as $seeder) {
                $this->callSilently('db:seed', ['--class' => $seeder, '--force' => true]);
            }
        }

        $users = DB::transaction(fn () => $this->seed((string) $this->option('password')));

        if ($manifest = $this->option('manifest')) {
            $this->writeManifest((string) $manifest, $users);
        }

        $this->info('E2E accounts ready ('.count(self::ACCOUNTS) + 1 .' users, password: '.$this->option('password').').');

        return self::SUCCESS;
    }

    /** @return array<string, User> */
    private function seed(string $password): array
    {
        $company = Company::query()->first() ?? Company::factory()->create();
        $alpha = Department::create(['company_id' => $company->id, 'name' => 'E2E Alpha', 'code' => 'E2EA']);
        $beta = Department::create(['company_id' => $company->id, 'name' => 'E2E Beta', 'code' => 'E2EB']);
        $departments = ['alpha' => $alpha, 'beta' => $beta];
        $shift = ShiftSetting::where('is_default', true)->first() ?? ShiftSetting::first();

        LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);

        // The policy turns the existing "Paid Leave" type into the CSL and
        // never creates one; a fresh E2E database has none, so add it first.
        if (! LeaveType::withTrashed()->where('code', ConexusLeavePolicyService::CSL_CODE)->orWhere('name', 'Paid Leave')->exists()) {
            LeaveType::create([
                'name' => 'Paid Leave', 'category' => 'annual', 'is_paid' => true, 'allow_paid_request' => true, 'color' => '#F97316',
                'code' => LeaveType::withTrashed()->where('code', ConexusLeavePolicyService::PAID_LEAVE_CODE)->exists() ? 'PDL' : ConexusLeavePolicyService::PAID_LEAVE_CODE,
            ]);
        }
        app(ConexusLeavePolicyService::class)->apply();

        $make = function (string $slug, array $account) use ($password, $departments, $shift): User {
            [$local, $name, $department] = $account;
            $role = Role::where('slug', $slug)->firstOrFail();
            $user = User::create([
                'name' => $name, 'email' => "{$local}@example.com", 'password' => $password,
                'role' => $role->legacyBucket(), 'role_id' => $role->id, 'password_changed_at' => now(),
            ]);
            $user->forceFill(['must_change_password' => false, 'email_verified_at' => now()])->save();

            Employee::factory()->create([
                'user_id' => $user->id, 'employee_id' => strtoupper(str_replace('.', '-', $local)),
                'department_id' => $departments[$department]->id, 'shift_id' => $shift?->id,
                'status' => 'active', 'manager_id' => null, 'joining_date' => '2024-01-08', 'holiday_calendar' => 'UK',
            ]);

            return $user->fresh();
        };

        $users = [];
        foreach (self::ACCOUNTS as $slug => $account) {
            $users[$slug] = $make($slug, $account);
        }
        $users['beta'] = $make('employee', self::OTHER_EMPLOYEE);

        $alpha->update(['head_id' => $users['department_head']->id]);
        $users['employee']->employee->update(['manager_id' => $users['manager']->id]);

        // Employee A: a CSL balance to apply against.
        app(LeaveRegisterReconciliationService::class)->reconcileEmployee(
            $users['employee']->employee->fresh(),
            ['name' => 'E2E', 'emails' => [$users['employee']->email], 'credit' => 4, 'carry' => 6, 'used' => 0, 'encashed' => 0, 'available' => 10],
            LeaveYear::where('label', '2026/27')->first(),
            LeaveType::where('code', 'CSL')->firstOrFail(),
            LeaveType::withTrashed()->where('code', 'AL')->first(),
            null,
        );

        // Yesterday's check-in with no check-out: the missing-checkout journey.
        $yesterday = Carbon::yesterday();
        Attendance::create([
            'employee_id' => $users['employee']->employee->id, 'date' => $yesterday->toDateString(),
            'check_in' => $yesterday->copy()->setTime(10, 25), 'status' => 'on_time', 'total_hours' => 0,
        ]);

        $this->seedPunchHistory($users['manager']->employee);

        return $users;
    }

    /**
     * Two weeks of biometric punches for the manager (Face = IN, ID card =
     * OUT, a lunch break), one late day with no check-out, and a live session
     * today — each day built by the canonical AttendanceDayRebuilder, so My
     * Attendance shows a realistic Today, history and trend.
     */
    private function seedPunchHistory(Employee $employee): void
    {
        $rebuilder = app(AttendanceDayRebuilder::class);
        $punch = function (Carbon $day, string $time, string $method) use ($employee): void {
            $at = $day->copy()->setTimeFromTimeString($time);
            AttendancePunch::create([
                'employee_id' => $employee->id, 'punched_at' => $at, 'punch_date' => $at->toDateString(),
                'method' => $method, 'source' => 'biometric', 'device_serial' => 'E2E-GATE-1', 'location' => 'Main gate',
            ]);
        };

        $day = Carbon::today()->subDays(14);
        $working = 0;
        while ($day->lt(Carbon::today())) {
            if (! $day->isWeekend()) {
                $working++;
                $late = $working % 5 === 3;
                $punch($day, $late ? '10:52' : sprintf('10:%02d', 18 + $working % 9), 'face');
                $punch($day, '13:31', 'id_card');
                $punch($day, '14:04', 'face');
                // The fourth working day's check-out never happened.
                if ($working !== 4) {
                    $punch($day, sprintf('19:%02d', 30 + $working % 12), 'id_card');
                }
                $rebuilder->rebuild($employee, $day);
            }
            $day = $day->copy()->addDay();
        }

        // Today: in, out for a break, back in — still working.
        $today = Carbon::today();
        $punch($today, '10:25', 'face');
        $punch($today, '11:15', 'id_card');
        $punch($today, '11:51', 'face');
        $rebuilder->rebuild($employee, $today);
    }

    /** @param array<string, User> $users */
    private function writeManifest(string $path, array $users): void
    {
        $accounts = collect($users)->map(fn (User $user) => [
            'email' => $user->email,
            'name' => $user->name,
            'user_id' => $user->id,
            'employee_id' => $user->employee?->id,
            'department_id' => $user->employee?->department_id,
        ]);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, json_encode([
            'password' => (string) $this->option('password'),
            'accounts' => $accounts,
            'departments' => Department::whereIn('code', ['E2EA', 'E2EB'])->pluck('id', 'code'),
        ], JSON_PRETTY_PRINT));
    }

    private function remove(): void
    {
        $emails = collect([...array_column(self::ACCOUNTS, 0), self::OTHER_EMPLOYEE[0]])->map(fn ($l) => "{$l}@example.com");
        $users = User::withTrashed()->whereIn('email', $emails)->get();

        foreach ($users as $user) {
            $employee = Employee::withTrashed()->where('user_id', $user->id)->first();
            if ($employee) {
                AttendancePunch::where('employee_id', $employee->id)->delete();
            }
            $employee?->forceDelete();
            DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $user->id)->delete();
            $user->forceDelete();
        }

        Department::whereIn('code', ['E2EA', 'E2EB'])->get()->each->delete();
    }
}
