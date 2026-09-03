<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a student tries to enroll in a course that is not published.
 *
 * Rendered as a 422 JSON response by the API exception handler.
 */
class CourseNotPublishedException extends RuntimeException
{
    public function __construct(string $message = 'This course is not available for enrollment.')
    {
        parent::__construct($message);
    }
}
