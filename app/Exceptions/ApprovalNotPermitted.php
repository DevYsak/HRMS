<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * Thrown when an actor tries to decide a request they are not allowed to:
 * their own request (self-approval), or one for an employee outside their
 * reporting line / HR scope. Extends AuthorizationException so an uncaught
 * instance renders as a 403.
 */
class ApprovalNotPermitted extends AuthorizationException
{
    public static function selfApproval(): self
    {
        return new self('You cannot approve or decide your own request.');
    }

    public static function outOfScope(): self
    {
        return new self('This employee is outside your approval scope.');
    }
}
