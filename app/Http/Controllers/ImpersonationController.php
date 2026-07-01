<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
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
    private const SESSION_KEY = 'impersonator_id';

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

        $request->session()->put(self::SESSION_KEY, $current->id);
        Auth::login($user);

        AuditLog::record($user, 'impersonated', null, ['by_user_id' => $current->id]);

        return redirect()->route('dashboard');
    }

    public function stop(Request $request): RedirectResponse
    {
        $originalId = $request->session()->pull(self::SESSION_KEY);

        if ($originalId && ($original = User::find($originalId))) {
            Auth::login($original);
        }

        return redirect()->route('dashboard');
    }
}
