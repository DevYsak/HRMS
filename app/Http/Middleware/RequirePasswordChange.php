<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds an account on the security page until it chooses its own password.
 *
 * Credentials issued by HR (creation, reset, biometric onboarding) are
 * temporary by definition — they have been typed into a modal, sent through
 * email, and sometimes read aloud. Until the employee replaces one, it is a
 * shared secret, so the account is allowed to do exactly one thing.
 *
 * The escape hatches matter as much as the block: logout, the security page
 * itself, and Livewire's own update endpoint all stay open, or the user would
 * be redirected in a loop with no way to comply and no way to leave.
 */
class RequirePasswordChange
{
    /**
     * Routes reachable while a password change is outstanding.
     *
     * The security page sits behind Fortify's password-confirmation step, so
     * its whole confirm flow has to be open too — otherwise the redirect to
     * confirm would itself be blocked and bounce back, which is the loop this
     * class exists to avoid.
     */
    private const ALLOWED_ROUTES = [
        'security.edit',
        'logout',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'profile.edit',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user || ! $user->must_change_password) {
            return $next($request);
        }

        if ($this->isAllowed($request)) {
            return $next($request);
        }

        return redirect()->route('security.edit')
            ->with('status', 'Please choose your own password before continuing.');
    }

    /**
     * Livewire posts every component update to one endpoint, so blocking it
     * would break the very form the user is being sent to.
     */
    private function isAllowed(Request $request): bool
    {
        if ($request->is('livewire/*')) {
            return true;
        }

        $name = $request->route()?->getName();

        return $name !== null && in_array($name, self::ALLOWED_ROUTES, true);
    }
}
