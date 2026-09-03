<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a student tries to start or view an exam that has not been
 * published.
 *
 * Rendered as a 422 JSON response by the API exception handler.
 */
class ExamNotPublishedException extends RuntimeException
{
    public function __construct(string $message = 'This exam is not available.')
    {
        parent::__construct($message);
    }
}
