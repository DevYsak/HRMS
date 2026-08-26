<?php

namespace App\Http\Controllers;

use App\Services\EmployeeInvitationService;
use Illuminate\Http\RedirectResponse;

/**
 * Redeeming an invitation link.
 *
 * Deliberately thin. Accepting does not sign anybody in and does not set a
 * password: the employee still types the credentials from their email into the
 * normal login form. All this does is close the invitation and send them to
 * the right place, which keeps one authentication path in the application
 * rather than a second one reachable by anyone holding a token.
 */
class InvitationController extends Controller
{
    public function accept(string $token, EmployeeInvitationService $invitations): RedirectResponse
    {
        $invitation = $invitations->accept($token);

        if ($invitation === null) {
            // One message for unknown, expired, revoked and already-accepted
            // alike. Distinguishing them would tell somebody holding a guessed
            // token whether it ever existed.
            return redirect()->route('login')->with('status', 'This invitation link is no longer valid. It may have expired or already been used — ask your HR team to send a new one.');
        }

        return redirect()->route('login')->with('status', 'Invitation accepted. Sign in with the email and temporary password from your invitation.');
    }
}
