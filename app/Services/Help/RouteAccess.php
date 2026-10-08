<?php

namespace App\Services\Help;

use App\Http\Middleware\EnsureRole;
use App\Models\User;
use App\Services\ModuleFeatureService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Predicts whether a user would get past a named route's own authorization
 * middleware (`can:`, `role:` and `module:`), so help content and the sidebar
 * only link to pages the reader can actually open.
 *
 * This is a display decision, never a security one: the route's middleware and
 * the component's own checks still run when the link is followed. When a guard
 * cannot be evaluated here (a `can:` that needs a bound model) the answer is
 * "no" — a missing link is harmless, a link to a 403 is not.
 */
class RouteAccess
{
    public function allows(User $user, string $routeName): bool
    {
        $route = Route::getRoutes()->getByName($routeName);

        if (! $route) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if (str_starts_with($middleware, 'can:')) {
                $arguments = explode(',', substr($middleware, 4));

                if (count($arguments) > 1 || Gate::forUser($user)->denies($arguments[0])) {
                    return false;
                }
            }

            if (str_starts_with($middleware, 'role:')) {
                $abilities = explode(',', substr($middleware, 5));
                $guard = app(EnsureRole::class);

                if (! collect($abilities)->contains(fn (string $ability): bool => $guard->check($user, $ability))) {
                    return false;
                }
            }

            if (str_starts_with($middleware, 'module:') && ! $this->moduleEnabled(substr($middleware, 7))) {
                return false;
            }
        }

        return true;
    }

    /** Same switches EnsureModuleEnabled enforces; an unknown module is closed. */
    private function moduleEnabled(string $module): bool
    {
        $features = app(ModuleFeatureService::class);

        return match ($module) {
            'payroll' => $features->payrollEnabled(),
            'payslips' => $features->payslipsEnabled(),
            default => false,
        };
    }
}
