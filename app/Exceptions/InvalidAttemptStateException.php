<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an operation is attempted against an attempt in a state that
 * does not permit it (e.g. answering a submitted/expired attempt, or
 * re-submitting with a different payload).
 *
 * Rendered as a 422 JSON response by the API exception handler.
 */
class InvalidAttemptStateException extends RuntimeException
{
    public function __construct(string $message = 'This attempt is not in a state that permits that action.')
    {
        parent::__construct($message);
    }
}
