<?php

namespace App\Services;

use App\Exceptions\InvitationNotAllowed;
use App\Mail\EmployeeInvitationMail;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Services\EmployeeImportService as Importer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Issuing a login to somebody HR has finished checking.
 *
 * Import creates employee records; it does not create access. An imported row
 * may be half-finished, may carry a generated stand-in email, may be a
 * duplicate. Inviting is the separate, deliberate step where a human confirms
 * this is a real person with a real work address and lets them in.
 *
 * Credentials are never stored or logged in plaintext. The temporary password
 * exists in memory long enough to be mailed; the token exists in the sent email
 * only, as a hash here.
 */
class EmployeeInvitationService
{
    /**
     * Employee statuses that may be granted a login. Somebody who has resigned,
     * been terminated, absconded or been archived must not be issued one.
     */
    private const INVITABLE_STATUSES = [
        'draft', 'onboarding', 'probation', 'confirmed', 'active', 'on-leave',
    ];

    public function __construct(private readonly PasswordService $passwords) {}

    /**
     * What the Invite button should say for this employee: one of
     * not_invited, invited, accepted, expired or active.
     */
    public function statusFor(Employee $employee): string
    {
        // Somebody who has signed in is already using their account; there is
        // nothing to invite them to.
        if ($employee->user?->last_login_at !== null) {
            return 'active';
        }

        return $this->latestInvitation($employee)?->status() ?? EmployeeInvitation::STATUS_NOT_INVITED;
    }

    /**
     * Reads the latestInvitation relation rather than querying directly, so a
     * list that eager-loads it costs one query instead of one per row.
     */
    public function latestInvitation(Employee $employee): ?EmployeeInvitation
    {
        return $employee->latestInvitation;
    }

    /**
     * Why this employee cannot be invited, or null when they can be.
     *
     * Separate from invite() so the UI can explain a refusal without
     * attempting a send.
     */
    public function ineligibleReason(Employee $employee): ?string
    {
        $user = $employee->user;
        $email = $user?->email;

        if ($email === null || trim($email) === '') {
            return 'Cannot send invitation — employee work email is missing.';
        }

        // The importer invents an address when a row has none, so that a user
        // record can exist at all. It belongs to nobody and must never be
        // mailed credentials.
        if ($employee->has_placeholder_email || Str::endsWith(Str::lower($email), '@'.Importer::PLACEHOLDER_EMAIL_DOMAIN)) {
            return 'Cannot send invitation — the address on file is a generated placeholder, not a work email.';
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'Cannot send invitation — the work email on file is not a valid address.';
        }

        $status = $employee->status?->value ?? '';

        if (! in_array($status, self::INVITABLE_STATUSES, true)) {
            return 'Cannot send invitation — this employee is '.($employee->status?->label() ?? 'not active').'.';
        }

        if ($user->last_login_at !== null) {
            return 'This employee already has an active login. Reset their password instead.';
        }

        return null;
    }

    /**
     * Send (or resend) an invitation.
     *
     * A resend revokes whatever came before it, so there is never more than one
     * live link or password per employee.
     *
     * @throws InvitationNotAllowed
     */
    public function invite(Employee $employee, User $actor): EmployeeInvitation
    {
        $employee->loadMissing('user');

        if ($reason = $this->ineligibleReason($employee)) {
            throw new InvitationNotAllowed($reason);
        }

        $previous = $this->latestInvitation($employee);
        $isResend = $previous !== null;

        if ($isResend) {
            $this->assertNotThrottled($employee);
        }

        $user = $employee->user;

        // Plaintext, held only for the duration of this call. resetPassword
        // stores nothing but the hash, and leaves password_changed_at null to
        // record that the employee has not chosen this password themselves.
        $temporaryPassword = $this->passwords->resetPassword($user, null, $actor);

        $plainToken = Str::random(64);

        $invitation = DB::transaction(function () use ($employee, $user, $actor, $plainToken, $previous, $isResend) {
            // Exactly one live invitation per employee: everything older is
            // revoked before the new one exists.
            EmployeeInvitation::where('employee_id', $employee->id)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            return EmployeeInvitation::create([
                'employee_id' => $employee->id,
                'user_id' => $user->id,
                'invited_by' => $actor->id,
                'sent_to' => $user->email,
                'token_hash' => EmployeeInvitation::hashToken($plainToken),
                'invited_at' => now(),
                'expires_at' => now()->addHours($this->expiryHours()),
                'resend_count' => $isResend ? ($previous->resend_count + 1) : 0,
            ]);
        });

        $this->deliver($user, $temporaryPassword, $plainToken, $invitation);

        // No password and no token: an audit trail that could be replayed would
        // defeat the point of hashing them.
        AuditLog::record(
            $employee,
            $isResend ? 'employee.invite_resent' : 'employee.invited',
            null,
            [
                'invitation_id' => $invitation->id,
                'sent_to' => $invitation->sent_to,
                'invited_by' => $actor->id,
                'expires_at' => $invitation->expires_at->toDateTimeString(),
                'resend_count' => $invitation->resend_count,
            ],
            null,
            $employee->id,
        );

        return $invitation;
    }

