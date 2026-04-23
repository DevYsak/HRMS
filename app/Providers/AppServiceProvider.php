<?php

namespace App\Providers;

use App\Models\Employee;
use App\Models\Payslip;
use App\Models\User;
use App\Observers\EmployeeObserver;
use App\Observers\PayslipObserver;
use App\Observers\UserObserver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
        $this->configureDefaults();
        $this->registerObservers();

        \Illuminate\Support\Facades\Gate::define('manageFullSettings', function (User $user) {
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
        Payslip::observe(PayslipObserver::class);
        User::observe(UserObserver::class);
    }
}
