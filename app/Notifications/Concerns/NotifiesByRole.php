<?php

namespace App\Notifications\Concerns;

/**
 * Tags a notification instance with the role it is being sent as, so the
 * delivery gate and template resolution can apply per-role settings.
 *
 * The same event (e.g. an excess-break flag) is often sent to more than one
 * recipient — the employee and their manager — as the identical instance
 * reused across two notify() calls. forRole() clones rather than mutates so
 * each recipient's copy carries its own role without the two interfering
 * with each other.
 */
trait NotifiesByRole
{
    protected ?string $role = null;

    public function forRole(string $role): static
    {
        $clone = clone $this;
        $clone->role = $role;

        return $clone;
    }

    public function notificationRole(): ?string
    {
        return $this->role;
    }
}
