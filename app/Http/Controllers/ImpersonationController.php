<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "Login as" another user to support or test their experience, then return
 * to your own account. Needs the Login as Employee permission (HR Admin by
 * default); see User::canImpersonate() for who may be viewed. The
 * impersonator id is kept in the session and a banner is shown throughout.
 */
class ImpersonationController extends Controller
{
    private const SESSION_KEY = AuditLog::IMPERSONATOR_SESSION_KEY;

    public function start(Request $request, User $user): RedirectResponse
    {
        $current = $request->user();

        // Only a real permission holder (not one already impersonating) may start.
        abort_unless(
            $current && $current->hasPermission('impersonate') && ! $request->session()->has(self::SESSION_KEY),
            403,
        );

        if ($user->id === $current->id) {
            return back();
        }

        abort_unless($current->canImpersonate($user), 403);

        // Recorded while still signed in as the real actor.
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
