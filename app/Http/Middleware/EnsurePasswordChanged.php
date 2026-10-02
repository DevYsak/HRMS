<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confines an account on an issued credential to the "Set your password" page.
 *
 * Runs on every web request rather than on chosen route groups, so a module
 * added later is covered without anybody remembering to opt it in. Livewire's
 * update endpoint is a web route too, which means a page left open from before
 * a forced reset cannot keep acting through its components either.
 */
class EnsurePasswordChanged
{
    /**
     * The only named routes a reset-required account may reach.
     *
     * @var array<int, string>
     */
    private const ALLOWED_ROUTES = [
        'password.first-change',
        'password.first-change.update',
        'logout',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->requiresPasswordChange()) {
            return $next($request);
        }

        // A Super Admin viewing as this employee is not its owner and must not
        // be the one to choose its password; the first-change page refuses
        // them too. Everything they see is already within their own reach.
        if ($request->hasSession() && $request->session()->has(AuditLog::IMPERSONATOR_SESSION_KEY)) {
            return $next($request);
        }

        if ($request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
            return response()->json([
                'message' => __('You must set your password before continuing.'),
                'redirect' => route('password.first-change'),
            ], Response::HTTP_FORBIDDEN);
        }

        return redirect()->route('password.first-change');
    }
}
