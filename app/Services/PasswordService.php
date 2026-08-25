<?php

namespace App\Services;

use App\Models\PasswordHistory;
use App\Models\User;
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
     * @throws ValidationException when the password repeats a recent one
     */
    public function changePassword(User $user, string $plain, ?User $changedBy = null): void
    {
        if ($this->isReused($user, $plain)) {
            $limit = (int) config('security.password_history_limit', 5);

            throw ValidationException::withMessages([
                'password' => __('Please choose a password you have not used in your last :count.', ['count' => $limit]),
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($plain),
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
     */
    public function resetPassword(User $user, ?string $plain = null, ?User $changedBy = null): string
    {
        $plain ??= $this->generate();

        $user->forceFill([
            'password' => Hash::make($plain),
            // Null marks a credential the employee has not chosen themselves;
            // it is a record, not a gate. Nothing forces a change.
            'password_changed_at' => null,
        ])->save();

        $this->recordHistory($user, $user->password, $changedBy);

        return $plain;
    }
}
