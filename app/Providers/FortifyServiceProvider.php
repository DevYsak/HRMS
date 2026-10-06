<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\RegisterResponse;
use App\Http\Responses\TwoFactorLoginResponse;
use App\Models\Employee;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\EmployeeInvitationService;
use App\Services\PasswordService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, TwoFactorLoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->recordLogins();
        $this->auditAuthentication();
    }

    /**
     * Sign-in, sign-out, failed attempts, lockouts and password resets in the
     * audit trail. Web guard only, so API/biometric guards add no noise. The
     * Login event fires before the guard holds the user, so the actor is
     * passed explicitly.
     */
    private function auditAuthentication(): void
    {
        $audit = fn () => app(AuditService::class);
        $employeeOf = fn (?User $user): ?int => $user ? Employee::where('user_id', $user->id)->value('id') : null;

        Event::listen(Login::class, function (Login $event) use ($audit, $employeeOf): void {
            if ($event->guard === 'web' && $event->user instanceof User) {
                $audit()->event('LOGIN', AuditService::AUTHENTICATION, $event->user,
                    new: ['remember' => $event->remember], subjectEmployeeId: $employeeOf($event->user), actor: $event->user);
            }
        });

        Event::listen(Logout::class, function (Logout $event) use ($audit, $employeeOf): void {
            if ($event->guard === 'web' && $event->user instanceof User) {
                $audit()->event('LOGOUT', AuditService::AUTHENTICATION, $event->user,
                    subjectEmployeeId: $employeeOf($event->user), actor: $event->user);
            }
        });

        Event::listen(Failed::class, function (Failed $event) use ($audit, $employeeOf): void {
            if ($event->guard !== 'web') {
                return;
            }

            $user = $event->user instanceof User ? $event->user : null;
            $audit()->event('LOGIN_FAILED', AuditService::SECURITY, $user ?? new User,
                new: ['email' => Str::limit((string) ($event->credentials['email'] ?? ''), 120, '')],
                subjectEmployeeId: $employeeOf($user), module: AuditService::AUTHENTICATION);
        });

        Event::listen(Lockout::class, function (Lockout $event) use ($audit, $employeeOf): void {
            $email = (string) $event->request->input(Fortify::username(), '');
            $user = $email !== '' ? User::where('email', $email)->first() : null;
            $audit()->event('LOGIN_LOCKOUT', AuditService::SECURITY, $user ?? new User,
                new: ['email' => Str::limit($email, 120, '')],
                subjectEmployeeId: $employeeOf($user), module: AuditService::AUTHENTICATION);
        });

        Event::listen(PasswordReset::class, function (PasswordReset $event) use ($audit, $employeeOf): void {
            if ($event->user instanceof User) {
                $audit()->event('PASSWORD_RESET_BY_LINK', AuditService::SECURITY, $event->user,
                    subjectEmployeeId: $employeeOf($event->user), module: AuditService::AUTHENTICATION, actor: $event->user);
            }
        });
    }

    /**
     * Stamp last_login_at on every successful sign-in.
     *
     * Without it there is no way to tell a dormant account from an active one,
     * which is the first question asked when reviewing who still needs access.
     * Written without touching updated_at so it does not masquerade as a
     * profile edit in the audit trail.
     */
    private function recordLogins(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            $user = $event->user;

            if ($user instanceof User) {
                $firstLogin = $user->last_login_at === null;

                $stamp = ['last_login_at' => now()];

                // Whatever route put the shared temporary password on this
                // account, holding it means the account is not yet its owner's.
                if (! $user->requiresPasswordChange() && app(PasswordService::class)->isOnTemporaryPassword($user)) {
                    $stamp['must_change_password'] = true;
                }

                $user->timestamps = false;
                $user->forceFill($stamp)->save();
                $user->timestamps = true;

                // An invited employee who types the emailed password straight
                // into this form never opens the invitation link, so nothing
                // else would ever close their invitation. Signing in is the
                // acceptance the invitation was asking for.
                if ($firstLogin) {
                    app(EmployeeInvitationService::class)->markAcceptedOnLogin($user);
                }
            }
        });
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('pages::auth.login'));
        Fortify::verifyEmailView(fn () => view('pages::auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('pages::auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));
        Fortify::registerView(fn () => view('pages::auth.register'));
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
