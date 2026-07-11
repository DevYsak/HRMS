<?php

namespace App\Livewire\Concerns;

use App\Services\Approvals\ClaimLockService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Multi-HR claim-lock helpers for approval Livewire components (v4 Part 2.3).
 *
 * Call claimForReview() when opening a pending request for review — it returns
 * false (and toasts who's on it) when another reviewer actively holds the
 * claim. Call releaseClaim() after a decision or when closing without acting.
 */
trait HandlesClaimLock
{
    /**
     * Attempt to claim a request for review. Returns true when the current user
     * now holds it (or it's already decided and needs no claim), false when
     * another reviewer is actively handling it.
     */
    protected function claimForReview(?Model $request): bool
    {
        if (! $request || $request->status !== 'pending') {
            return true;
        }

        if (app(ClaimLockService::class)->claim($request, Auth::id())) {
            return true;
        }

        \Flux::toast('Being handled by '.($request->claimer?->name ?? 'another reviewer').' — no action needed.', variant: 'warning');

        return false;
    }

    /** Release the claim (decision recorded, or the reviewer backed out). */
    protected function releaseClaim(?Model $request): void
    {
        if ($request) {
            app(ClaimLockService::class)->release($request);
        }
    }

    /** Is this request actively claimed by someone other than the current user? */
    protected function claimHeldByOther(?Model $request): bool
    {
        return $request !== null && app(ClaimLockService::class)->heldByOther($request, Auth::id());
    }
}