    /**
     * Redeem an invitation link.
     *
     * Single use: the first successful acceptance closes it. Anything unusable
     * — unknown, already accepted, revoked or expired — returns null, so a
     * caller cannot tell a wrong token from an expired one and use the
     * difference to probe for valid ones.
     */
    public function accept(string $plainToken): ?EmployeeInvitation
    {
        $invitation = EmployeeInvitation::where('token_hash', EmployeeInvitation::hashToken($plainToken))->first();

        if ($invitation === null) {
            return null;
        }

        if (! $invitation->isRedeemable()) {
            if ($invitation->isExpired()) {
                $this->audit($invitation, 'employee.invite_expired', ['invitation_id' => $invitation->id]);
            }

            return null;
        }

        $invitation->forceFill(['accepted_at' => now()])->save();

        $this->audit($invitation, 'employee.invite_accepted', [
            'invitation_id' => $invitation->id,
            'via' => 'link',
        ]);

        return $invitation;
    }

    /**
     * Mark a live invitation accepted because the employee signed in.
     *
     * An employee who types the emailed password straight into the login form
     * never touches the link, and their invitation would otherwise sit at
     * "Invited" forever and eventually read as expired.
     */
    public function markAcceptedOnLogin(User $user): void
    {
        $invitation = EmployeeInvitation::where('user_id', $user->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->orderByDesc('id')
            ->first();

        if ($invitation === null || $invitation->expires_at->isPast()) {
            return;
        }

        $invitation->forceFill(['accepted_at' => now()])->save();

        $this->audit($invitation, 'employee.invite_accepted', [
            'invitation_id' => $invitation->id,
            'via' => 'login',
        ]);
    }

    public function expiryHours(): int
    {
        return max(1, (int) config('security.invitation_expiry_hours', 48));
    }

    /**
     * Deliver the invitation, honouring the admin toggle in
     * Settings > Notifications & Email. A delivery failure must not roll back
     * an invitation that already exists — HR resends instead.
     */
    private function deliver(User $user, string $temporaryPassword, string $plainToken, EmployeeInvitation $invitation): void
    {
        if ((NotificationSetting::for(EmployeeInvitationMail::class)?->mail_enabled ?? true) === false) {
            return;
        }

        try {
            Mail::to($user->email)->send(new EmployeeInvitationMail(
                user: $user,
                temporaryPassword: $temporaryPassword,
                acceptUrl: route('invite.accept', ['token' => $plainToken]),
                expiresAt: $invitation->expires_at,
            ));
        } catch (TransportExceptionInterface $e) {
            // Only transport failures are handled here, and only by class name:
            // the message can carry the recipient and message headers, and
            // repeating it around a credential mail is how plaintext reaches a
            // log file. Anything else — a broken template, a bad argument — is
            // a bug and must surface rather than leave HR reading "Invitation
            // sent" when nothing was.
            throw new InvitationNotAllowed(
                'The invitation was created but could not be emailed ('.class_basename($e).'). Check the mail settings and resend.'
            );
        }
    }

    /**
     * Audit against the employee where there is one. A cascade could have taken
     * the employee row, and the invitation is still worth recording against.
     *
     * @param  array<string, mixed>  $values
     */
    private function audit(EmployeeInvitation $invitation, string $action, array $values): void
    {
        AuditLog::record(
            $invitation->employee ?? $invitation,
            $action,
            null,
            $values,
            null,
            $invitation->employee_id,
        );
    }

    /** @throws InvitationNotAllowed */
    private function assertNotThrottled(Employee $employee): void
    {
        $key = 'invite-resend:'.$employee->id;
        $max = max(1, (int) config('security.invitation_resend_per_hour', 5));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

            throw new InvitationNotAllowed(
                'Too many invitations sent to this employee. Try again in '.$minutes.' minute'.($minutes === 1 ? '' : 's').'.'
            );
        }

        RateLimiter::hit($key, 3600);
    }
}
