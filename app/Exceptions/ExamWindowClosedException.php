<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a student tries to start an attempt at or after the exam's
 * authoritative deadline, i.e. min(starts_at + duration_minutes, ends_at).
 *
 * A late entry never receives a fresh duration: once the global deadline has
 * passed the exam simply cannot be started.
 *
 * Rendered as a 422 JSON response by the API exception handler.
 */
class ExamWindowClosedException extends RuntimeException
{
    public function __construct(string $message = 'The time limit for starting this exam has passed.')
    {
        parent::__construct($message);
    }
}
