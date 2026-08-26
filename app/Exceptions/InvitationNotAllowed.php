<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when an invitation is refused. The message is written to be shown to
 * HR verbatim, so it must say what is wrong with this employee rather than
 * what went wrong inside the service.
 */
class InvitationNotAllowed extends RuntimeException {}
