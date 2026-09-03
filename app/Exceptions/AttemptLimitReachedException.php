<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a student has reached the maximum number of attempts permitted
 * for an exam.
 *
 * Rendered as a 422 JSON response by the API exception handler.
 */
class AttemptLimitReachedException extends RuntimeException
{
    public function __construct(string $message = 'You have reached the maximum number of attempts for this exam.')
    {
        parent::__construct($message);
    }
}
