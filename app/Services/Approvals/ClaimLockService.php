<?php

namespace App\Services\Approvals;

use App\Models\AttendanceRegularisation;
use App\Models\LeaveEncashment;
use App\Models\LeaveRequest;
use App\Models\OtRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Claim-lock for multi-HR approval routing (v4 Part 2.3).
 *
 * With two or more in-scope HR admins, the first one to OPEN a pending request
 * claims it; the others see "Being handled by [name]" and cannot act on it.
 * A claim auto-releases after STALE_AFTER_MINUTES of inaction (hourly
 * pulse:release-stale-claims), and is released immediately when the claimer
 * closes the request without deciding or when a decision is recorded.
 */
class ClaimLockService
{
    /** Claims older than this are considered abandoned and auto-release. */
    public const STALE_AFTER_MINUTES = 120;

    /** Request models that carry claimed_by / claimed_at. */
    public const CLAIMABLE = [
        LeaveRequest::class,
        OtRequest::class,
        AttendanceRegularisation::class,
        LeaveEncashment::class,
    ];

    /**
     * Try to claim a pending request for the given user. Returns true when the
     * user now holds the claim (fresh, re-entrant, or taking over a stale one),
     * false when another user actively holds it. Atomic via a conditional
     * UPDATE, so two HRs clicking at the same moment cannot both win.
     */
    public function claim(Model $request, int $userId): bool
    {
        // Re-entrant: the holder can always re-open their own claim.
        if ((int) $request->claimed_by === $userId && ! $this->isStale($request)) {
            $request->forceFill(['claimed_at' => now()])->save();

            return true;
        }

        $staleCutoff = now()->subMinutes(self::STALE_AFTER_MINUTES);

        $won = $request->newQueryWithoutScopes()
            ->whereKey($request->getKey())
            ->where(function ($q) use ($staleCutoff) {
                $q->whereNull('claimed_by')
                    ->orWhere('claimed_at', '<', $staleCutoff);
            })
            ->update(['claimed_by' => $userId, 'claimed_at' => now()]) === 1;

        $request->refresh();

        return $won || (int) $request->claimed_by === $userId;
    }

    /** Release a claim (decision recorded, or the claimer closed without acting). */
    public function release(Model $request): void
    {
        $request->forceFill(['claimed_by' => null, 'claimed_at' => null])->save();
    }

    /** Is this request actively claimed by someone other than the given user? */
    public function heldByOther(Model $request, int $userId): bool
    {
        return $request->claimed_by !== null
            && (int) $request->claimed_by !== $userId
            && ! $this->isStale($request);
    }

    /** A claim left idle past the stale window no longer blocks anyone. */
    public function isStale(Model $request): bool
    {
        return $request->claimed_at !== null
            && $request->claimed_at->lt(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    /**
     * Release every stale claim across all claimable request types.
     * Run hourly by pulse:release-stale-claims.
     *
     * @return int number of claims released
     */
    public function releaseStale(): int
    {
        $cutoff = now()->subMinutes(self::STALE_AFTER_MINUTES);
        $released = 0;

        foreach (self::CLAIMABLE as $model) {
            $released += DB::table((new $model)->getTable())
                ->whereNotNull('claimed_by')
                ->where('claimed_at', '<', $cutoff)
                ->update(['claimed_by' => null, 'claimed_at' => null]);
        }

        return $released;
    }
}
