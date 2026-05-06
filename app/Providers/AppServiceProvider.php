<?php

namespace App\Providers;

use App\Models\AttendanceRegularisation;
use App\Models\DocumentAcknowledgement;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\Incentive;
use App\Models\LeaveRequest;
use App\Models\OtRequest;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\Reimbursement;
use App\Models\User;
use App\Observers\AttendanceRegularisationObserver;
use App\Observers\DocumentAcknowledgementObserver;
use App\Observers\EmployeeObserver;
use App\Observers\EmployeeSalaryObserver;
use App\Observers\IncentiveObserver;
use App\Observers\LeaveRequestObserver;
use App\Observers\OtRequestObserver;
use App\Observers\PayrollObserver;
use App\Observers\PayslipObserver;
use App\Observers\ReimbursementObserver;
use App\Observers\UserObserver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Allow up to 120s for dashboard/payroll renders in non-production
        if (! app()->isProduction()) {
            ini_set('max_execution_time', '120');
        }

        $this->configureDefaults();
        $this->registerObservers();

        if (app()->environment('production')) {
            URL::forceScheme('https');
        }

        Gate::define('manageFullSettings', function (User $user) {
            return $user->isSuperAdmin() || $user->isHrAdmin();
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Register Eloquent model observers for audit log compliance.
     */
    protected function registerObservers(): void
    {
        Employee::observe(EmployeeObserver::class);
        EmployeeSalary::observe(EmployeeSalaryObserver::class);
        User::observe(UserObserver::class);
        LeaveRequest::observe(LeaveRequestObserver::class);
        OtRequest::observe(OtRequestObserver::class);
        Payslip::observe(PayslipObserver::class);
        Payroll::observe(PayrollObserver::class);
        Incentive::observe(IncentiveObserver::class);
        Reimbursement::observe(ReimbursementObserver::class);
        AttendanceRegularisation::observe(AttendanceRegularisationObserver::class);
        DocumentAcknowledgement::observe(DocumentAcknowledgementObserver::class);
    }
}
