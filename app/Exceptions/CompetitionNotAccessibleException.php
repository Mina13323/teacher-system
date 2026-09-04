<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a student attempts to access or join a competition they are not
 * eligible for — for example it is not active (outside its scheduling window),
 * has ended, is unpublished, or the student is not enrolled in the linked
 * exam's course.
 *
 * Rendered as a 403 JSON response by the API exception handler.
 */
class CompetitionNotAccessibleException extends RuntimeException
{
    public function __construct(string $message = 'You do not have access to this competition.')
    {
        parent::__construct($message);
    }
}
