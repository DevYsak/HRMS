<?php

namespace App\Services\Notifications;

/**
 * The outcome of one channel's delivery check: whether it may send, and if
 * not, the machine-readable reason — used both to decide and to log why a
 * send was skipped.
 */
final readonly class DeliveryDecision
{
    private function __construct(
        public bool $allowed,
        public ?string $reason,
    ) {}

    public static function allow(): self
    {
        return new self(true, null);
    }

    public static function skip(string $reason): self
    {
        return new self(false, $reason);
    }
}
