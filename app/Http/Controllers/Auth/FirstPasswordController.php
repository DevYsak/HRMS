<?php

namespace App\Http\Controllers\Auth;

use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Audit\AuditService;
use App\Services\PasswordService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The "Set your password" page an account on an issued credential is confined
 * to (see EnsurePasswordChanged).
 *
 * A plain controller and form rather than a Livewire component, so it works
 * while the Livewire endpoint is closed to these accounts. It does not ask for
 * the temporary password again: the employee has just signed in with it.
 */
class FirstPasswordController extends Controller
{
    use PasswordValidationRules;

    public function show(Request $request): View|RedirectResponse
    {
        if (! $request->user()->requiresPasswordChange()) {
            return redirect()->route('dashboard');
        }

        $this->refuseWhileImpersonating($request);

        return view('pages::auth.first-password');
    }

    public function update(Request $request, PasswordService $passwords): RedirectResponse
    {
        $user = $request->user();

        if (! $user->requiresPasswordChange()) {
            return redirect()->route('dashboard');
        }

        $this->refuseWhileImpersonating($request);

        $validated = $request->validate([
            'password' => $this->passwordRules(),
        ]);

        $passwords->changePassword($user, $validated['password'], $user);

        // Sessions elsewhere were opened with the issued credential.
        if (config('security.logout_other_devices_on_password_change')) {
            Auth::logoutOtherDevices($validated['password']);
        }

        $request->session()->regenerate();

        // State only. Keys avoid the word "password", which the audit
        // sanitiser would redact.
        app(AuditService::class)->event(
            'EMPLOYEE_FIRST_PASSWORD_CHANGED',
            AuditService::SECURITY,
            $user,
            old: ['reset_required' => true],
            new: ['reset_required' => false, 'changed_at' => $user->password_changed_at?->toDateTimeString()],
            subjectEmployeeId: $user->employee?->id,
            module: AuditService::AUTHENTICATION,
        );

        return redirect()->route('dashboard')->with('status', __('Your password has been set.'));
    }

    /**
     * Choosing an employee's password is the employee's act alone.
     */
    private function refuseWhileImpersonating(Request $request): void
    {
        abort_if($request->session()->has(AuditLog::IMPERSONATOR_SESSION_KEY), 403);
    }
}
