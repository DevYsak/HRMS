<?php

namespace App\Services;

use App\Models\PasswordHistory;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Central place for generating secure credentials and recording password
 * history. Replaces the previous hardcoded "Password@123" default so every
 * account gets a unique, policy-compliant password.
 *
 * Every write to users.password should come through here. It was possible to
 * change a password four different ways — the settings page, Fortify's action,
 * HR's reset, the biometric sync — and only one of them recorded history, so
 * the history table filled up with a fraction of the truth and no policy could
 * be built on it.
 */
class PasswordService
{
    /**
     * Generate a strong random password (letters + numbers + symbols) that
     * satisfies the production password policy.
     */
    public function generate(int $length = 14): string
    {
        return Str::password($length, letters: true, numbers: true, symbols: true, spaces: false);
    }

    /**
     * The shared bootstrap password new employee accounts start with.
     *
     * It is a credential, so it lives in config rather than in each caller —
     * and it only ever opens the first-login password page.
     */
    public function temporaryPassword(): string
    {
        return (string) config('security.temporary_password');
    }

    /**
     * Whether this user's stored hash is still the shared temporary password.
     */
    public function isOnTemporaryPassword(User $user): bool
    {
        $temporary = $this->temporaryPassword();

        return $temporary !== '' && $user->password !== null && Hash::check($temporary, $user->password);
    }

    /**
     * Append a hashed-password entry to the user's history.
     */
    public function recordHistory(User $user, string $hashedPassword, ?User $changedBy = null): void
    {
        PasswordHistory::create([
            'user_id' => $user->id,
            'password' => $hashedPassword,
            'changed_by' => $changedBy?->id,
            'created_at' => now(),
        ]);
    }

    /**
     * Whether a plaintext password matches one this user has used recently.
     *
     * Compares against the current password as well as the stored history —
     * history alone would happily let someone "change" to what they already
     * have if their current password predates the history table.
     */
    public function isReused(User $user, string $plain): bool
    {
        $limit = (int) config('security.password_history_limit', 5);

        if ($limit < 1) {
            return false;
        }

        if ($user->password && Hash::check($plain, $user->password)) {
            return true;
        }

        return PasswordHistory::where('user_id', $user->id)
            ->latest('created_at')
            ->limit($limit)
            ->pluck('password')
            ->contains(fn (string $hash) => Hash::check($plain, $hash));
    }

    /**
     * A user choosing their own password.
     *
     * Enforces the reuse policy, clears the temporary-password flag, stamps
     * when it changed and records history — the four things that were
     * previously each done by some callers and not others.
     *
     * @throws ValidationException when the password repeats a recent one or is the shared temporary password
     */
    public function changePassword(User $user, string $plain, ?User $changedBy = null): void
    {
        // Everyone was issued it, so choosing it would leave the account as
        // open as it was before the change.
        $temporary = $this->temporaryPassword();

        if ($temporary !== '' && hash_equals($temporary, $plain)) {
            throw ValidationException::withMessages([
                'password' => __('Your new password cannot be the temporary password.'),
            ]);
        }

        if ($this->isReused($user, $plain)) {
            $limit = (int) config('security.password_history_limit', 5);

            throw ValidationException::withMessages([
                'password' => __('Please choose a password you have not used in your last :count.', ['count' => $limit]),
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($plain),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        $this->recordHistory($user, $user->password, $changedBy);
    }

    /**
     * Set (or generate) a password for a user, persist it, and record history.
     * Returns the plaintext so it can be revealed once to an admin / emailed.
     *
     * Deliberately skips the reuse check: an admin resetting a locked-out
     * account must always succeed.
     *
     * Whoever reset it knows the result, so the account goes back behind the
     * first-login password page until its owner chooses their own.
     */
    public function resetPassword(User $user, ?string $plain = null, ?User $changedBy = null): string
    {
        $plain ??= $this->generate();

        $user->forceFill([
            'password' => Hash::make($plain),
            'must_change_password' => true,
            'password_changed_at' => null,
        ])->save();

        $this->recordHistory($user, $user->password, $changedBy);

        return $plain;
    }

    /**
     * Send an account back through the first-login password page without
     * touching the password itself.
     *
     * The owner signs in with what they already have and must then choose a
     * new one. Nothing about the existing password is read or revealed.
     */
    public function forceReset(User $user, User $actor): void
    {
        $user->forceFill(['must_change_password' => true])->save();

        // Keys avoid the word "password": the audit sanitiser redacts any key
        // containing it, which would hide the very state this records.
        app(AuditService::class)->event(
            'EMPLOYEE_PASSWORD_RESET_FORCED',
            AuditService::SECURITY,
            $user,
            new: ['reset_required' => true, 'forced_by' => $actor->id],
            subjectEmployeeId: $user->employee?->id,
            module: AuditService::EMPLOYEE,
        );
    }
}
