<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a student attempts to enroll in a course they are already in.
 *
 * Rendered as a 409 JSON response by the API exception handler.
 */
class DuplicateEnrollmentException extends RuntimeException
{
    public function __construct(string $message = 'You are already enrolled in this course.')
    {
        parent::__construct($message);
    }
}
