<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One login invitation. The plaintext token exists only in the email that was
 * sent; what is kept here is its hash, so a leaked database row cannot be
 * replayed as an invitation link.
 */
#[Fillable(['employee_id', 'user_id', 'invited_by', 'sent_to', 'token_hash', 'invited_at', 'expires_at', 'accepted_at', 'revoked_at', 'resend_count'])]
class EmployeeInvitation extends Model
{
    /** Statuses surfaced to HR, in the order an invitation moves through them. */
    public const STATUS_NOT_INVITED = 'not_invited';

    public const STATUS_INVITED = 'invited';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_EXPIRED = 'expired';

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** Hash a plaintext token the same way it is stored. */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return ! $this->isAccepted() && $this->expires_at->isPast();
    }

    /**
     * Whether this invitation's link can still be redeemed. Accepting is
     * single-use: acceptance, revocation and expiry each close it for good.
     */
    public function isRedeemable(): bool
    {
        return ! $this->isAccepted() && ! $this->isRevoked() && ! $this->expires_at->isPast();
    }

    public function status(): string
    {
        return match (true) {
            $this->isAccepted() => self::STATUS_ACCEPTED,
            $this->isRevoked(), $this->expires_at->isPast() => self::STATUS_EXPIRED,
            default => self::STATUS_INVITED,
        };
    }
}
