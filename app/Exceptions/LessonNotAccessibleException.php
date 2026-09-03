<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a student attempts to record or view progress against a lesson
 * they do not have access to (e.g. not enrolled in the course, or the lesson
 * is not published).
 *
 * Rendered as a 403 JSON response by the API exception handler.
 */
class LessonNotAccessibleException extends RuntimeException
{
    public function __construct(string $message = 'You do not have access to this lesson.')
    {
        parent::__construct($message);
    }
}
