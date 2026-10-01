<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Lets a Super Admin "View as" another user to test their experience, then
 * return to their own account. The impersonator id is kept in the session and a
 * banner is shown throughout while active.
 */
class ImpersonationController extends Controller
{
    private const SESSION_KEY = AuditLog::IMPERSONATOR_SESSION_KEY;

    public function start(Request $request, User $user): RedirectResponse
    {
        $current = $request->user();

        // Only a real Super Admin (not one already impersonating) may start.
        abort_unless(
            $current && $current->isSuperAdmin() && ! $request->session()->has(self::SESSION_KEY),
            403,
        );

        if ($user->id === $current->id) {
            return back();
        }

        // Recorded while still signed in as the Super Admin, so the actor is the real person.
        app(AuditService::class)->event('IMPERSONATION_STARTED', AuditService::SECURITY, $user,
            new: ['impersonated_user_id' => $user->id, 'impersonated_user' => $user->name],
            subjectEmployeeId: $user->employee?->id);

        // Fresh session id on every identity switch (session fixation).
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $current->id);
        Auth::login($user);

        return redirect()->route('dashboard');
    }

    public function stop(Request $request): RedirectResponse
    {
        $originalId = $request->session()->pull(self::SESSION_KEY);

        if ($originalId && ($original = User::find($originalId))) {
            $impersonated = $request->user();
            Auth::login($original);
            $request->session()->regenerate();

            if ($impersonated) {
                app(AuditService::class)->event('IMPERSONATION_ENDED', AuditService::SECURITY, $impersonated,
                    new: ['impersonated_user_id' => $impersonated->id],
                    subjectEmployeeId: $impersonated->employee?->id);
            }
        }

        return redirect()->route('dashboard');
    }
}
