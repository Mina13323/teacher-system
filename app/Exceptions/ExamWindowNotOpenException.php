<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a student tries to start an attempt before the exam's official
 * window opens (server time < exam.starts_at).
 *
 * Rendered as a 422 JSON response by the API exception handler.
 */
class ExamWindowNotOpenException extends RuntimeException
{
    public function __construct(string $message = 'This exam has not started yet.')
    {
        parent::__construct($message);
    }
}
