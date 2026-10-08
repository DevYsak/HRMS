<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\CheckActiveEmployee;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SetTeamUrlDefaults;
use App\Http\Middleware\VerifyBiometricApiKey;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // First, so every log line and error page of the request carries it.
        $middleware->prepend(AssignRequestId::class);

        $middleware->web(append: [
            SetTeamUrlDefaults::class,
            CheckActiveEmployee::class,
            EnsurePasswordChanged::class,
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
            'biometric.api' => VerifyBiometricApiKey::class,
            'module' => EnsureModuleEnabled::class,
        ]);

        // eSSL ADMS device push endpoints — device posts directly, no CSRF token
        $middleware->validateCsrfTokens(except: [
            'iclock/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Error pages are resources/views/errors/*: friendly text and the
        // request id, never the exception. With APP_DEBUG off (production)
        // Laravel never renders a stack trace; the full trace goes to the log,
        // tagged with request_id via Context.
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'aadhar_number', 'pan_number', 'account_number']);
    })->create();
