<?php

use App\Http\Middleware\CheckActiveEmployee;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\RequirePasswordChange;
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
        $middleware->web(append: [
            SetTeamUrlDefaults::class,
            CheckActiveEmployee::class,
            // Runs after CheckActiveEmployee so a departed employee is signed
            // out rather than sent to change a password they no longer need.
            RequirePasswordChange::class,
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
            'biometric.api' => VerifyBiometricApiKey::class,
        ]);

        // eSSL ADMS device push endpoints — device posts directly, no CSRF token
        $middleware->validateCsrfTokens(except: [
            'iclock/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
