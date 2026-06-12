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
use App\Models\Permission;
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
use Illuminate\Notifications\DatabaseNotification;
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
        // Allow up to 120s for dashboard/payroll renders in non-production.
        // Skip in console: CLI ignores this ini setting normally, but explicitly
        // setting it here causes `php artisan test` to hit a cumulative 120s
        // limit across the whole run and crash with a fatal error.
        if (! app()->isProduction() && ! $this->app->runningInConsole()) {
            ini_set('max_execution_time', '120');
        }

        $this->configureDefaults();
        $this->registerObservers();
        $this->configureNotificationPriority();

        if (app()->environment('production')) {
            URL::forceScheme('https');
        }

        Gate::define('manageFullSettings', function (User $user) {
            return $user->isSuperAdmin() || $user->isHrAdmin();
        });

        Gate::define('manage-settings', function (User $user) {
            return $user->canManageSettings();
        });

        // Dynamic, database-driven authorization: any ability matching a
        // `permissions.key` row resolves through the user's assigned role.
        Gate::before(function (User $user, string $ability) {
            if (Permission::query()->where('key', $ability)->exists()) {
                return $user->hasPermission($ability);
            }

            return null;
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

    /**
     * Populate the notifications.priority column from each notification's
     * data['priority'] payload (high|normal|low) at write time, so the
     * column can be filtered and sorted without parsing the data JSON.
     */
    protected function configureNotificationPriority(): void
    {
        DatabaseNotification::creating(function (DatabaseNotification $notification): void {
            $data = $notification->data;
            $priority = (is_array($data) || $data instanceof \ArrayAccess)
                ? ($data['priority'] ?? null)
                : null;

            $notification->priority = in_array($priority, ['high', 'normal', 'low'], true)
                ? $priority
                : 'normal';
        });
    }
}
