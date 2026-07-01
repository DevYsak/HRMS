<?php

namespace App\Services;

use App\Models\PasswordHistory;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Central place for generating secure credentials and recording password
 * history. Replaces the previous hardcoded "Password@123" default so every
 * account gets a unique, policy-compliant password.
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
     * Set (or generate) a password for a user, persist it, and record history.
     * Returns the plaintext so it can be revealed once to an admin / emailed.
     */
    public function resetPassword(User $user, ?string $plain = null, ?User $changedBy = null): string
    {
        $plain ??= $this->generate();

        $user->forceFill(['password' => Hash::make($plain)])->save();
        $this->recordHistory($user, $user->password, $changedBy);

        return $plain;
    }
}
